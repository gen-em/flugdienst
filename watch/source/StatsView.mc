// Einsatzdoku — Oberflaeche: Statistik des laufenden Einsatztags
// Einsaetze = abgeschlossene Einsaetze des Tages (Alarmierung + Ende)
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

        // abgeschlossene Einsaetze des Tages
        dc.setColor(Graphics.COLOR_ORANGE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy - 12, Graphics.FONT_NUMBER_HOT,
            Model.dayMissions.toString(),
            Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
        dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy + 40, Graphics.FONT_MEDIUM, "Einsätze",
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
