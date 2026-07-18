// Einsatzdoku — Oberflaeche: aktuelle Geschwindigkeit + Einsatzdistanz
using Toybox.WatchUi;
using Toybox.Graphics;
using Toybox.Lang;

class SpeedView extends WatchUi.View {

    function initialize() { View.initialize(); }

    function onUpdate(dc as Graphics.Dc) as Void {
        dc.setColor(Graphics.COLOR_BLACK, Graphics.COLOR_BLACK);
        dc.clear();
        var cx = dc.getWidth() / 2;
        var cy = dc.getHeight() / 2;

        // Geschwindigkeit gross (km/h)
        var kmh = Track.speedMs * 3.6;
        dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy - 34, Graphics.FONT_NUMBER_THAI_HOT,
            kmh.format("%d"), Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
        dc.setColor(Graphics.COLOR_LT_GRAY, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy + 14, Graphics.FONT_TINY, "km/h",
            Graphics.TEXT_JUSTIFY_CENTER);

        // Einsatzdistanz (nur bei laufendem Einsatz)
        if (Model.missionActive()) {
            dc.setColor(Graphics.COLOR_ORANGE, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, cy + 52, Graphics.FONT_MEDIUM,
                (Track.distanceM / 1000.0).format("%.1f") + " km",
                Graphics.TEXT_JUSTIFY_CENTER);
        } else {
            dc.setColor(Graphics.COLOR_DK_GRAY, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, cy + 52, Graphics.FONT_TINY, "kein Einsatz",
                Graphics.TEXT_JUSTIFY_CENTER);
        }
    }
}

class SpeedDelegate extends WatchUi.BehaviorDelegate {

    function initialize() { BehaviorDelegate.initialize(); }

    function onNextPage() as Lang.Boolean { Nav.go(1); return true; }
    function onPreviousPage() as Lang.Boolean { Nav.go(-1); return true; }
    function onBack() as Lang.Boolean { Nav.goTo(:clock); return true; }
}
