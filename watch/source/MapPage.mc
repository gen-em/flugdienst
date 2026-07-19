// Einsatzdoku — Oberflaeche 2: Karte mit Position + Einsatz-Track (Anf. 1.3)
//
// Nutzt WatchUi.MapView (CIQ >= 3.0.10, kartografiefaehige Geraete wie
// Fenix 6 Pro). MapView unterstuetzt genau EINE Polylinie -> Einsatz-Track.
using Toybox.WatchUi;
using Toybox.Position;
using Toybox.Math;
using Toybox.Lang;
using Toybox.System;

class MapPageView extends WatchUi.MapView {

    var zoomMode as Lang.Boolean = false;  // eigener Zoom-Modus (START schaltet um)
    var spanM as Lang.Float = 2000.0;      // halbe Kantenlaenge des Ausschnitts in Metern

    function initialize() {
        MapView.initialize();
        setMapMode(WatchUi.MAP_MODE_PREVIEW);
        // Pflicht vor der ersten Anzeige: welcher Bildschirmbereich gehoert
        // der Karte? Hier: der komplette Bildschirm.
        var s = System.getDeviceSettings();
        setScreenVisibleArea(0, 0, s.screenWidth, s.screenHeight);
        // Ebenfalls Pflicht: eine initiale Kartenflaeche. Ohne bekannte
        // Position starten wir ueber Sueddeutschland; sobald GPS-Punkte da
        // sind, uebernimmt _refreshTrack() die echte Ausrichtung.
        var ll = Track.lastLatLon();
        var lat = (ll[0] != null) ? ll[0] : 48.5d;
        var lon = (ll[1] != null) ? ll[1] : 10.5d;
        setMapVisibleArea(
            new Position.Location({ :latitude => lat + 0.02d, :longitude => lon - 0.03d, :format => :degrees }),
            new Position.Location({ :latitude => lat - 0.02d, :longitude => lon + 0.03d, :format => :degrees }));
    }

    function onShow() as Void {
        _refreshTrack();
    }

    function onUpdate(dc) as Void {
        if (zoomMode) { _applyZoom(); } else { _refreshTrack(); }
        MapView.onUpdate(dc);
    }

    // Zoom-Modus: Ausschnitt um die aktuelle Position (Rueckfall: Track-Fit)
    function _applyZoom() as Void {
        var c = Track.lastLatLon();
        if (c[0] == null) { _refreshTrack(); return; }
        var lat = c[0] as Lang.Float;
        var lon = c[1] as Lang.Float;
        var dLat = spanM / 111320.0;
        var cosLat = Math.cos(lat * Math.PI / 180.0);
        if (cosLat < 0.1) { cosLat = 0.1; }
        var dLon = spanM / (111320.0 * cosLat);
        setMapVisibleArea(
            new Position.Location({ :latitude => lat - dLat, :longitude => lon - dLon, :format => :degrees }),
            new Position.Location({ :latitude => lat + dLat, :longitude => lon + dLon, :format => :degrees }));
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
                // Noch kein Track: kleiner Rahmen um die Position
                // (identische Ecken = Flaeche der Groesse 0 -> vermeiden)
                setMapVisibleArea(
                    new Position.Location({ :latitude => ll[0] + 0.01d, :longitude => ll[1] - 0.015d, :format => :degrees }),
                    new Position.Location({ :latitude => ll[0] - 0.01d, :longitude => ll[1] + 0.015d, :format => :degrees }));
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

    var _v as MapPageView;

    function initialize(v as MapPageView) {
        BehaviorDelegate.initialize();
        _v = v;
    }

    // kurz START: Zoom-Modus an/aus. Im Zoom-Modus zoomen UP/DOWN um die
    // aktuelle Position; BACK verlaesst erst den Modus, dann die Seite.
    function onSelect() as Lang.Boolean {
        _v.zoomMode = !_v.zoomMode;
        WatchUi.requestUpdate();
        return true;
    }

    function onPreviousPage() as Lang.Boolean {            // UP
        if (_v.zoomMode) {
            _v.spanM = _v.spanM * 1.6;                     // rauszoomen
            if (_v.spanM > 150000.0) { _v.spanM = 150000.0; }
            WatchUi.requestUpdate();
            return true;
        }
        Nav.go(-1); return true;
    }

    function onNextPage() as Lang.Boolean {                // DOWN
        if (_v.zoomMode) {
            _v.spanM = _v.spanM / 1.6;                     // reinzoomen
            if (_v.spanM < 150.0) { _v.spanM = 150.0; }
            WatchUi.requestUpdate();
            return true;
        }
        Nav.go(1); return true;
    }

    function onBack() as Lang.Boolean {
        if (_v.zoomMode) {
            _v.zoomMode = false;                           // zurueck zum Track-Fit
            WatchUi.requestUpdate();
            return true;
        }
        Nav.goTo(:clock); return true;
    }
}
