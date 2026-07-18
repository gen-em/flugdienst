// Einsatzdoku — App-Einstieg
using Toybox.Application;
using Toybox.Lang;
using Toybox.WatchUi;

class HemsApp extends Application.AppBase {

    function initialize() { AppBase.initialize(); }

    function onStart(state as Lang.Dictionary or Null) as Void {
        Model.load();
        Track.restore();
        if (Model.serviceActive) {
            // Uhr-/App-Neustart mitten im Dienst: nahtlos weiter
            Track.startPositioning();
            Cpr.restore();       // laufende Reanimation wiederaufnehmen
            Uploader.syncAll();
        }
    }

    function onStop(state as Lang.Dictionary or Null) as Void {
        Track.flushForShutdown();
        Model.save();
    }

    function getInitialView() as [WatchUi.Views] or [WatchUi.Views, WatchUi.InputDelegates] {
        if (Model.serviceActive) {
            return [new ClockView(), new ClockDelegate()];
        }
        return [new StartView(), new StartDelegate()];
    }
}
