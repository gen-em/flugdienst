// Einsatzdoku — GPS-Aufzeichnung mit Ausduennung und Chunk-Persistenz.
//
// Punktformat im Puffer (flach, speicherschonend):
//   [lat0, lon0, ele0, ts0, lat1, lon1, ele1, ts1, ...]
// Persistenz: Chunks a 200 Punkte unter Schluesseln "<ref>_<n>", damit die
// 8-KB-Grenze pro Storage-Wert eingehalten wird. Meta unter K_TRACK_META.
using Toybox.Position;
using Toybox.Application.Storage;
using Toybox.Lang;
using Toybox.Math;
using Toybox.WatchUi;

module Track {

    const CHUNK_POINTS = 200;

    // aktiver Puffer (Einsatz ODER Ruhe-Segment — nie beides)
    var _ref as Lang.String or Null = null;   // client_ref des aktiven Tracks
    var _isMission as Lang.Boolean = false;
    var _buf as Lang.Array = [];              // flacher Punktpuffer (nur Tail seit letztem Flush)
    var _count as Lang.Number = 0;            // Gesamtpunkte des aktiven Tracks

    var _lastLat = null; var _lastLon = null; var _lastEle = null;
    var _lastTs as Lang.Number = 0;

    var distanceM as Lang.Float = 0.0;        // aktueller Einsatz
    var ascentM as Lang.Float = 0.0;

    // Anzeige-Polylinie (nur aktueller Einsatz), flach [lat,lon,...]
    var display as Lang.Array = [];
    var _displayStride as Lang.Number = 1;    // jeder n-te Punkt kommt in die Anzeige
    var _sinceDisplay as Lang.Number = 0;

    var _lastRestSync as Lang.Number = 0;

    // ---- Lebenszyklus -------------------------------------------------------

    function startPositioning() as Void {
        Position.enableLocationEvents(Position.LOCATION_CONTINUOUS, method(:onPosition));
    }

    function stopPositioning() as Void {
        Position.enableLocationEvents(Position.LOCATION_DISABLE, method(:onPosition));
    }

    function beginMissionTrack(ref as Lang.String) as Void {
        _flush();
        _ref = ref; _isMission = true;
        _buf = []; _count = 0;
        distanceM = 0.0; ascentM = 0.0;
        display = []; _displayStride = 1; _sinceDisplay = 0;
        _saveMeta();
    }

    function endMissionTrack() as Void { _flush(); _ref = null; }

    function beginRestTrack(ref as Lang.String) as Void {
        _flush();
        _ref = ref; _isMission = false;
        _buf = []; _count = 0;
        _saveMeta();
    }

    function endRestTrack() as Void { _flush(); _ref = null; }

    // ---- Positions-Callback -------------------------------------------------

    function onPosition(info as Position.Info) as Void {
        if (_ref == null || info.position == null) { return; }
        if (info.accuracy != null && info.accuracy < Position.QUALITY_POOR) { return; }

        var deg = info.position.toDegrees();
        var lat = deg[0]; var lon = deg[1];
        var ele = info.altitude;
        var now = Util.epochNow();

        // Ausduennung: nie oefter als 1/s; dann >= 15 m ODER >= 10 s
        if (now - _lastTs < Const.THIN_MIN_GAP_S) { return; }
        if (_lastLat != null) {
            var d = _haversine(_lastLat, _lastLon, lat, lon);
            if (d < Const.THIN_MIN_DIST_M && (now - _lastTs) < Const.THIN_MAX_GAP_S) {
                return;
            }
            if (_isMission) {
                distanceM += d;
                if (ele != null && _lastEle != null && ele > _lastEle) {
                    ascentM += (ele - _lastEle);
                }
            }
        }

        _buf.add(lat); _buf.add(lon); _buf.add(ele); _buf.add(now);
        _count += 1;
        _lastLat = lat; _lastLon = lon; _lastEle = ele; _lastTs = now;

        if (_isMission) { _addDisplayPoint(lat, lon); }

        // Flash-Persistenz: gebuendelt, sobald ein Chunk voll ist
        if (_buf.size() >= CHUNK_POINTS * 4) { _flush(); }

        // Periodischer Ruhe-Sync + Nachzuegler (Anforderungen 2)
        if (now - _lastRestSync > Const.REST_SYNC_INTERVAL_S) {
            _lastRestSync = now;
            Uploader.syncAll();
        }
        WatchUi.requestUpdate();
    }

    function lastLatLon() as Lang.Array {
        return [_lastLat, _lastLon];
    }

    // ---- Anzeige-Polylinie (Cap 1000 mit Dichte-Halbierung) -----------------

