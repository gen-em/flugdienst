// Einsatzdoku — app-weiter Zustand + Persistenz.
// Alles Wichtige liegt in einem Dictionary, das nach jeder Aenderung in den
// persistenten Storage geschrieben wird -> uebersteht App-/Uhren-Neustart.
using Toybox.Application.Storage;
using Toybox.Lang;

module Model {

    var serviceActive as Lang.Boolean = false;
    var day as Lang.String or Null = null;        // Betriebstag "YYYY-MM-DD"
    var phase as Lang.Number = 1;

    // Aktiver Einsatz: null oder Dictionary
    // { "ref", "startedAt", "endedAt", "phases" => [[p, iso, lat, lon, hhmm], ...],
    //   "resus" => [ { "start", "startLocal", "events" => [[type, iso, hhmm]] }, ... ],
    //   "dist", "asc" (bei Einsatzende eingefroren), "final" => Boolean }
    var mission as Lang.Dictionary or Null = null;

    // Abgeschlossene, aber noch nicht (fertig) hochgeladene Einsaetze
    var pendingMissions as Lang.Array = [];

    // Aktives Ruhe-Segment: null oder { "ref", "startedAt", "endedAt", "final" }
    var restSegment as Lang.Dictionary or Null = null;
    var pendingRest as Lang.Array = [];

    function load() as Void {
        var s = Storage.getValue(Const.K_STATE);
        if (s instanceof Lang.Dictionary) {
            serviceActive   = s["svc"]  == true;
            day             = s["day"];
            phase           = s["ph"]   != null ? s["ph"] : 1;
            mission         = s["mis"];
            restSegment     = s["rest"];
            pendingMissions = s["pm"] != null ? s["pm"] : [];
            pendingRest     = s["pr"] != null ? s["pr"] : [];
        }
    }

    function save() as Void {
        Storage.setValue(Const.K_STATE, {
            "svc" => serviceActive, "day" => day, "ph" => phase,
            "mis" => mission, "rest" => restSegment,
            "pm" => pendingMissions, "pr" => pendingRest
        });
    }

    // ---- Dienst-Klammer (Anforderungen 1.1) --------------------------------

    function beginService() as Void {
        serviceActive = true;
        day = Util.localDay();
        phase = 1;
        _startRestSegment();
        save();
        Track.startPositioning();
    }

    function endService() as Void {
        _closeRestSegment();
        if (mission != null) { _finishMission(); }   // Sicherheitsnetz
        serviceActive = false;
        save();
        Track.stopPositioning();
        Uploader.syncAll();
    }

    // ---- Phasen (Anforderungen 1.2) ----------------------------------------

    function missionActive() as Lang.Boolean { return mission != null; }

    // kurz START auf Oberflaeche 1: naechste Phase
    function nextPhase() as Void {
        if (phase >= 10 || phase < 1) { phase = 1; }
        setPhase(phase + 1);
    }

    // Direktes Setzen (auch Schnellmenue): erneutes Setzen frueherer Phasen
    // erzeugt schlicht einen weiteren Zeitstempel (keine Korrektur).
    function setPhase(p as Lang.Number) as Void {
        if (p < 2 || p > 10) { return; }
        if (mission == null) { _startMission(); }    // Phase 2..10 ohne Einsatz -> Einsatz beginnt

        var pos = Track.lastLatLon();
        // [phase, isoUTC, lat, lon, lokaleAnzeige]
        (mission["phases"] as Lang.Array).add([p, Util.isoNow(), pos[0], pos[1], Util.localHHMM()]);
        phase = p;
        Util.vibrateShort();

        if (p == 10) { _finishMission(); }
        save();
    }

    function _startMission() as Void {
        _closeRestSegment();
        mission = {
            "ref" => "m-" + Util.epochNow().toString(),
            "startedAt" => Util.isoNow(), "endedAt" => null,
            "phases" => [], "resus" => [],
            "final" => false
        };
        Track.beginMissionTrack(mission["ref"] as Lang.String);
    }

    function _finishMission() as Void {
        Cpr.stopRecording();                 // laufende Rea sauber schliessen
        mission["endedAt"] = Util.isoNow();
        // Kilometer/Anstieg einfrieren: gehoeren zu DIESEM Einsatz, auch wenn
        // der Upload erst spaeter (waehrend eines neuen Einsatzes) gelingt
        mission["dist"] = Track.distanceM.toNumber();
        mission["asc"]  = Track.ascentM.toNumber();
        mission["final"] = true;
        Track.endMissionTrack();
        pendingMissions.add(mission);
        mission = null;
        phase = 1;
        _startRestSegment();
        save();
        Uploader.syncAll();
    }

    // ---- Ruhe-Segmente (Anforderungen 1.3) ---------------------------------

    function _startRestSegment() as Void {
        restSegment = {
            "ref" => "r-" + Util.epochNow().toString(),
            "startedAt" => Util.isoNow(), "endedAt" => null, "final" => false
        };
        Track.beginRestTrack(restSegment["ref"] as Lang.String);
    }

    function _closeRestSegment() as Void {
        if (restSegment == null) { return; }
        restSegment["endedAt"] = Util.isoNow();
        restSegment["final"] = true;
        Track.endRestTrack();
        pendingRest.add(restSegment);
        restSegment = null;
    }

    // ---- Reanimation (Anforderungen 1.4, mehrere pro Einsatz moeglich) -----
    // Jeder Rea-Start legt eine NEUE Sitzung an; "Aufzeichnung beenden"
    // schliesst sie. Zeitstempel liegen beim Einsatz; laeuft ausnahmsweise
    // keiner, wird implizit einer gestartet.

    function resusStart() as Void {
        if (mission == null) { _startMission(); }
        (mission["resus"] as Lang.Array).add({
            "start" => Util.isoNow(), "startLocal" => Util.localHHMM(),
            "events" => []
        });
        save();
    }

    function resusEvent(type as Lang.String) as Void {
        if (mission == null || (mission["resus"] as Lang.Array).size() == 0) {
            resusStart();
        }
        var sessions = mission["resus"] as Lang.Array;
        var cur = sessions[sessions.size() - 1] as Lang.Dictionary;
        // [typ, isoUTC, lokaleAnzeige]
        (cur["events"] as Lang.Array).add([type, Util.isoNow(), Util.localHHMM()]);
        Util.vibrateShort();
        save();
    }

    // Letzte (= aktuelle) Rea-Sitzung, fuer die Uebersicht auf der Uhr
    function currentResus() as Lang.Dictionary or Null {
        if (mission == null) { return null; }
        var sessions = mission["resus"] as Lang.Array;
        return sessions.size() > 0 ? sessions[sessions.size() - 1] : null;
    }
}
