// Einsatzdoku — Startbildschirm "Dienst beginnen" (Anforderungen 1.1)
using Toybox.WatchUi;
using Toybox.Graphics;
using Toybox.System;
using Toybox.Lang;
using Toybox.Timer;

class StartView extends WatchUi.View {

    var _timer as Timer.Timer or Null = null;

    function initialize() { View.initialize(); }

    // Nach "Trotzdem beenden? -> Nein": im Hintergrund weiter senden und den
    // Status live anzeigen, bis alles bestaetigt ist.
    function onShow() as Void {
        if (_timer == null) { _timer = new Timer.Timer(); }
        _timer.start(method(:refresh), 2000, true);
    }

    function onHide() as Void {
        if (_timer != null) { _timer.stop(); }
    }

    function refresh() as Void {
        if (!Uploader.allSynced()) { Uploader.syncAll(); }
        WatchUi.requestUpdate();
    }

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

        // Kopplung: Hinweis solange keine Zugangsdaten da sind, sonst Status
        if (Pair.status != null) {
            var ok = Pair.status.substring(0, 3).equals("Gek");
            dc.setColor(ok ? Graphics.COLOR_GREEN : Graphics.COLOR_YELLOW,
                Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, dc.getHeight() - 52, Graphics.FONT_XTINY,
                Pair.status, Graphics.TEXT_JUSTIFY_CENTER);
        } else if (!Uploader.hasCredentials()) {
            dc.setColor(Graphics.COLOR_YELLOW, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, dc.getHeight() - 52, Graphics.FONT_XTINY,
                "UP halten: Gerät koppeln", Graphics.TEXT_JUSTIFY_CENTER);
        }

    }
}

class StartDelegate extends WatchUi.BehaviorDelegate {

    function initialize() { BehaviorDelegate.initialize(); }

    // lang UP: Geraet koppeln (Code-Eingabe)
    function onMenu() as Lang.Boolean {
        Pair.openInput();
        return true;
    }

    // DOWN: Sync-Status & App-Version anzeigen
    function onNextPage() as Lang.Boolean {
        WatchUi.pushView(new SyncView(true), new SyncDelegate(true), WatchUi.SLIDE_UP);
        return true;
    }

    function onSelect() as Lang.Boolean {          // START
        Model.beginService();
        Util.vibrateTwice();                       // fuehlbar: Aufzeichnung laeuft
        Nav.goTo(:clock);
        return true;
    }

    function onBack() as Lang.Boolean {
        System.exit();
        return true;
    }
}
