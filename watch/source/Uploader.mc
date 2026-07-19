// Einsatzdoku — Upload gemaess JSON-Vertrag v1.0.
// Sendet Einsaetze und Ruhe-Segmente inkrementell (max. 500 Punkte/Request),
// merkt sich pro Track die bestaetigte next_seq und raeumt nach final+komplett auf.
using Toybox.Communications;
using Toybox.Application.Properties;
using Toybox.Application.Storage;
using Toybox.Lang;

// Traeger fuer den Web-Antwort-Rueckruf (method() gibt es nur auf Objekten).
// Die Signatur muss exakt der von makeWebRequest erwarteten entsprechen.
class UploaderCb {
    function initialize() {}
    function onResponse(code as Lang.Number,
                        data as Null or Lang.Dictionary or Lang.String
                                or Toybox.PersistedContent.Iterator) as Void {
        Uploader.onResponse(code, data);
    }
}

module Uploader {

    var _busy as Lang.Boolean = false;
    var lastError as Lang.String or Null = null;
    var _cb as UploaderCb or Null = null;
    var _inflight as Lang.Dictionary or Null = null;  // Kontext der laufenden Anfrage

    // Von ueberall aufrufbar: arbeitet die Warteschlange sequenziell ab.
    // Alles beim Server bestaetigt? (Nach Dienstende liegt alles Unbestaetigte
    // in den Pending-Listen; vollstaendig bestaetigte Eintraege werden entfernt.)
    function allSynced() as Lang.Boolean {
        return Model.pendingMissions.size() == 0 && Model.pendingRest.size() == 0;
    }

    function syncAll() as Void {
        if (_busy) { return; }
        _next();
    }

    function _next() as Void {
        // 1) abgeschlossene Einsaetze, 2) aktives + abgeschlossene Ruhe-Segmente,
        // 3) aktiver Einsatz (Teil-Upload) — jeweils erster mit offenen Punkten
        var job = _findJob();
        if (job == null) { _busy = false; return; }
        _busy = true;
        _send(job);
    }

    function _findJob() as Lang.Dictionary or Null {
        for (var i = 0; i < Model.pendingMissions.size(); i++) {
            var m = Model.pendingMissions[i];
            if (_openPoints(m["ref"]) > 0 || !_isAcked(m["ref"])) {
                return { "kind" => "mission", "data" => m, "pendingIdx" => i };
            }
        }
        for (var i = 0; i < Model.pendingRest.size(); i++) {
            var r = Model.pendingRest[i];
            if (_openPoints(r["ref"]) > 0 || !_isAcked(r["ref"])) {
                return { "kind" => "rest_segment", "data" => r, "pendingIdx" => i };
            }
        }
        if (Model.restSegment != null && _openPoints(Model.restSegment["ref"]) > 0) {
            return { "kind" => "rest_segment", "data" => Model.restSegment, "pendingIdx" => -1 };
        }
        if (Model.mission != null && _openPoints(Model.mission["ref"]) > 0) {
            return { "kind" => "mission", "data" => Model.mission, "pendingIdx" => -1 };
        }
        return null;
    }

