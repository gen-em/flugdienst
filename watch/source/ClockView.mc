// Einsatzdoku — Oberflaeche 1: Uhrzeit + Phase
//
// kurz START: naechste Phase (2..9). Nach Phase 9 bleibt der Einsatz offen;
// kurz START zeigt dann die Bestaetigung "Einsatz beenden & senden?".
// lang START: farbcodiertes Schnellmenue (Phasen, Einsatzuebersicht gelb,
// Einsatz abschliessen gruen, Einsatztag beenden rot — beide mit Bestaetigung).
using Toybox.WatchUi;
using Toybox.Graphics;
using Toybox.System;
using Toybox.Lang;
using Toybox.Timer;

class ClockView extends WatchUi.View {

    var _timer as Timer.Timer or Null = null;

    function initialize() { View.initialize(); }

    function onShow() as Void {
        if (_timer == null) { _timer = new Timer.Timer(); }
        _timer.start(method(:refresh), 1000, true);   // Uhrzeit im Sekundentakt
    }

    function onHide() as Void {
        if (_timer != null) { _timer.stop(); }
    }

    function refresh() as Void { WatchUi.requestUpdate(); }

    function onUpdate(dc as Graphics.Dc) as Void {
        dc.setColor(Graphics.COLOR_BLACK, Graphics.COLOR_BLACK);
        dc.clear();
        var cx = dc.getWidth() / 2;
        var cy = dc.getHeight() / 2;

        // Uhrzeit gross
        var t = System.getClockTime();
        dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy - 44, Graphics.FONT_NUMBER_THAI_HOT,
            t.hour.format("%02d") + ":" + t.min.format("%02d"),
            Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);

        // Datum klein darunter
        dc.setColor(Graphics.COLOR_LT_GRAY, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy + 4, Graphics.FONT_TINY,
            Util.localDateShort(),
            Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);

        // Phase darunter: Nummer gross, Bezeichnung klein
        dc.setColor(Graphics.COLOR_ORANGE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy + 40, Graphics.FONT_LARGE,
            Model.phase.toString(),
            Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
        dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy + 68, Graphics.FONT_TINY,
            Const.PHASE_LABELS[Model.phase],
            Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);

        // Laufende Reanimation: roter Ring entlang der Luenette — peripher
        // erkennbar, ohne eine Textzeile zu belegen.
        if (Cpr.active) {
            dc.setPenWidth(9);
            dc.setColor(Graphics.COLOR_RED, Graphics.COLOR_TRANSPARENT);
            var rad = (dc.getWidth() < dc.getHeight() ? dc.getWidth() : dc.getHeight()) / 2 - 5;
            dc.drawCircle(cx, cy, rad);
            dc.setPenWidth(1);
        }
    }
}

class ClockDelegate extends WatchUi.BehaviorDelegate {

    var _timer as Timer.Timer or Null = null;
    var _holding as Lang.Boolean = false;
    var _longFired as Lang.Boolean = false;

    function initialize() { BehaviorDelegate.initialize(); }

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

    // Nach 1 s Halten: Schnellmenue oeffnet sofort (nicht erst beim Loslassen)
    function onHoldTimeout() as Void {
        if (!_holding) { return; }
        _longFired = true;
        _pushQuickMenu();
    }

    function onKeyReleased(evt as WatchUi.KeyEvent) as Lang.Boolean {
        if (evt.getKey() != WatchUi.KEY_ENTER) { return false; }
        if (!_holding) { return false; }
        _holding = false;
        if (_timer != null) { _timer.stop(); }
        if (_longFired) { _longFired = false; return true; }   // lang: schon offen
        if (Model.missionActive() && Model.phase >= 9) {
            pushFinishConfirm();               // Haltezustand: Abschluss bestaetigen
        } else {
            Model.nextPhase();
            WatchUi.requestUpdate();
        }
        return true;
    }

    function onKey(evt as WatchUi.KeyEvent) as Lang.Boolean {
        // ENTER selbst verarbeiten (verhindert doppeltes onSelect)
        return evt.getKey() == WatchUi.KEY_ENTER;
    }

    function _pushQuickMenu() as Void {
        var v = new QuickMenuView();
        WatchUi.pushView(v, new QuickMenuDelegate(v), WatchUi.SLIDE_LEFT);
    }

    static function pushFinishConfirm() as Void {
        var dlg = new WatchUi.Confirmation("Einsatz beenden & senden?");
        WatchUi.pushView(dlg, new FinishConfirmDelegate(), WatchUi.SLIDE_LEFT);
    }

    function onNextPage() as Lang.Boolean { Nav.go(1); return true; }       // kurz DOWN
    function onPreviousPage() as Lang.Boolean { Nav.go(-1); return true; }  // kurz UP

