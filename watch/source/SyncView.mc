// Einsatzdoku — Sync-Status & App-Version (eigene Seite)
//
// Waehrend des Dienstes im Seiten-Pager zwischen Statistik und Rea;
// vom Startbildschirm aus per DOWN erreichbar (BACK fuehrt zurueck).
// Die Seite stoesst im Hintergrund weiter Sendeversuche an.
using Toybox.WatchUi;
using Toybox.Graphics;
using Toybox.Lang;
using Toybox.Timer;
using Toybox.Application.Storage;
using Toybox.Position;

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

        // Rueckstand = nur abgeschlossene, unbestaetigte Pakete — das immer
        // offene laufende Ruhesegment zaehlt nicht als Rueckstand.
        var open = Model.backlogCount();
        if (open == 0) {
            dc.setColor(Graphics.COLOR_GREEN, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, cy - 30, Graphics.FONT_LARGE, "Sync vollständig",
                Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
            // Haken selbst zeichnen (die Geraeteschrift kennt das Glyph nicht)
            dc.setPenWidth(5);
            dc.drawLine(cx - 14, cy + 6, cx - 4, cy + 16);
            dc.drawLine(cx - 4, cy + 16, cx + 15, cy - 5);
            dc.setPenWidth(1);
        } else {
            dc.setColor(Graphics.COLOR_ORANGE, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, cy - 44, Graphics.FONT_NUMBER_MILD, open.toString(),
                Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
            dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, cy - 8, Graphics.FONT_SMALL,
                open == 1 ? "Paket offen" : "Pakete offen",
                Graphics.TEXT_JUSTIFY_CENTER);
        }

        // GPS-Guete: spiegelt exakt die Schwelle, ab der Track.mc Punkte
        // speichert (< QUALITY_POOR wird verworfen) — sonst waere die
        // Anzeige irrefuehrend.
        var y = cy + 34;
        var gpsTxt = "GPS aus (kein Dienst)";
        var gpsCol = Graphics.COLOR_DK_GRAY;
        if (Model.serviceActive) {
            var q = Position.QUALITY_NOT_AVAILABLE;
            var pi = Position.getInfo();
            if (pi != null && pi.accuracy != null) { q = pi.accuracy; }
            if (q >= Position.QUALITY_USABLE) {
                gpsTxt = "GPS gut"; gpsCol = Graphics.COLOR_GREEN;
            } else if (q >= Position.QUALITY_POOR) {
                gpsTxt = "GPS ausreichend"; gpsCol = Graphics.COLOR_GREEN;
            } else {
                gpsTxt = "GPS zu schwach"; gpsCol = Graphics.COLOR_RED;
            }
        }
        dc.setColor(gpsCol, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, y, Graphics.FONT_XTINY, gpsTxt, Graphics.TEXT_JUSTIFY_CENTER);

        // Fehlergrund (z. B. "Nicht gekoppelt", "Upload 401")
        if (Uploader.lastError != null) {
            y += 22;
            dc.setColor(Graphics.COLOR_LT_GRAY, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, y, Graphics.FONT_XTINY,
                Uploader.lastError as Lang.String, Graphics.TEXT_JUSTIFY_CENTER);
        }

        // Kopplungs-Rueckmeldung (nach Code-Eingabe)
        if (Pair.status != null) {
            y += 22;
            var ok = Pair.status.substring(0, 3).equals("Gek");
            dc.setColor(ok ? Graphics.COLOR_GREEN : Graphics.COLOR_YELLOW,
                Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, y, Graphics.FONT_XTINY,
                Pair.status, Graphics.TEXT_JUSTIFY_CENTER);
        }

        // eine Zeile: REA-Warnung vor Einrichtungs-Hinweis
        if (Cpr.active) {
            dc.setColor(Graphics.COLOR_RED, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, dc.getHeight() - 52, Graphics.FONT_XTINY,
                "REA läuft", Graphics.TEXT_JUSTIFY_CENTER);
        } else if (!Uploader.hasCredentials()) {
            dc.setColor(Graphics.COLOR_YELLOW, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, dc.getHeight() - 52, Graphics.FONT_XTINY,
                "START halten: Gerät koppeln", Graphics.TEXT_JUSTIFY_CENTER);
        }

        dc.setColor(Graphics.COLOR_DK_GRAY, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, dc.getHeight() - 30, Graphics.FONT_XTINY,
            "Version " + Const.APP_VERSION, Graphics.TEXT_JUSTIFY_CENTER);
    }
}

class SyncDelegate extends WatchUi.BehaviorDelegate {

    var _fromStart as Lang.Boolean;
    var _timer as Timer.Timer or Null = null;
    var _holding as Lang.Boolean = false;
    var _longFired as Lang.Boolean = false;

    function initialize(fromStart as Lang.Boolean) {
        BehaviorDelegate.initialize();
        _fromStart = fromStart;
    }

    // START halten (1 s): Geraete-Kopplung — Code-Eingabe oeffnen.
    // Gleiches Haltemuster wie auf der Hauptanzeige (ClockDelegate).
    function onKeyPressed(evt as WatchUi.KeyEvent) as Lang.Boolean {
        if (evt.getKey() == WatchUi.KEY_ENTER) {
            _holding = true;
            _longFired = false;
            if (_timer == null) { _timer = new Timer.Timer(); }
            _timer.start(method(:onHoldTimeout), Const.LONG_PRESS_MS, false);
            return true;
        }
        return false;
    }

    function onHoldTimeout() as Void {
        if (!_holding) { return; }
        _longFired = true;
        Pair.openInput();
    }

    function onKeyReleased(evt as WatchUi.KeyEvent) as Lang.Boolean {
        if (evt.getKey() != WatchUi.KEY_ENTER) { return false; }
        if (!_holding) { return false; }
        _holding = false;
        if (_timer != null) { _timer.stop(); }
        if (_longFired) { _longFired = false; }
        return true;                               // kurz START: keine Aktion
    }

    function onKey(evt as WatchUi.KeyEvent) as Lang.Boolean {
        return evt.getKey() == WatchUi.KEY_ENTER;  // ENTER selbst verarbeiten
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
