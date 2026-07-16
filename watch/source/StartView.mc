// Einsatzdoku — Startbildschirm "Dienst beginnen" (Anforderungen 1.1)
using Toybox.WatchUi;
using Toybox.Graphics;
using Toybox.System;
using Toybox.Lang;

class StartView extends WatchUi.View {

    function initialize() { View.initialize(); }

    function onUpdate(dc as Graphics.Dc) as Void {
        dc.setColor(Graphics.COLOR_BLACK, Graphics.COLOR_BLACK);
        dc.clear();
        var cx = dc.getWidth() / 2;
        var cy = dc.getHeight() / 2;

        dc.setColor(Graphics.COLOR_ORANGE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy - 50, Graphics.FONT_MEDIUM, "Einsatzdoku",
            Graphics.TEXT_JUSTIFY_CENTER);
        dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy, Graphics.FONT_SMALL, "Dienst beginnen?",
            Graphics.TEXT_JUSTIFY_CENTER);
        dc.setColor(Graphics.COLOR_LT_GRAY, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy + 40, Graphics.FONT_TINY, "START drücken",
            Graphics.TEXT_JUSTIFY_CENTER);
    }
}

class StartDelegate extends WatchUi.BehaviorDelegate {

    function initialize() { BehaviorDelegate.initialize(); }

    function onSelect() as Lang.Boolean {          // START
        Model.beginService();
        Nav.goTo(:clock);
        return true;
    }

    function onBack() as Lang.Boolean {
        System.exit();
        return true;
    }
}