    function onBack() as Lang.Boolean {
        // Versehentliches Beenden verhindern: Dienst laeuft weiter, App bleibt offen
        var dlg = new WatchUi.Confirmation("Dienst läuft. App verlassen?");
        WatchUi.pushView(dlg, new ExitConfirmDelegate(), WatchUi.SLIDE_LEFT);
        return true;
    }
}

class ExitConfirmDelegate extends WatchUi.ConfirmationDelegate {
    function initialize() { ConfirmationDelegate.initialize(); }
    function onResponse(response) as Lang.Boolean {
        if (response == WatchUi.CONFIRM_YES) { System.exit(); }
        return true;
    }
}

class FinishConfirmDelegate extends WatchUi.ConfirmationDelegate {
    function initialize() { ConfirmationDelegate.initialize(); }
    function onResponse(response) as Lang.Boolean {
        if (response == WatchUi.CONFIRM_YES) {
            Model.finishMission();
            WatchUi.requestUpdate();
        }
        return true;
    }
}

class EndDayConfirmDelegate extends WatchUi.ConfirmationDelegate {
    function initialize() { ConfirmationDelegate.initialize(); }
    function onResponse(response) as Lang.Boolean {
        if (response == WatchUi.CONFIRM_YES) {
            Cpr.stop();
            Model.endService();
            WatchUi.switchToView(new SendingView(), new SendingDelegate(), WatchUi.SLIDE_DOWN);
        }
        return true;
    }
}

// ---------------------------------------------------------------------------
// Dienstende: "Sende Daten..." — wartet auf die Server-Bestaetigung und
// beendet die App dann automatisch. Klappt es nicht binnen
// Const.END_SYNC_WAIT_S, folgt die Rueckfrage "Trotzdem beenden?".
// ---------------------------------------------------------------------------

class SendingView extends WatchUi.View {

    var _timer as Timer.Timer or Null = null;
    var _ticks as Lang.Number = 0;

    function initialize() { View.initialize(); }

    function onShow() as Void {
        if (_timer == null) { _timer = new Timer.Timer(); }
        _timer.start(method(:tick), 1000, true);
        Uploader.syncAll();
    }

    function onHide() as Void {
        if (_timer != null) { _timer.stop(); }
    }

    function tick() as Void {
        if (Uploader.allSynced()) {
            System.exit();                        // alles bestaetigt -> App zu
        }
        _ticks += 1;
        if (_ticks >= Const.END_SYNC_WAIT_S) {
            if (_timer != null) { _timer.stop(); }
            var dlg = new WatchUi.Confirmation("Noch nicht alles gesendet. Trotzdem beenden?");
            WatchUi.pushView(dlg, new QuitConfirmDelegate(), WatchUi.SLIDE_LEFT);
            return;
        }
        Uploader.syncAll();                       // weiter versuchen
        WatchUi.requestUpdate();
    }

    function onUpdate(dc as Graphics.Dc) as Void {
        dc.setColor(Graphics.COLOR_BLACK, Graphics.COLOR_BLACK);
        dc.clear();
        var cx = dc.getWidth() / 2;
        var cy = dc.getHeight() / 2;
        dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy - 14, Graphics.FONT_MEDIUM, "Sende Daten…",
            Graphics.TEXT_JUSTIFY_CENTER);
        dc.setColor(Graphics.COLOR_LT_GRAY, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy + 26, Graphics.FONT_TINY,
            (Model.pendingMissions.size() + Model.pendingRest.size()).toString() + " offen",
            Graphics.TEXT_JUSTIFY_CENTER);
    }
}

class SendingDelegate extends WatchUi.BehaviorDelegate {
    function initialize() { BehaviorDelegate.initialize(); }
    // Waehrend des Sendens keine Aktionen — BACK ueberspringt das Warten
    function onBack() as Lang.Boolean {
        var dlg = new WatchUi.Confirmation("Noch nicht alles gesendet. Trotzdem beenden?");
        WatchUi.pushView(dlg, new QuitConfirmDelegate(), WatchUi.SLIDE_LEFT);
        return true;
    }
}

class QuitConfirmDelegate extends WatchUi.ConfirmationDelegate {
    function initialize() { ConfirmationDelegate.initialize(); }
    function onResponse(response) as Lang.Boolean {
        if (response == WatchUi.CONFIRM_YES) {
            System.exit();                        // Daten bleiben gepuffert
        } else {
            // Warten: zurueck zum Startbildschirm (Details: Sync-Seite, DOWN)
            WatchUi.switchToView(new StartView(), new StartDelegate(), WatchUi.SLIDE_DOWN);
        }
        return true;
    }
}

// ---------------------------------------------------------------------------
// Farbcodiertes Schnellmenue (Muster wie CprMenuView, Eintraege dynamisch)
// ---------------------------------------------------------------------------

class QuickMenuView extends WatchUi.View {