    function _send(job as Lang.Dictionary) as Void {
        var url = _serverUrl();
        if (url.length() == 0) {                 // Einstellungen noch leer
            lastError = "Keine Server-URL";
            _busy = false;
            return;
        }
        var d = job["data"] as Lang.Dictionary;
        var ref = d["ref"] as Lang.String;
        var seqFrom = _ackedSeq(ref);
        var n = _openPoints(ref);
        if (n > Const.UPLOAD_CHUNK_POINTS) { n = Const.UPLOAD_CHUNK_POINTS; }

        var flat = Track.readPoints(ref, seqFrom, n);
        var points = [];
        for (var i = 0; i < flat.size(); i += 4) {
            points.add([flat[i], flat[i + 1], flat[i + 2], flat[i + 3]]);
        }

        var body = {
            "kind" => job["kind"], "client_ref" => ref, "day" => Model.day,
            "started_at" => d["startedAt"], "ended_at" => d["endedAt"],
            "final" => d["final"] == true,
            "track" => { "seq_from" => seqFrom, "points" => points }
        };

        if ("mission".equals(job["kind"])) {
            body["distance_m"] = (d["dist"] != null) ? d["dist"] : Track.distanceM.toNumber();
            body["ascent_m"]   = (d["asc"]  != null) ? d["asc"]  : Track.ascentM.toNumber();
            var phases = [];
            var raw = d["phases"] as Lang.Array;
            for (var i = 0; i < raw.size(); i++) {
                var p = raw[i];
                phases.add({ "phase" => p[0], "at" => p[1], "lat" => p[2], "lon" => p[3] });
            }
            body["phases"] = phases;
            var sessions = d["resus"] as Lang.Array;
            if (sessions != null && sessions.size() > 0) {
                var out = [];
                for (var s = 0; s < sessions.size(); s++) {
                    var sess = sessions[s] as Lang.Dictionary;
                    var evs = [];
                    var rraw = sess["events"] as Lang.Array;
                    for (var i = 0; i < rraw.size(); i++) {
                        evs.add({ "type" => rraw[i][0], "at" => rraw[i][1] });
                    }
                    out.add({ "started_at" => sess["start"], "events" => evs });
                }
                body["resus_sessions"] = out;
            }
        }

        var opts = {
            :method => Communications.HTTP_REQUEST_METHOD_POST,
            :headers => {
                "Content-Type" => Communications.REQUEST_CONTENT_TYPE_JSON,
                "X-Device-Id" => Properties.getValue("deviceId"),
                "X-Api-Key"   => Properties.getValue("apiKey")
            },
            :responseType => Communications.HTTP_RESPONSE_CONTENT_TYPE_JSON
        };

        // Kontext der laufenden Anfrage merken (Uploads laufen sequenziell,
        // daher genuegt eine einzelne Variable).
        _inflight = { "ref" => ref, "kind" => job["kind"],
                      "pendingIdx" => job["pendingIdx"],
                      "final" => d["final"] == true };

        if (_cb == null) { _cb = new UploaderCb(); }
        Communications.makeWebRequest(url, body, opts, _cb.method(:onResponse));
    }

    // Toleranz bei der Server-URL: "luftrettung.net" genuegt in den
    // Einstellungen — Schema und /ingest.php werden ergaenzt.
    function _serverUrl() as Lang.String {
        var u = Properties.getValue("serverUrl");
        if (!(u instanceof Lang.String) || u.length() == 0) { return ""; }
        if (u.find("http") != 0) { u = "https://" + u; }
        var len = u.length();
        var endsPhp = (len >= 4) && (u.substring(len - 4, len) as Lang.String).equals(".php");
        if (!endsPhp) {
            if (!(u.substring(len - 1, len) as Lang.String).equals("/")) { u = u + "/"; }
            u = u + "ingest.php";
        }
        return u;
    }

    function onResponse(code as Lang.Number,
                        data as Null or Lang.Dictionary or Lang.String
                                or Toybox.PersistedContent.Iterator) as Void {
        var ctx = _inflight;
        if (ctx == null) { _busy = false; return; }
        var ref = ctx["ref"] as Lang.String;
        if (code == 200 && data instanceof Lang.Dictionary && data["ok"] == true) {
            lastError = null;
            var nextSeq = data["next_seq"] as Lang.Number;
            _setAcked(ref, nextSeq, true);

            // final + alle Punkte bestaetigt -> lokal aufraeumen
            if (ctx["final"] == true && nextSeq >= Track.pointCount(ref)) {
                Track.purge(ref);
                Storage.deleteValue("ack_" + ref);    // Marken mit entsorgen
                Storage.deleteValue("meta_" + ref);
                var idx = ctx["pendingIdx"] as Lang.Number;
                if (idx >= 0) {
                    if ("mission".equals(ctx["kind"])) { Model.pendingMissions.remove(Model.pendingMissions[idx]); }
                    else { Model.pendingRest.remove(Model.pendingRest[idx]); }
                    Model.save();
                }
            }
            _next();   // weitere offene Chunks/Jobs
        } else {
            lastError = "Upload " + code.toString();
            _busy = false;   // spaeter erneut (naechster syncAll-Ausloeser)
        }
    }

    // ---- Upload-Marken pro Track --------------------------------------------

    function _ackedSeq(ref as Lang.String) as Lang.Number {
        var v = Storage.getValue("ack_" + ref);
        return v != null ? v : 0;
    }
    function _isAcked(ref as Lang.String) as Lang.Boolean {
        return Storage.getValue("meta_" + ref) == true;   // Metadaten mind. 1x bestaetigt
    }
    function _setAcked(ref as Lang.String, seq as Lang.Number, meta as Lang.Boolean) as Void {
        Storage.setValue("ack_" + ref, seq);
        if (meta) { Storage.setValue("meta_" + ref, true); }
    }
    function _openPoints(ref as Lang.String) as Lang.Number {
        var open = Track.pointCount(ref) - _ackedSeq(ref);
        return open > 0 ? open : 0;
    }
}
