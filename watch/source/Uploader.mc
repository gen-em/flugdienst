// Einsatzdoku — Upload gemaess JSON-Vertrag v1.0.
// Sendet Einsaetze und Ruhe-Segmente inkrementell (max. 500 Punkte/Request),
// merkt sich pro Track die bestaetigte next_seq und raeumt nach final+komplett auf.
using Toybox.Communications;
using Toybox.Application.Properties;
using Toybox.Application.Storage;
using Toybox.Lang;

module Uploader {

    var _busy as Lang.Boolean = false;
    var lastError as Lang.String or Null = null;

    // Von ueberall aufrufbar: arbeitet die Warteschlange sequenziell ab.
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
            body["distance_m"] = Track.distanceM.toNumber();
            body["ascent_m"]   = Track.ascentM.toNumber();
            var phases = [];
            var raw = d["phases"] as Lang.Array;
            for (var i = 0; i < raw.size(); i++) {
                var p = raw[i];
                phases.add({ "phase" => p[0], "at" => p[1], "lat" => p[2], "lon" => p[3] });
            }
            body["phases"] = phases;
            if (d["resusStart"] != null) {
                var evs = [];
                var rraw = d["resusEvents"] as Lang.Array;
                for (var i = 0; i < rraw.size(); i++) {
                    evs.add({ "type" => rraw[i][0], "at" => rraw[i][1] });
                }
                body["resus"] = { "started_at" => d["resusStart"], "events" => evs };
            }
        }

        var opts = {
            :method => Communications.HTTP_REQUEST_METHOD_POST,
            :headers => {
                "Content-Type" => Communications.REQUEST_CONTENT_TYPE_JSON,
                "X-Device-Id" => Properties.getValue("deviceId"),
                "X-Api-Key"   => Properties.getValue("apiKey")
            },
            :responseType => Communications.HTTP_RESPONSE_CONTENT_TYPE_JSON,
            :context => { "ref" => ref, "kind" => job["kind"],
                          "pendingIdx" => job["pendingIdx"],
                          "final" => d["final"] == true }
        };

        Communications.makeWebRequest(
            Properties.getValue("serverUrl"), body, opts, method(:onResponse));
    }

    function onResponse(code as Lang.Number, data, ctx as Lang.Dictionary) as Void {
        var ref = ctx["ref"] as Lang.String;
        if (code == 200 && data instanceof Lang.Dictionary && data["ok"] == true) {
            lastError = null;
            var nextSeq = data["next_seq"] as Lang.Number;
            _setAcked(ref, nextSeq, true);

            // final + alle Punkte bestaetigt -> lokal aufraeumen
            if (ctx["final"] == true && nextSeq >= Track.pointCount(ref)) {
                Track.purge(ref);
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