    var items as Lang.Array = [];   // [Label, Farbe, ID]
    var index as Lang.Number = 0;

    function initialize() {
        View.initialize();
        for (var p = 2; p <= 9; p++) {
            items.add([p.toString() + " " + Const.PHASE_LABELS[p], 0xFFFFFF, p]);
        }
        items.add(["Einsatzübersicht", 0xFFFF00, :overview]);          // gelb
        if (Model.missionActive()) {
            items.add(["Einsatz abschließen", 0x00FF00, :finish]);     // gruen
        }
        items.add(["Einsatztag beenden", 0xFF0000, :endDay]);          // rot
    }

    function onUpdate(dc as Graphics.Dc) as Void {
        dc.setColor(Graphics.COLOR_BLACK, Graphics.COLOR_BLACK);
        dc.clear();
        var cx = dc.getWidth() / 2;
        var cy = dc.getHeight() / 2;
        var rowH = 38;
        var n = items.size();

        for (var off = -2; off <= 2; off++) {
            var i = ((index + off) % n + n) % n;
            var item = items[i];
            var y = cy + off * rowH;
            if (off == 0) {
                dc.setColor(item[1] as Lang.Number, Graphics.COLOR_TRANSPARENT);
                dc.fillRoundedRectangle(14, y - rowH / 2 + 2,
                    dc.getWidth() - 28, rowH - 4, 8);
                dc.setColor(Graphics.COLOR_BLACK, Graphics.COLOR_TRANSPARENT);
                dc.drawText(cx, y, Graphics.FONT_SMALL,
                    item[0] as Lang.String,
                    Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
            } else {
                dc.setColor(item[1] as Lang.Number, Graphics.COLOR_TRANSPARENT);
                dc.drawText(cx, y, Graphics.FONT_TINY,
                    item[0] as Lang.String,
                    Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
            }
        }
    }
}

class QuickMenuDelegate extends WatchUi.BehaviorDelegate {

    var _v as QuickMenuView;

    function initialize(v as QuickMenuView) {
        BehaviorDelegate.initialize();
        _v = v;
    }

    function onPreviousPage() as Lang.Boolean {           // UP: endlos
        var n = _v.items.size();
        _v.index = (_v.index - 1 + n) % n;
        WatchUi.requestUpdate();
        return true;
    }

    function onNextPage() as Lang.Boolean {               // DOWN: endlos
        _v.index = (_v.index + 1) % _v.items.size();
        WatchUi.requestUpdate();
        return true;
    }

    function onSelect() as Lang.Boolean {                 // START
        var id = _v.items[_v.index][2];
        WatchUi.popView(WatchUi.SLIDE_RIGHT);
        if (id == :endDay) {
            var dlg = new WatchUi.Confirmation("Einsatztag beenden?");
            WatchUi.pushView(dlg, new EndDayConfirmDelegate(), WatchUi.SLIDE_LEFT);
        } else if (id == :finish) {
            ClockDelegate.pushFinishConfirm();
        } else if (id == :overview) {
            pushMissionOverview();
        } else if (id instanceof Lang.Number) {
            Model.setPhase(id as Lang.Number);            // neuer Zeitstempel
        }
        return true;
    }

    function onBack() as Lang.Boolean {
        WatchUi.popView(WatchUi.SLIDE_RIGHT);
        return true;
    }

    // "Einsatzuebersicht": scrollbare Liste der Phasen-Zeitstempel
    static function pushMissionOverview() as Void {
        var menu = new WatchUi.Menu2({ :title => "Zeiten" });
        var src = (Model.mission != null) ? Model.mission
                : (Model.pendingMissions.size() > 0
                    ? Model.pendingMissions[Model.pendingMissions.size() - 1] : null);
        if (src == null) {
            menu.addItem(new WatchUi.MenuItem("Kein Einsatz", null, 0, null));
        } else {
            var phases = src["phases"] as Lang.Array;
            for (var i = 0; i < phases.size(); i++) {
                var p = phases[i];
                var hhmm = (p.size() > 4) ? p[4] : (p[1] as Lang.String).substring(11, 16);
                menu.addItem(new WatchUi.MenuItem(
                    hhmm + "  " + Const.PHASE_LABELS[p[0]], null, i, null));
            }
        }
        WatchUi.pushView(menu, new ListCloseDelegate(), WatchUi.SLIDE_LEFT);
    }
}

// Delegate fuer reine Anzeige-Listen: jede Auswahl/Back schliesst nur
class ListCloseDelegate extends WatchUi.Menu2InputDelegate {
    function initialize() { Menu2InputDelegate.initialize(); }
    function onSelect(item as WatchUi.MenuItem) as Void { }
    function onBack() as Void { WatchUi.popView(WatchUi.SLIDE_RIGHT); }
}
