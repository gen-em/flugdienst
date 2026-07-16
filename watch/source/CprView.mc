// Einsatzdoku — Oberflaeche 3: Reanimation (Anforderungen 1.4)
//
// Tasten: kurz UP/DOWN Navigation | kurz START Rea-Beginn/Countdown-Neustart
//         lang UP Adrenalin | lang DOWN Rhythmuskontrolle | lang START Menue
//         BACK verlaesst die Oberflaeche
// Lang-Druecke werden manuell ueber onKeyPressed/onKeyReleased erkannt
// (BehaviorDelegate liefert nativ nur lang-UP als onMenu).
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

        // kleiner Timer: Gesamtdauer vorwaerts
        var e = Cpr.elapsedS();
        dc.setColor(Graphics.COLOR_LT_GRAY, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, 28, Graphics.FONT_SMALL,
            _mmss(e), Graphics.TEXT_JUSTIFY_CENTER);

        // grosser Timer: 2:00-Countdown (steht bei 0:00)
        var r = Cpr.cycleRemainingS();
        dc.setColor(r == 0 ? Graphics.COLOR_RED : Graphics.COLOR_WHITE,
            Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, cy - 34, Graphics.FONT_NUMBER_THAI_HOT,
            _mmss(r), Graphics.TEXT_JUSTIFY_CENTER);

        dc.setColor(Graphics.COLOR_LT_GRAY, Graphics.COLOR_TRANSPARENT);
        dc.drawText(cx, dc.getHeight() - 52, Graphics.FONT_XTINY,
            "lang↑ Adrenalin  lang↓ Rhythmus", Graphics.TEXT_JUSTIFY_CENTER);
        dc.drawText(cx, dc.getHeight() - 34, Graphics.FONT_XTINY,
            "lang START Menü", Graphics.TEXT_JUSTIFY_CENTER);
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
        // Default-Verhalten (onSelect etc.) unterdruecken — alles laeuft
        // ueber pressed/released.
        var k = evt.getKey();
        return (k == WatchUi.KEY_UP || k == WatchUi.KEY_DOWN || k == WatchUi.KEY_ENTER);
    }

    function onBack() as Lang.Boolean { Nav.goTo(:clock); return true; }

    function _pushMenu() as Void {
        var menu = new WatchUi.Menu2({ :title => "Reanimation" });
        menu.addItem(new WatchUi.MenuItem("Defibrillation", null, Const.R_DEFI, null));
        menu.addItem(new WatchUi.MenuItem("Intubation", null, Const.R_INTUBATION, null));
        menu.addItem(new WatchUi.MenuItem("Amiodaron", null, Const.R_AMIODARON, null));
        menu.addItem(new WatchUi.MenuItem("Sonographie", null, Const.R_SONO, null));
        menu.addItem(new WatchUi.MenuItem("ROSC", null, Const.R_ROSC, null));
        menu.addItem(new WatchUi.MenuItem("Tod", null, Const.R_TOD, null));
        menu.addItem(new WatchUi.MenuItem("Übersicht", null, :overview, null));
        WatchUi.pushView(menu, new CprMenuDelegate(), WatchUi.SLIDE_LEFT);
    }
}

class CprMenuDelegate extends WatchUi.Menu2InputDelegate {

    function initialize() { Menu2InputDelegate.initialize(); }

    function onSelect(item as WatchUi.MenuItem) as Void {
        var id = item.getId();
        if (id == :overview) {
            WatchUi.popView(WatchUi.SLIDE_RIGHT);
            _pushOverview();
        } else if (id instanceof Lang.String) {
            Cpr.markEvent(id as Lang.String);
            WatchUi.popView(WatchUi.SLIDE_RIGHT);
        }
    }

    function _pushOverview() as Void {
        var menu = new WatchUi.Menu2({ :title => "Rea-Zeiten" });
        var src = Model.mission;
        if (src == null || src["resusStart"] == null) {
            menu.addItem(new WatchUi.MenuItem("Keine Reanimation", null, 0, null));
        } else {
            var hhmm = (src["resusStartLocal"] != null)
                ? src["resusStartLocal"]
                : (src["resusStart"] as Lang.String).substring(11, 16);
            menu.addItem(new WatchUi.MenuItem(hhmm + "  Beginn", null, -1, null));
            var evs = src["resusEvents"] as Lang.Array;
            for (var i = 0; i < evs.size(); i++) {
                var ev = evs[i];
                var t = (ev.size() > 2) ? ev[2] : (ev[1] as Lang.String).substring(11, 16);
                menu.addItem(new WatchUi.MenuItem(
                    t + "  " + Const.RESUS_LABELS[ev[0]], null, i, null));
            }
        }
        WatchUi.pushView(menu, new ListCloseDelegate(), WatchUi.SLIDE_LEFT);
    }
}
