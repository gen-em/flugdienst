// Einsatzdoku — Geraete-Kopplung per Kurzcode
//
// Auf dem Startbildschirm UP halten -> Code eintippen (5 Zeichen, aus dem
// Web unter Einstellungen -> Geraete). Die Uhr tauscht den Code bei pair.php
// gegen frische Zugangsdaten und speichert sie dauerhaft im Uhr-Speicher —
// deviceId/apiKey muessen nie mehr von Hand eingetragen werden.
// Voraussetzung: Server-Domain in den App-Einstellungen (properties.xml).
using Toybox.Lang;
using Toybox.WatchUi;
using Toybox.Communications;
using Toybox.Application.Storage;

// Rueckruf-Traeger (method() existiert nur auf Objekten)
class PairCb {
    function initialize() { }
    function onResponse(code as Lang.Number, data as Lang.Dictionary or Lang.String or Null) as Void {
        Pair.onResponse(code, data);
    }
}

module Pair {

    var status as Lang.String or Null = null;   // Anzeige auf dem Startbildschirm
    var _cb as PairCb or Null = null;

    function openInput() as Void {
        var tp = new WatchUi.TextPicker("");
        WatchUi.pushView(tp, new PairTextDelegate(), WatchUi.SLIDE_LEFT);
    }

    function request(code as Lang.String) as Void {
        var base = Uploader.serverBase();
        if (base.length() == 0) {
            status = "Erst Server-Domain setzen";
            WatchUi.requestUpdate();
            return;
        }
        status = "Kopple…";
        WatchUi.requestUpdate();
        if (_cb == null) { _cb = new PairCb(); }
        Communications.makeWebRequest(
            base + "pair.php",
            { "code" => code.toUpper() },
            {
                :method => Communications.HTTP_REQUEST_METHOD_POST,
                :headers => { "Content-Type" => Communications.REQUEST_CONTENT_TYPE_JSON },
                :responseType => Communications.HTTP_RESPONSE_CONTENT_TYPE_JSON
            },
            _cb.method(:onResponse));
    }

    function onResponse(code as Lang.Number, data) as Void {
        if (code == 200 && data instanceof Lang.Dictionary && data["device_id"] != null) {
            Storage.setValue("cred", {
                "d" => data["device_id"], "k" => data["api_key"]
            });
            Uploader.lastError = null;
            status = "Gekoppelt ✓";
        } else if (code == 404) {
            status = "Code ungültig/abgelaufen";
        } else {
            status = "Kopplung fehlgeschlagen (" + code.toString() + ")";
        }
        WatchUi.requestUpdate();
    }
}

class PairTextDelegate extends WatchUi.TextPickerDelegate {
    function initialize() { TextPickerDelegate.initialize(); }
    function onTextEntered(text as Lang.String, changed as Lang.Boolean) as Lang.Boolean {
        if (text.length() > 0) { Pair.request(text); }
        return true;
    }
    function onCancel() as Lang.Boolean { return true; }
}
