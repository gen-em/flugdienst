// Einsatzdoku — Oberflaeche 3: Reanimation (Anforderungen 1.4)
//
// Tasten: kurz UP/DOWN Navigation | kurz START Rea-Beginn/Countdown-Neustart
//         lang UP Adrenalin | lang DOWN Rhythmuskontrolle | lang START Menue
//         BACK verlaesst die Oberflaeche
// Lang-Druecke werden manuell ueber onKeyPressed/onKeyReleased erkannt.
// Das Untermenue ist selbst gezeichnet (Menu2 kann keine Farben) und enthaelt
// "Aufzeichnung beenden": schliesst die laufende Rea; erneuter Start beginnt
// eine neue Reanimation (mehrere pro Einsatz).
using Toybox.WatchUi;
using Toybox.Graphics;
using Toybox.System;
using Toybox.Lang;

class CprView extends WatchUi.View {

    function initialize() { View.initialize(); }

    function onUpdate(dc as Graphics.Dc) as Void {
        dc.setColor(Graphics.COLOR_BLACK, Graphics.COLOR_BLACK);
        dc.clear();
        var cx = dc.getWidth() / 2;
        var cy = dc.getHeight() / 2;

        if (!Cpr.active) {
            dc.setColor(Graphics.COLOR_RED, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, cy - 40, Graphics.FONT_MEDIUM, "Reanimation",
                Graphics.TEXT_JUSTIFY_CENTER);
            dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_TRANSPARENT);
            dc.drawText(cx, cy + 4, Graphics.FONT_SMALL, "START = Beginn",
                Graphics.TEXT_JUSTIFY_CENTER);
            return;
        }

        // kleiner Timer: Gesamtdauer vorwaerts (LILA)
        var e = Cpr.elapsedS();
        dc.setColor(Graphics.COLOR_PURPLE, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, 28, Graphics.FONT_SMALL,
            _mmss(e), Graphics.TEXT_JUSTIFY_CENTER);

        // grosser Timer: 2:00-Countdown (steht bei 0:00, dann rot)
        var r = Cpr.cycleRemainingS();
        dc.setColor(r == 0 ? Graphics.COLOR_RED : Graphics.COLOR_WHITE,
            Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy - 34, Graphics.FONT_NUMBER_THAI_HOT,
            _mmss(r), Graphics.TEXT_JUSTIFY_CENTER);
    }

    function _mmss(s as Lang.Number) as Lang.String {
        return (s / 60).format("%d") + ":" + (s % 60).format("%02d");
    }
}

class CprDelegate extends WatchUi.BehaviorDelegate {

    var _pressStart as Lang.Dictionary = {};   // key -> System.getTimer()

    function initialize() { BehaviorDelegate.initialize(); }

    function onKeyPressed(evt as WatchUi.KeyEvent) as Lang.Boolean {
        var k = evt.getKey();
        if (k == WatchUi.KEY_UP || k == WatchUi.KEY_DOWN || k == WatchUi.KEY_ENTER) {
            _pressStart[k] = System.getTimer();
            return true;
        }
        return false;
    }

    function onKeyReleased(evt as WatchUi.KeyEvent) as Lang.Boolean {
        var k = evt.getKey();
        var t0 = _pressStart[k];
        if (t0 == null) { return false; }
        _pressStart.remove(k);
        var isLong = (System.getTimer() - t0) >= Const.LONG_PRESS_MS;

        if (k == WatchUi.KEY_UP) {
            if (isLong) { Cpr.markAdrenalin(); } else { Nav.go(-1); }
            return true;
        }
        if (k == WatchUi.KEY_DOWN) {
            if (isLong) { Cpr.markRhythmus(); } else { Nav.go(1); }
            return true;
        }
        if (k == WatchUi.KEY_ENTER) {
            if (isLong) { _pushMenu(); } else { Cpr.start(); }
            return true;
        }
        return false;
    }

    // Falls das System lang-UP dennoch als onMenu meldet: gleiche Bedeutung.
    function onMenu() as Lang.Boolean {
        if (Cpr.active) { Cpr.markAdrenalin(); }
        return true;
    }

    function onKey(evt as WatchUi.KeyEvent) as Lang.Boolean {
        var k = evt.getKey();
        return (k == WatchUi.KEY_UP || k == WatchUi.KEY_DOWN || k == WatchUi.KEY_ENTER);
    }

    function onBack() as Lang.Boolean { Nav.goTo(:clock); return true; }

    function _pushMenu() as Void {
        var v = new CprMenuView();
        WatchUi.pushView(v, new CprMenuDelegate(v), WatchUi.SLIDE_LEFT);
    }
}

// ---------------------------------------------------------------------------
// Selbst gezeichnetes, farbcodiertes Untermenue
// ---------------------------------------------------------------------------

class CprMenuView extends WatchUi.View {

