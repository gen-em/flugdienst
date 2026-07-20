// Einsatzdoku — zentrale Konstanten (Werte aus Anforderungen v1.0)
using Toybox.Lang;

module Const {

    const APP_VERSION = "1.3.2";      // bei jedem Release erhoehen

    // Phasen 1..9 (Index 0 unbenutzt). Der Einsatz-Abschluss ist KEIN
    // Zeitstempel mehr, sondern eine bestaetigte Aktion (Phase 10 entfaellt).
    const PHASE_LABELS = [
        "", "Frei", "Alarmierung", "Abflug", "Ankunft Einsatzort",
        "Ankunft PatientIn", "Transportbeginn", "Landung KKH",
        "Übergabe", "Einsatzende"
    ];

    // Reanimations-Ereignistypen (Server-Vertrag, Abschnitt 3)
    const R_ADRENALIN   = "adrenalin";
    const R_RHYTHMUS    = "rhythmuskontrolle";
    const R_DEFI        = "defibrillation";
    const R_INTUBATION  = "intubation";
    const R_AMIODARON   = "amiodaron";
    const R_SONO        = "sonographie";
    const R_ZUGANG      = "zugang";
    const R_ROSC        = "rosc";
    const R_TOD         = "tod";

    const RESUS_LABELS = {
        "adrenalin" => "Adrenalin", "rhythmuskontrolle" => "Rhythmuskontrolle",
        "defibrillation" => "Defibrillation", "intubation" => "Intubation",
        "amiodaron" => "Amiodaron", "sonographie" => "Sonographie",
        "zugang" => "Zugang",
        "rosc" => "ROSC", "tod" => "Tod"
    };

    // GPS-Ausduennung (Anforderungen 1.5) — spaeter justierbar
    const THIN_MIN_DIST_M = 15.0;   // neuer Punkt ab >= 15 m
    const THIN_MAX_GAP_S  = 10;     // spaetestens alle 10 s
    const THIN_MIN_GAP_S  = 1;      // nie oefter als 1/s

    // Anzeige-Polylinie (Anforderungen 1.3)
    const DISPLAY_MAX_POINTS = 1000; // bei Ueberlauf Dichte halbieren

    // Upload
    const UPLOAD_CHUNK_POINTS = 500; // JSON-Vertrag Abschnitt 6
    const REST_SYNC_INTERVAL_S = 3600;

    // Reanimation
    const CPR_CYCLE_S = 120;         // 2:00-Countdown
    const LONG_PRESS_MS = 1000;
    const END_SYNC_WAIT_S = 3;       // Dienstende: so lange senden, dann fragen

    // Storage-Schluessel
    const K_STATE = "state";         // Dienst-/Einsatz-/Rea-Zustand
    const K_TRACK_META = "trk_meta"; // Zaehler & Upload-Marken
}
