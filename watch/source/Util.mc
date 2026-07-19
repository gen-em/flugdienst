// Einsatzdoku — gemeinsame Helfer
// HINWEIS: Erster Wurf, noch nicht gegen das SDK kompiliert.
using Toybox.Time;
using Toybox.Time.Gregorian;
using Toybox.Lang;
using Toybox.Attention;
using Toybox.System;

module Util {

    // Aktueller Zeitpunkt als ISO 8601 UTC ("2026-07-16T08:31:05Z")
    function isoNow() as Lang.String {
        return isoFromMoment(Time.now());
    }

    function isoFromMoment(moment as Time.Moment) as Lang.String {
        var g = Gregorian.utcInfo(moment, Time.FORMAT_SHORT);
        return Lang.format("$1$-$2$-$3$T$4$:$5$:$6$Z", [
            g.year.format("%04d"), g.month.format("%02d"), g.day.format("%02d"),
            g.hour.format("%02d"), g.min.format("%02d"), g.sec.format("%02d")]);
    }

    // Betriebstag = lokales Datum des Dienstbeginns ("YYYY-MM-DD")
    function localDay() as Lang.String {
        var g = Gregorian.info(Time.now(), Time.FORMAT_SHORT);
        return Lang.format("$1$-$2$-$3$", [
            g.year.format("%04d"), g.month.format("%02d"), g.day.format("%02d")]);
    }

    function epochNow() as Lang.Number {
        return Time.now().value();
    }

    // Lokale Uhrzeit "HH:MM" fuer die Anzeige auf der Uhr
    function localHHMM() as Lang.String {
        var g = Gregorian.info(Time.now(), Time.FORMAT_SHORT);
        return g.hour.format("%02d") + ":" + g.min.format("%02d");
    }

    // Zwei kurze Vibrationen (Rea-Beginn)
    function vibrateTwice() as Void {
        _vibe([
            new Attention.VibeProfile(75, 300),
            new Attention.VibeProfile(0, 200),
            new Attention.VibeProfile(75, 300)
        ]);
    }

    // Absturzsicherer Vibrationsaufruf (Hardware-Limit: max. 8 Profile!)
    function _vibe(p as Lang.Array) as Void {
        if (Attention has :vibrate) {
            try { Attention.vibrate(p); } catch (ex) { }
        }
    }

    // Zyklusende Teil 1: drei kraeftige Pulse (5 Profile — unter dem Limit).
    // Cpr.tick() haengt einen Tick spaeter zwei weitere an (insgesamt 5).
    function vibrateCycleEnd() as Void {
        _vibe([
            new Attention.VibeProfile(90, 300), new Attention.VibeProfile(0, 200),
            new Attention.VibeProfile(90, 300), new Attention.VibeProfile(0, 200),
            new Attention.VibeProfile(90, 300)
        ]);
    }

    // Kraeftiger Bestaetigungs-Puls (Phase/Ereignis dokumentiert)
    function vibrateShort() as Void {
        _vibe([new Attention.VibeProfile(80, 200)]);
    }
}