    // [Label, Farbe, ID]
    static const ITEMS = [
        ["Defibrillation", 0xFFAA00, Const.R_DEFI],        // bernstein (Strom)
        ["Intubation",     0x00AAFF, Const.R_INTUBATION],  // blau (Atemweg)
        ["Amiodaron",      0xAA00FF, Const.R_AMIODARON],   // violett (Pharma)
        ["Sonographie",    0x00FFAA, Const.R_SONO],        // tuerkis (Bild)
        ["ROSC",           0x00FF00, Const.R_ROSC],        // gruen (Erfolg)
        ["Tod",            0xAAAAAA, Const.R_TOD],         // grau
        ["Übersicht",      0xFFFFFF, :overview],           // weiss
        ["Rea beenden",    0xFF0000, :stopRec]             // rot
    ];

    var index as Lang.Number = 0;

    function initialize() { View.initialize(); }

    function onUpdate(dc as Graphics.Dc) as Void {
        dc.setColor(Graphics.COLOR_BLACK, Graphics.COLOR_BLACK);
        dc.clear();
        var cx = dc.getWidth() / 2;
        var cy = dc.getHeight() / 2;
        var rowH = 38;
        var n = ITEMS.size();

        // 5 Zeilen: 2 davor, Auswahl mittig, 2 danach — endlos (Modulo)
        for (var off = -2; off <= 2; off++) {
            var i = ((index + off) % n + n) % n;
            var item = ITEMS[i];
            var y = cy + off * rowH;   // y = vertikale Mitte der Zeile
            if (off == 0) {
                // Auswahl: farbige Kachel, Text exakt vertikal zentriert
                dc.setColor(item[1] as Lang.Number, Graphics.COLOR_TRANSPARENT);
                dc.fillRoundedRectangle(14, y - rowH / 2 + 2,
                    dc.getWidth() - 28, rowH - 4, 8);
                dc.setColor(Graphics.COLOR_BLACK, Graphics.COLOR_TRANSPARENT);
                dc.drawText(cx, y, Graphics.FONT_SMALL,
                    item[0] as Lang.String,
                    Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
            } else {
                // Nachbarn: farbiger Text auf schwarz, ebenfalls zentriert
                dc.setColor(item[1] as Lang.Number, Graphics.COLOR_TRANSPARENT);
                dc.drawText(cx, y, Graphics.FONT_TINY,
                    item[0] as Lang.String,
                    Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
            }
        }
    }
}

class StopRecConfirmDelegate extends WatchUi.ConfirmationDelegate {
    function initialize() { ConfirmationDelegate.initialize(); }
    function onResponse(response) as Lang.Boolean {
        if (response == WatchUi.CONFIRM_YES) { Cpr.stopRecording(); }
        return true;
    }
}

class CprMenuDelegate extends WatchUi.BehaviorDelegate {

    var _v as CprMenuView;

    function initialize(v as CprMenuView) {
        BehaviorDelegate.initialize();
        _v = v;
    }

    function onPreviousPage() as Lang.Boolean {           // UP: endlos
        var n = CprMenuView.ITEMS.size();
        _v.index = (_v.index - 1 + n) % n;
        WatchUi.requestUpdate();
        return true;
    }

    function onNextPage() as Lang.Boolean {               // DOWN: endlos
        _v.index = (_v.index + 1) % CprMenuView.ITEMS.size();
        WatchUi.requestUpdate();
        return true;
    }

    function onSelect() as Lang.Boolean {                 // START
        var id = CprMenuView.ITEMS[_v.index][2];
        WatchUi.popView(WatchUi.SLIDE_RIGHT);
        if (id == :overview) {
            _pushOverview();
        } else if (id == :stopRec) {
            // Sicherheitsabfrage vor dem Beenden der Rea-Aufzeichnung
            var dlg = new WatchUi.Confirmation("Rea beenden?");
            WatchUi.pushView(dlg, new StopRecConfirmDelegate(), WatchUi.SLIDE_LEFT);
        } else {
            Cpr.markEvent(id as Lang.String);
        }
        return true;
    }

    function onBack() as Lang.Boolean {
        WatchUi.popView(WatchUi.SLIDE_RIGHT);
        return true;
    }

    // Uebersicht der aktuellen (letzten) Rea-Sitzung als scrollbare Liste
    function _pushOverview() as Void {
        var menu = new WatchUi.Menu2({ :title => "Rea-Zeiten" });
        var sess = Model.currentResus();
        if (sess == null) {
            menu.addItem(new WatchUi.MenuItem("Keine Reanimation", null, 0, null));
        } else {
            menu.addItem(new WatchUi.MenuItem(
                (sess["startLocal"] as Lang.String) + "  Beginn", null, -1, null));
            var evs = sess["events"] as Lang.Array;
            for (var i = 0; i < evs.size(); i++) {
                var ev = evs[i];
                menu.addItem(new WatchUi.MenuItem(
                    (ev[2] as Lang.String) + "  " + Const.RESUS_LABELS[ev[0]],
                    null, i, null));
            }
        }
        WatchUi.pushView(menu, new ListCloseDelegate(), WatchUi.SLIDE_LEFT);
    }
}
