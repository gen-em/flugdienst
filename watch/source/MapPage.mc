// Einsatzdoku — Oberflaeche 2: Karte mit Position + Einsatz-Track (Anf. 1.3)
//
// Nutzt WatchUi.MapView (CIQ >= 3.0.10, kartografiefaehige Geraete wie
// Fenix 6 Pro). MapView unterstuetzt genau EINE Polylinie -> Einsatz-Track.
using Toybox.WatchUi;
using Toybox.Position;
using Toybox.Lang;

class MapPageView extends WatchUi.MapView {

    function initialize() {
        MapView.initialize();
        setMapMode(WatchUi.MAP_MODE_PREVIEW);
    }

    function onShow() as Void {
        _refreshTrack();
    }

    function onUpdate(dc) as Void {
        _refreshTrack();
        MapView.onUpdate(dc);
    }

    function _refreshTrack() as Void {
        var d = Track.display;                 // flach [lat,lon,...]
        if (d.size() >= 4) {
            var poly = new WatchUi.MapPolyline();
            poly.setColor(0xE8590C);
            for (var i = 0; i < d.size(); i += 2) {
                poly.addLocation(new Position.Location(
                    { :latitude => d[i], :longitude => d[i + 1],
                      :format => :degrees }));
            }
            setPolyline(poly);
        }
        var ll = Track.lastLatLon();
        if (ll[0] != null) {
            var loc = new Position.Location(
                { :latitude => ll[0], :longitude => ll[1], :format => :degrees });
            var marker = new WatchUi.MapMarker(loc);
            setMapMarker([marker]);
            if (d.size() < 4) {
                // Noch kein Track: Karte auf Position zentrieren
                setMapVisibleArea(loc, loc);
            } else {
                _fitToTrack(d);
            }
        }
    }

    function _fitToTrack(d as Lang.Array) as Void {
        var minLat = d[0]; var maxLat = d[0];
        var minLon = d[1]; var maxLon = d[1];
        for (var i = 2; i < d.size(); i += 2) {
            if (d[i] < minLat) { minLat = d[i]; }
            if (d[i] > maxLat) { maxLat = d[i]; }
            if (d[i + 1] < minLon) { minLon = d[i + 1]; }
            if (d[i + 1] > maxLon) { maxLon = d[i + 1]; }
        }
        setMapVisibleArea(
            new Position.Location({ :latitude => minLat, :longitude => minLon, :format => :degrees }),
            new Position.Location({ :latitude => maxLat, :longitude => maxLon, :format => :degrees }));
    }
}

class MapPageDelegate extends WatchUi.BehaviorDelegate {

    function initialize() { BehaviorDelegate.initialize(); }

    function onNextPage() as Lang.Boolean { Nav.go(1); return true; }
    function onPreviousPage() as Lang.Boolean { Nav.go(-1); return true; }
    function onBack() as Lang.Boolean { Nav.goTo(:clock); return true; }
}
