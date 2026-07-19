// Einsatzdoku — Sync-Status & App-Version (eigene Seite)
//
// Waehrend des Dienstes im Seiten-Pager zwischen Statistik und Rea;
// vom Startbildschirm aus per DOWN erreichbar (BACK fuehrt zurueck).
// Die Seite stoesst im Hintergrund weiter Sendeversuche an.
using Toybox.WatchUi;
using Toybox.Graphics;
using Toybox.Lang;
using Toybox.Timer;

class SyncView extends WatchUi.View {

    var _fromStart as Lang.Boolean;
    var _timer as Timer.Timer or Null = null;

    function initialize(fromStart as Lang.Boolean) {
        View.initialize();
        _fromStart = fromStart;
    }

    function onShow() as Void {
        if (_timer == null) { _timer = new Timer.Timer(); }
        _timer.start(method(:refresh), 2000, true);
        if (!Uploader.allSynced()) { Uploader.syncAll(); }
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

        dc.setColor(Graphics.COLOR_LT_GRAY, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, 26, Graphics.FONT_TINY, "Sync",
            Graphics.TEXT_JUSTIFY_CENTER);

        var open = Model.pendingMissions.size() + Model.pendingRest.size();
        if (Uploader.allSynced()) {
            dc.setColor(Graphics.COLOR_GREEN, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, cy - 24, Graphics.FONT_LARGE, "Alles gesendet ✓",
                Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
        } else {
            dc.setColor(Graphics.COLOR_ORANGE, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, cy - 44, Graphics.FONT_NUMBER_MILD, open.toString(),
                Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
            dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, cy - 8, Graphics.FONT_SMALL,
                open == 1 ? "Paket offen" : "Pakete offen",
                Graphics.TEXT_JUSTIFY_CENTER);
        }

        // Fehlergrund (z. B. "Nicht gekoppelt", "Upload 401")
        if (Uploader.lastError != null) {
            dc.setColor(Graphics.COLOR_LT_GRAY, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, cy + 30, Graphics.FONT_XTINY,
                Uploader.lastError as Lang.String, Graphics.TEXT_JUSTIFY_CENTER);
        }

        if (Cpr.active) {
            dc.setColor(Graphics.COLOR_RED, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, dc.getHeight() - 52, Graphics.FONT_XTINY,
                "REA läuft", Graphics.TEXT_JUSTIFY_CENTER);
        }

        dc.setColor(Graphics.COLOR_DK_GRAY, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, dc.getHeight() - 30, Graphics.FONT_XTINY,
            "Version " + Const.APP_VERSION, Graphics.TEXT_JUSTIFY_CENTER);
    }
}

class SyncDelegate extends WatchUi.BehaviorDelegate {

    var _fromStart as Lang.Boolean;

    function initialize(fromStart as Lang.Boolean) {
        BehaviorDelegate.initialize();
        _fromStart = fromStart;
    }

    function onNextPage() as Lang.Boolean {
        if (_fromStart) { return true; }           // vom Start: keine Nachbarseiten
        Nav.go(1); return true;
    }

    function onPreviousPage() as Lang.Boolean {
        if (_fromStart) { return true; }
        Nav.go(-1); return true;
    }

    function onBack() as Lang.Boolean {
        if (_fromStart) {
            WatchUi.popView(WatchUi.SLIDE_DOWN);   // zurueck zum Startbildschirm
        } else {
            Nav.goTo(:clock);
        }
        return true;
    }
}
