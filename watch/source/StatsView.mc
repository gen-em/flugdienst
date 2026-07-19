// Einsatzdoku — Oberflaeche: Statistik des laufenden Einsatztags
// Einsaetze = Anzahl Einsaetze des Tages (inkl. laufendem)
// Alarmierungen = Anzahl aller Phase-2-Zeitstempel des Tages
using Toybox.WatchUi;
using Toybox.Graphics;
using Toybox.Lang;

class StatsView extends WatchUi.View {

    function initialize() { View.initialize(); }

    function onUpdate(dc as Graphics.Dc) as Void {
        dc.setColor(Graphics.COLOR_BLACK, Graphics.COLOR_BLACK);
        dc.clear();
        var cx = dc.getWidth() / 2;
        var cy = dc.getHeight() / 2;

        dc.setColor(Graphics.COLOR_LT_GRAY, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, 26, Graphics.FONT_TINY, "Heute",
            Graphics.TEXT_JUSTIFY_CENTER);

        // Einsaetze
        dc.setColor(Graphics.COLOR_ORANGE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy - 42, Graphics.FONT_NUMBER_MEDIUM,
            Model.dayMissions.toString(),
            Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
        dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy - 6, Graphics.FONT_SMALL, "Einsätze",
            Graphics.TEXT_JUSTIFY_CENTER);

        // Alarmierungen
        dc.setColor(Graphics.COLOR_ORANGE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy + 52, Graphics.FONT_NUMBER_MEDIUM,
            Model.dayAlarms.toString(),
            Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
        dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy + 86, Graphics.FONT_SMALL, "Alarmierungen",
            Graphics.TEXT_JUSTIFY_CENTER);

        if (Cpr.active) {
            dc.setColor(Graphics.COLOR_RED, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, dc.getHeight() - 28, Graphics.FONT_XTINY,
                "REA läuft", Graphics.TEXT_JUSTIFY_CENTER);
        }
    }
}

class StatsDelegate extends WatchUi.BehaviorDelegate {
    function initialize() { BehaviorDelegate.initialize(); }
    function onNextPage() as Lang.Boolean { Nav.go(1); return true; }
    function onPreviousPage() as Lang.Boolean { Nav.go(-1); return true; }
    function onBack() as Lang.Boolean { Nav.goTo(:clock); return true; }
}
