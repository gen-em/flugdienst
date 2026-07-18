# Changelog — Einsatzdoku

Format nach [Keep a Changelog](https://keepachangelog.com/de/); Versionen
entsprechen den ehemaligen Spezifikations-Ständen. Neue Einträge kommen bei
jedem Änderungspaket oben dazu.

## [Unveröffentlicht]

### Geändert
- Reanimation: Display bleibt während laufender Rea dauerhaft hell;
  Rea-Start vibriert 2×, Zyklusende 5× (statt 2×), Ereignis-Bestätigung
  kräftiger.
- Long-Press-Aktionen (Menüs, Adrenalin, Rhythmuskontrolle) feuern nach 1 s
  Halten sofort — nicht mehr erst beim Loslassen.
- **Einsatz-Abschluss statt Phase 10:** Nach Phase 9 „Einsatzende" bleibt der
  Einsatz offen; kurz START (oder grüner Menüpunkt) fragt „Einsatz beenden &
  senden?" — erst dann wird geschlossen und hochgeladen. Einsatzende/Dauer =
  Zeit der Phase 9. Migration löscht alte Phase-10-Zeitstempel und korrigiert
  Einsatzenden; Ingest und Formular akzeptieren nur noch Phasen 2–9.
- Uhr-Schnellmenü farbcodiert mit Endlos-Scrollen: Phasen 2–9,
  Einsatzübersicht (gelb), Einsatz abschließen (grün), Einsatztag beenden
  (rot); kurze Phasennamen auf der Uhr (Landung KKH, Übergabe, Einsatzende).
- Rea-Menü: neue Reihenfolge mit Rhythmuskontrolle (gelb, inkl.
  Countdown-Reset) und Adrenalin (pink) als Menüpunkte, „ENDE" statt „Rea
  beenden"; Direktkürzel lang UP/DOWN bleiben. „REA läuft" zusätzlich auf der
  Tempo-Seite.
- Server-URL in den Uhr-Einstellungen tolerant: „luftrettung.net" genügt.
- Uhr: Uhrzeit auf der Hauptanzeige deutlich größer, Phasenanzeige kleiner;
  Rea-Gesamtdauer größer; Kartenseite mit interaktivem Modus (kurz START =
  Garmins Zoom/Verschieben, BACK zurück zur Vorschau).
- Kosmetik-Paket Web: Einsatzformular mittig; Notizfeld im Eingabefeld-Stil;
  „Bearbeiten" als Button; Feldliste vertikal zentriert; „+ Phase hinzufügen"
  fokussiert das neue Dropdown; Fußzeile mit © Gen-EM und AGPL-3.0 auf allen
  Seiten.

### Behoben
- Navigation: Auf der Geräte-Seite tauschten „Geräte" und „Verwaltung" beim
  Klick die Plätze (abweichende Link-Reihenfolge).
- Migration „Mehrere Reanimationen": Ersatzindex vor dem Entfernen des
  UNIQUE (MySQL 1553); Runner überspringt bereits erledigte Einzelschritte.

## [1.2] — 2026-07-18

### Hinzugefügt
- **Geräte-Selbstverwaltung** („Geräte"-Seite): NutzerInnen legen eigene Uhren
  an (Schlüssel einmalig sichtbar) und (de)aktivieren sie selbst.
- **Manuelle Einsätze:** Formular für Nachtragen und Bearbeiten
  (`einsatz_form.php`) mit dynamischen Phasenzeilen, Mitternachts-Logik und
  Zusatzfeldern; „+ Einsatz nachtragen" in der Tagesübersicht, „Bearbeiten"
  und „manuell"-Badge in der Einsatzübersicht.
- **Erweiterbare Zusatzfelder** über zentrale Definition
  (`mission_fields.php`); Startbestand: Einsatznummer, Notizen.
- Virtuelles, dauerhaft deaktiviertes Gerät „Manuelle Einträge" je NutzerIn
  als Träger von Handeinträgen.

### Geändert
- **Geräte werden deaktiviert statt gelöscht** — Upload-Schlüssel sofort
  gesperrt, alle Daten bleiben, Reaktivierung möglich; Löschen aus der
  Oberfläche entfernt. Ingest antwortet deaktivierten Geräten mit `403`.
- Manuell bearbeitete Einsätze sind vor Überschreiben durch Uhr-Uploads
  geschützt (`manual`-Marker); GPS-Punkte werden weiterhin ergänzt.
- Dokumentation neu strukturiert: Handbuch / Technik / Changelog;
  Anforderungskatalog als `archiv/Anforderungen_v1.2.md` eingefroren.

### Behoben
- **Datenverlust-Bug** in der Track-Persistenz: Teil-Chunks wurden nach einem
  Neustart mitten im Einsatz vom nächsten vollen Chunk überschrieben;
  zusätzlich konnten Upload-Lesezugriffe Punkte übersehen. Chunk-Ausrichtung
  jetzt garantiert, Tail-Lesen eindeutig.
- Reanimations-Timer überlebt App-/Uhren-Neustart (persistierter Zustand,
  epochenbasierte Fortsetzung).
- Einsatz-Kilometer werden bei Einsatzende eingefroren — verzögerte Uploads
  erhalten nicht mehr die Werte des Folgeeinsatzes.
- Ingest validiert `seq_from ≥ 0`.

## [1.1] — 2026-07-17

### Hinzugefügt
- **Tempo-Oberfläche** (aktuelle km/h + Einsatzdistanz), Seitenreihenfolge
  Uhr → Karte → Tempo → Rea.
- **Mehrere Reanimationen pro Einsatz:** „Rea beenden" (rot, mit Bestätigung)
  schließt eine Sitzung, erneuter START eröffnet die nächste; im Web je
  Sitzung eine eigene Tabelle. JSON-Vertrag v1.1 (`resus_sessions`).
- **Flugtag-Daten** in der Tagesübersicht: editierbare Felder Maschine,
  Basis/Standort, Besatzung, Notizen; Verknüpfung über (user_id, Datum).
- **Automatischer Aufräumjob** (max. 1×/Tag, ohne Cron): Trackpunkt-Waisen und
  alte Reset-Tokens.
- Web-Installer (`install.php`) mit Selbst-Sperre; Migrations-Runner
  (`update.php`) mit Buchführung; FTPS-Deploy per GitHub Actions;
  `.gitignore`.
- **GenEM-Branding**: Farbwelt, Bricolage Grotesque/Open Sans, Logo in
  Kopfleiste und Login, Favicon; Uhr-Launcher-Icon aus der Bildmarke.

### Geändert
- Schnellmenü der Hauptanzeige auf **lang START** verlegt (vorher lang UP).
- Rea-Untermenü selbst gezeichnet: farbcodierte Kacheln, Endlos-Scrollen,
  exakt zentrierte Beschriftung; Gesamt-Rea-Zeit lila; Bedien-Hinweistexte
  entfernt.
- Referrer-Policy auf `strict-origin-when-cross-origin` (OSM-Kacheln luden
  nicht).
- Lösch-Schutz der Uhr räumt Bestätigungsmarken mit auf.

### Behoben
- Monkey-C-Erstübersetzung: Modul-Callbacks über Träger-Klassen,
  `makeWebRequest`-Signatur, MapView-Pflichtaufrufe
  (`setScreenVisibleArea`, initiale Kartenfläche, keine Null-Flächen).

## [1.0] — 2026-07-16

### Hinzugefügt
- Erstes Gesamtsystem nach eingefrorener Spezifikation v1.0:
  - **Uhr-App** (Fenix 6 Pro): Dienst-Klammer („Dienst beginnen" /
    „Einsatztag beenden"), 10 Einsatzphasen mit Zeitstempeln und Position,
    Schnellmenü, Karten-Oberfläche mit Einsatz-Track (Anzeige-Cap 1000,
    Dichte-Halbierung), Reanimationsmodus (2:00-Zyklus, Vibration,
    Ereignis-Zeitstempel), GPS-Ausdünnung 15 m/10 s/max 1 s,
    Flash-Persistenz in Chunks, Offline-Puffer mit bestätigtem Löschen.
  - **JSON-Vertrag v1.0**: idempotente, inkrementelle Uploads
    (`client_ref`, `seq_from`/`next_seq`, 500-Punkte-Chunks).
  - **Web-App**: Login/Reset per Mail (eigener SMTPS-Client), Admin-Bereich
    (NutzerInnen, Geräte mit einmalig sichtbarem Schlüssel), Tagesübersicht
    mit Leaflet-Karte (Einsätze farbig, Ruhe-Track schwarz, Auto-Zoom ~75 %),
    Einsatzübersicht mit Phasen- und Rea-Tabelle.
