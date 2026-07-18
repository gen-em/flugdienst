<?php
declare(strict_types=1);
/**
 * Zusatzfelder fuer Einsaetze — EINE zentrale Definition.
 *
 * Formular (einsatz_form.php), API (api/mission.php) und Anzeige (einsatz.php)
 * lesen alle diese Liste. Ein neues Feld ergaenzen heisst:
 *   1. Datenbankspalte per Migration in update.php anlegen
 *   2. Hier einen Eintrag hinzufuegen
 * Fertig — Formular, Speichern und Anzeige uebernehmen es automatisch.
 *
 * Eintrag-Format:
 *   'spaltenname' => [
 *       'label'       => 'Anzeigename',
 *       'type'        => 'text' | 'textarea' | 'number',
 *       'max'         => maximale Zeichenlaenge (text/textarea),
 *       'placeholder' => optionaler Platzhalter im Formular,
 *   ]
 */
return [
    'mission_no' => [
        'label' => 'Einsatznummer',
        'type' => 'text', 'max' => 64,
        'placeholder' => 'z. B. Leitstellen-Nr.',
    ],
    'notes' => [
        'label' => 'Notizen',
        'type' => 'textarea', 'max' => 2000,
        'placeholder' => 'Freitext (keine Patientendaten!)',
    ],
];