    function _addDisplayPoint(lat, lon) as Void {
        _sinceDisplay += 1;
        if (_sinceDisplay < _displayStride) { return; }
        _sinceDisplay = 0;
        display.add(lat); display.add(lon);

        if (display.size() >= Const.DISPLAY_MAX_POINTS * 2) {
            // jeden zweiten Punkt entfernen -> gesamter Track bleibt sichtbar
            var halved = [];
            for (var i = 0; i < display.size(); i += 4) {
                halved.add(display[i]); halved.add(display[i + 1]);
            }
            display = halved;
            _displayStride *= 2;
        }
    }

    // ---- Persistenz + Upload-Zugriff ---------------------------------------

    function _flush() as Void {
        if (_ref == null || _buf.size() == 0) { return; }
        var chunkIdx = (_count - (_buf.size() / 4)) / CHUNK_POINTS;
        Storage.setValue(_ref + "_" + chunkIdx.toString(), _buf);
        // Achtung: Chunks sind nur dann sauber ausgerichtet, wenn immer bei
        // vollem Chunk geflusht wird; Rest-Flush (App-Ende) erzeugt Teilchunk,
        // der beim naechsten Punkt ueberschrieben wuerde -> deshalb nach einem
        // Teil-Flush Puffer NICHT leeren, sondern weiterfuehren.
        if (_buf.size() >= CHUNK_POINTS * 4) { _buf = []; }
        _saveMeta();
    }

    function flushForShutdown() as Void { _flush(); }

    function _saveMeta() as Void {
        Storage.setValue(Const.K_TRACK_META, {
            "ref" => _ref, "isMission" => _isMission, "count" => _count,
            "dist" => distanceM, "asc" => ascentM
        });
    }

    function restore() as Void {
        var m = Storage.getValue(Const.K_TRACK_META);
        if (m instanceof Lang.Dictionary && m["ref"] != null) {
            _ref = m["ref"]; _isMission = m["isMission"] == true;
            _count = m["count"] != null ? m["count"] : 0;
            distanceM = m["dist"] != null ? m["dist"] : 0.0;
            ascentM = m["asc"] != null ? m["asc"] : 0.0;
            // Anzeige-Polylinie aus Chunks grob rekonstruieren
            display = []; _displayStride = 1; _sinceDisplay = 0;
            if (_isMission) {
                var pts = readPoints(_ref, 0, _count);
                for (var i = 0; i < pts.size(); i += 4) {
                    _addDisplayPoint(pts[i], pts[i + 1]);
                }
            }
        }
    }

    // Punkte [seqFrom, seqFrom+n) eines Tracks lesen (fuer Upload)
    function readPoints(ref as Lang.String, seqFrom as Lang.Number, n as Lang.Number) as Lang.Array {
        var out = [];
        var seq = seqFrom;
        while (out.size() / 4 < n) {
            var chunk = Storage.getValue(ref + "_" + (seq / CHUNK_POINTS).toString());
            var isTail = false;
            if (chunk == null) {
                // Punkte koennen noch im RAM-Tail liegen
                if (ref.equals(_ref)) { chunk = _buf; isTail = true; } else { break; }
            }
            var offs = (seq % CHUNK_POINTS) * 4;
            if (isTail) {
                var tailStart = _count - (_buf.size() / 4);
                offs = (seq - tailStart) * 4;
            }
            if (offs < 0 || offs >= chunk.size()) { break; }
            while (offs < chunk.size() && out.size() / 4 < n) {
                out.add(chunk[offs]); out.add(chunk[offs + 1]);
                out.add(chunk[offs + 2]); out.add(chunk[offs + 3]);
                offs += 4; seq += 1;
            }
        }
        return out;
    }

    function pointCount(ref as Lang.String) as Lang.Number {
        if (ref.equals(_ref)) { return _count; }
        // abgeschlossene Tracks: Anzahl aus Chunks bestimmen
        var n = 0; var i = 0;
        while (true) {
            var chunk = Storage.getValue(ref + "_" + i.toString());
            if (chunk == null) { break; }
            n += chunk.size() / 4; i += 1;
        }
        return n;
    }

    // Chunks eines fertig hochgeladenen Tracks loeschen
    function purge(ref as Lang.String) as Void {
        var i = 0;
        while (Storage.getValue(ref + "_" + i.toString()) != null) {
            Storage.deleteValue(ref + "_" + i.toString());
            i += 1;
        }
    }

    // ---- Geometrie ----------------------------------------------------------

    function _haversine(lat1, lon1, lat2, lon2) as Lang.Float {
        var r = 6371000.0;
        var p1 = Math.toRadians(lat1); var p2 = Math.toRadians(lat2);
        var dp = Math.toRadians(lat2 - lat1);
        var dl = Math.toRadians(lon2 - lon1);
        var a = Math.sin(dp / 2) * Math.sin(dp / 2)
              + Math.cos(p1) * Math.cos(p2) * Math.sin(dl / 2) * Math.sin(dl / 2);
        return (2.0 * r * Math.asin(Math.sqrt(a))).toFloat();
    }
}
