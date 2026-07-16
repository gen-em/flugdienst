// Einsatzdoku — Oberflaeche 1: Uhrzeit + Phase (Anforderungen 1.2)
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

        var t = System.getClockTime();
        dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy - 62, Graphics.FONT_NUMBER_MEDIUM,
            t.hour.format("%02d") + ":" + t.min.format("%02d"),
            Graphics.TEXT_JUSTIFY_CENTER);

        // Phase gross: Zahl + Bezeichnung
        dc.setColor(Graphics.COLOR_ORANGE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy + 8, Graphics.FONT_NUMBER_MILD,
            Model.phase.toString(), Graphics.TEXT_JUSTIFY_CENTER);
        dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy + 52, Graphics.FONT_SMALL,
            Const.PHASE_LABELS[Model.phase], Graphics.TEXT_JUSTIFY_CENTER);

        // dezente Statuszeile: laufende Rea / Upload-Problem
        if (Cpr.active) {
            dc.setColor(Graphics.COLOR_RED, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, dc.getHeight() - 32, Graphics.FONT_XTINY,
                "REA läuft", Graphics.TEXT_JUSTIFY_CENTER);
        } else if (Uploader.lastError != null) {
            dc.setColor(Graphics.COLOR_LT_GRAY, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, dc.getHeight() - 32, Graphics.FONT_XTINY,
                "Sync ausstehend", Graphics.TEXT_JUSTIFY_CENTER);
        }
    }
}

class ClockDelegate extends WatchUi.BehaviorDelegate {

    var _pressStart as Lang.Dictionary = {};   // key -> System.getTimer()

    function initialize() { BehaviorDelegate.initialize(); }

    // START manuell vermessen: kurz = naechste Phase, lang = Schnellmenue
    function onKeyPressed(evt as WatchUi.KeyEvent) as Lang.Boolean {
        if (evt.getKey() == WatchUi.KEY_ENTER) {
            _pressStart[WatchUi.KEY_ENTER] = System.getTimer();
            return true;
        }
        return false;
    }

    function onKeyReleased(evt as WatchUi.KeyEvent) as Lang.Boolean {
        if (evt.getKey() != WatchUi.KEY_ENTER) { return false; }
        var t0 = _pressStart[WatchUi.KEY_ENTER];
        if (t0 == null) { return false; }
        _pressStart.remove(WatchUi.KEY_ENTER);
        if ((System.getTimer() - t0) >= Const.LONG_PRESS_MS) {
            _pushQuickMenu();
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
        var menu = new WatchUi.Menu2({ :title => "Schnellmenü" });
        for (var p = 2; p <= 10; p++) {
            menu.addItem(new WatchUi.MenuItem(
                p.toString() + " " + Const.PHASE_LABELS[p], null, p, null));
        }
        menu.addItem(new WatchUi.MenuItem("Einsatzübersicht Zeiten", null, :overview, null));
        menu.addItem(new WatchUi.MenuItem("Einsatztag beenden", null, :endDay, null));
        WatchUi.pushView(menu, new QuickMenuDelegate(), WatchUi.SLIDE_LEFT);
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

class QuickMenuDelegate extends WatchUi.Menu2InputDelegate {

    function initialize() { Menu2InputDelegate.initialize(); }

    function onSelect(item as WatchUi.MenuItem) as Void {
        var id = item.getId();
        if (id == :endDay) {
            WatchUi.popView(WatchUi.SLIDE_RIGHT);
            var dlg = new WatchUi.Confirmation("Einsatztag beenden?");
            WatchUi.pushView(dlg, new EndDayConfirmDelegate(), WatchUi.SLIDE_LEFT);
        } else if (id == :overview) {
            WatchUi.popView(WatchUi.SLIDE_RIGHT);
            pushMissionOverview();
        } else if (id instanceof Lang.Number) {
            Model.setPhase(id as Lang.Number);      // Schnellauswahl: neuer Zeitstempel
            WatchUi.popView(WatchUi.SLIDE_RIGHT);
        }
    }

    // "Einsatzuebersicht Zeiten": scrollbare Liste der Phasen-Zeitstempel
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

class EndDayConfirmDelegate extends WatchUi.ConfirmationDelegate {
    function initialize() { ConfirmationDelegate.initialize(); }
    function onResponse(response) as Lang.Boolean {
        if (response == WatchUi.CONFIRM_YES) {
            Cpr.stop();
            Model.endService();
            WatchUi.switchToView(new StartView(), new StartDelegate(), WatchUi.SLIDE_DOWN);
        }
        return true;
    }
}

// Delegate fuer reine Anzeige-Listen: jede Auswahl/Back schliesst nur
class ListCloseDelegate extends WatchUi.Menu2InputDelegate {
    function initialize() { Menu2InputDelegate.initialize(); }
    function onSelect(item as WatchUi.MenuItem) as Void { }
    function onBack() as Void { WatchUi.popView(WatchUi.SLIDE_RIGHT); }
}
