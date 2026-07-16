// Einsatzdoku — Pager zwischen den drei Oberflaechen (Anforderungen 1.2-1.4)
using Toybox.WatchUi;
using Toybox.Lang;

module Nav {

    const PAGES = [:clock, :map, :cpr];
    var index as Lang.Number = 0;

    function go(delta as Lang.Number) as Void {
        index = (index + delta + PAGES.size()) % PAGES.size();
        var t = (delta > 0) ? WatchUi.SLIDE_UP : WatchUi.SLIDE_DOWN;
        var pair = build(PAGES[index]);
        WatchUi.switchToView(pair[0], pair[1], t);
    }

    function goTo(page as Lang.Symbol) as Void {
        index = PAGES.indexOf(page);
        if (index < 0) { index = 0; }
        var pair = build(PAGES[index]);
        WatchUi.switchToView(pair[0], pair[1], WatchUi.SLIDE_IMMEDIATE);
    }

    function build(page as Lang.Symbol) as Lang.Array {
        if (page == :map) { return [new MapPageView(), new MapPageDelegate()]; }
        if (page == :cpr) { return [new CprView(), new CprDelegate()]; }
        return [new ClockView(), new ClockDelegate()];
    }
}
