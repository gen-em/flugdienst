<?php
declare(strict_types=1);
/**
 * Zusatzfelder fuer Einsaetze — EINE zentrale Definition.
 *
 * Formular (einsatz_form.php), API (api/mission.php, api/day.php) und Anzeige
 * lesen alle diese Liste. Neues Feld = 1 Migration (update.php) + 1 Eintrag
 * hier. Alle Felder sind echte DB-Spalten (spaeter durchsuchbar).
 *
 * Typen:
 *   'text' | 'textarea' | 'number'  einfache Eingaben ('max' = Zeichen)
 *   'checkbox'                      Haken (0/1); optional 'children':
 *                                   Unterfelder, nur sichtbar/gespeichert,
 *                                   wenn der Haken gesetzt ist
 *   'select'                        Dropdown; 'options' = feste Werteliste
 *                                   ODER 'options_src' => 'bw_units'
 *                                   (Bergwacht-Bereitschaften aus Stammdaten)
 *
 * Weitere Schluessel:
 *   'day_col'   => true|'check'     Spalte in der Tagestabelle (Text bzw. ✓)
 *   'day_label' => 'Winde'          Spaltentitel (sonst 'label')
 *   'placeholder'
 *
 * Der Einsatzort (Adresse + Koordinaten, Photon-Autocomplete) ist bewusst
 * KEIN Eintrag hier — er hat ein eigenes Widget (loc_addr/loc_lat/loc_lon).
 */
return [
    'mission_no' => [
        'label' => 'Einsatznummer', 'type' => 'text', 'max' => 64,
        'placeholder' => 'z. B. Leitstellen-Nr.',
    ],
    'transport_dest' => [
        'label' => 'Transportziel', 'type' => 'text', 'max' => 190,
        'placeholder' => 'z. B. Klinikum Kempten',
    ],
    'site_desc' => [
        'label' => 'Beschreibung Einsatzort', 'type' => 'text', 'max' => 190,
        'day_col' => true, 'day_label' => 'Einsatzort',
        'placeholder' => 'kurz, erscheint in der Tagesübersicht',
    ],
    'winch' => [
        'label' => 'Windeneinsatz', 'type' => 'checkbox',
        'day_col' => 'check', 'day_label' => 'Winde',
        'children' => [
            'winch_cycles' => [
                'label' => 'Cycles', 'type' => 'select',
                'options' => ['0','1','2','3','4','5','6','7','8'],
            ],
            'winch_cycles_pat' => [
                'label' => 'Cycles mit Patient', 'type' => 'select',
                'options' => ['0','1','2','3','4','5','6','7','8'],
            ],
            'winch_airload' => [ 'label' => 'Luftverladung', 'type' => 'checkbox' ],
        ],
    ],
    'bergwacht' => [
        'label' => 'Bergwacht', 'type' => 'checkbox',
        'day_col' => 'check', 'day_label' => 'Bergwacht',
        'children' => [
            'bw_unit' => [
                'label' => 'Bereitschaft', 'type' => 'select',
                'options_src' => 'bw_units',
            ],
            'bw_info' => [
                'label' => 'Namen / Infos', 'type' => 'text', 'max' => 190,
            ],
        ],
    ],
    'other_ema' => [
        'label' => 'Anderer Notarzt', 'type' => 'text', 'max' => 190,
    ],
    'other_resources' => [
        'label' => 'Weitere Rettungsmittel', 'type' => 'text', 'max' => 190,
    ],
    'notes' => [
        'label' => 'Notizen', 'type' => 'textarea', 'max' => 2000,
        'placeholder' => 'Freitext (keine Patientendaten!)',
    ],
];
