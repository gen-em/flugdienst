# Einsatzdoku — Technische Dokumentation

*Stand: 18.07.2026 · Bedienung: `Handbuch.md` · Schnittstelle: `JSON-Vertrag.md` ·
Historie: `CHANGELOG.md` · Ursprüngliche Spezifikation: `archiv/Anforderungen_v1.2.md`.*

## 1. Architekturüberblick

```
┌─────────────────┐  HTTPS POST /ingest.php   ┌──────────────────────────┐
│ Garmin Fenix 6  │  JSON (Vertrag v1.1)      │  Webspace                │
│ Connect-IQ-App  │ ────────────────────────► │  PHP ≥ 8.1  + MySQL      │
│ (Monkey C)      │  X-Device-Id / X-Api-Key  │                          │
└─────────────────┘                           │  ingest.php   (Uhr-API)  │
        weitere Quellen (geräteneutral) ────► │  api/…        (Lese-API) │
                                              │  *.php        (Seiten)   │
┌─────────────────┐  HTTPS (Session-Login)    │  update.php   (Migration)│
│ Browser         │ ────────────────────────► │  install.php  (Setup)    │
└─────────────────┘                           └──────────────────────────┘
```

Grundsätze: Der Server ist **geräteneutral** (kennt nur den JSON-Vertrag);
Zeiten werden **UTC** gespeichert und in **Europe/Berlin** angezeigt; jede
Lese- und Schreiboperation ist nach `user_id` getrennt; die Uhr löscht lokale
Daten erst nach Server-Bestätigung.

## 2. Verzeichnisstruktur

```
hems/
├── docs/                  Handbuch, Technik, Changelog, JSON-Vertrag, Archiv
├── server/                komplette Web-App (wird per FTPS deployt)
│   ├── ingest.php         Uhr-/Fremdquellen-Endpunkt (Auth, Idempotenz)
│   ├── index.php          Tagesübersicht  · einsatz.php  Einsatzansicht
│   ├── einsatz_form.php   Nachtragen/Bearbeiten · mission_fields.php Felddefinition
│   ├── geraete.php        Geräte-Selbstverwaltung · admin.php Verwaltung
│   ├── login/logout/reset_request/reset_confirm.php   Auth-Flows
│   ├── install.php        Ersteinrichtung · update.php Migrations-Runner
│   ├── db.php             PDO, Helfer, Labels, Aufräumjob
│   ├── auth_guard.php     Session/CSRF/Rollen · smtp.php SMTPS-Versand
│   ├── api/day.php        Tage + Tagesdaten (GET), Flugtag-Meta (POST)
│   ├── api/mission.php    Einzeleinsatz inkl. Feldern/Rea-Sitzungen
│   ├── assets/            style.css, Logos, Favicon
│   ├── migrations/        (nur Doku; produktiv zählt update.php)
│   └── schema.sql         Voll-Schema für Neuinstallationen
├── watch/                 Connect-IQ-Projekt (Monkey C)
│   ├── manifest.xml, monkey.jungle, resources/
│   └── source/            s. Abschnitt 5
└── .github/workflows/deploy.yml   FTPS-Deploy (nur server/, exkl. config)
```

## 3. Datenmodell (MySQL)

| Tabelle | Zweck / Besonderheiten |
|---|---|
| `users` | Login (E-Mail = Username), Rolle `user`/`admin`; Löschen kaskadiert alles |
| `password_resets` | Token-Hashes (sha256), 1 h gültig; Aufräumjob entsorgt Altbestand |
| `devices` | Upload-Zugang je Gerät: `device_id` (öffentlich) + `api_key_hash`; **`active`-Flag** (deaktivieren statt löschen); virtuelle Geräte `manual-<userId>` für Handeinträge (dauerhaft inaktiv, aus Listen gefiltert) |
| `missions` | Einsatz; `UNIQUE(device_id, client_ref)` = Idempotenz-Anker; `day` = Flugtag; **`manual`-Marker** (Schutz vor Uhr-Überschreiben); Zusatzfelder lt. `mission_fields.php` (derzeit `mission_no`, `notes`) |
| `mission_phases` | Phasen-Zeitstempel (2–10, Mehrfach-Einträge erlaubt) inkl. Position |
| `resus_sessions` / `resus_events` | Reanimationen: **mehrere Sitzungen je Einsatz**, Ereignisse typisiert |
| `rest_segments` | Ruhe-Track-Segmente (gleiches Idempotenz-Schema wie Einsätze) |
| `track_points` | GPS-Punkte für Einsätze **und** Segmente; PK `(owner_type, owner_id, seq)`; bewusst ohne FK (polymorph) → Aufräumjob entfernt Waisen |
| `days` | Flugtag-Metadaten; **Verknüpfung über natürlichen Schlüssel `(user_id, day)`**, entsteht lazy beim ersten Speichern |
| `app_state` | Schlüssel/Wert (z. B. `last_cleanup`) |
| `schema_migrations` | Buchführung des Migrations-Runners |

Skalierung: ~2.000–2.500 Punkte je Einsatz; Indizes `(user_id, day)` und der
Punkte-PK tragen das auf Jahre problemlos (~1 Mio. Punkte/Jahr).

## 4. Zentrale Abläufe

**Upload & Idempotenz** (Details: `JSON-Vertrag.md`): Die Uhr sendet je
Einsatz/Segment eine `client_ref` und Punkte ab `seq_from`; der Server
antwortet mit `next_seq` (erste noch fehlende Sequenz). Wiederholungen sind
unschädlich (`INSERT IGNORE` auf den Punkte-PK, Upsert auf `client_ref`).
Phasen/Rea werden je Upload **vollständig ersetzt** (kein Delta). Die Uhr darf
lokal erst löschen, wenn `final` bestätigt und `next_seq` = Punktzahl.

**Schutz manueller Einsätze:** Beim Ingest wird vor dem Upsert der
`manual`-Marker geprüft. Ist er gesetzt, werden Metadaten/Phasen/Rea **nicht**
angefasst; Trackpunkte laufen weiter ein (append-only). Gesetzt wird der
Marker beim Speichern im Bearbeitungsformular bzw. bei Handanlage.

**Zeitbehandlung:** Speicherung UTC (`DATETIME`), Anzeige über `fmt_local()`
(Europe/Berlin). Das Formular rechnet lokale Eingaben nach UTC um; Zeiten
„nach Mitternacht" (kleiner als die vorherige) erhalten +1 Tag.

**Aufräumjob:** `run_cleanup_if_due()` (db.php) läuft max. 1×/Tag, huckepack
auf `auth_guard.php` (Web) und `ingest.php` (Uhr) — kein Cron nötig. Marke
`last_cleanup` wird *vor* dem Lauf gesetzt (verhindert Parallel-Läufe);
entsorgt Trackpunkt-Waisen und alte Reset-Tokens; scheitert grundsätzlich
still (Wartung darf keine Anfrage brechen).

**Sicherheit:** HTTPS erzwungen (.htaccess), Session-Cookies
HttpOnly/Secure/SameSite=Strict, CSRF für Formulare (`csrf_field`) und
JSON-POSTs (Header `X-CSRF`), PDO Prepared Statements durchgängig,
Passwörter/Schlüssel nur als Hash, Bruteforce-Bremse am Login, Ingest mit
Größen- (512 KB) und Wertevalidierung, sensible Dateien per .htaccess
gesperrt, Referrer-Policy `strict-origin-when-cross-origin` (OSM-Kacheln).

## 5. Uhr-App (Monkey C) — Modulstruktur

| Datei | Verantwortung |
|---|---|
| `HemsApp.mc` | Einstieg; Restore-Kette bei Neustart (Model → Track → Cpr → Sync) |
| `Model.mc` | Dienst-Klammer, Phasenlogik, Einsatz-/Segment-Lebenszyklus, Rea-Sitzungen, Persistenz (`state`) |
| `Track.mc` | GPS (15 m/10 s/1 s-Ausdünnung), Distanz/Anstieg, Anzeige-Polylinie (Cap 1000, Dichte-Halbierung), **Flash-Chunks à 200 Punkte**; `restore()` lädt Teil-Chunks zurück in den Puffer (Chunk-Ausrichtung, verlustfrei) |
| `Cpr.mc` | Rea-Timer app-weit (1-s-Tick), 2:00-Zyklus, Ereignisse, **persistenter Zustand** (übersteht Neustart) |
| `Uploader.mc` | Job-Queue (fertige Einsätze → Segmente → aktive), Chunking ≤ 500, `next_seq`-Bestätigung, Purge inkl. Marken |
| `Nav.mc` | Pager Uhr → Karte → Tempo → Rea |
| `StartView/ClockView/MapPage/SpeedView/CprView.mc` | Oberflächen + Delegates; lange Tastendrücke manuell via `onKeyPressed/Released` (800 ms, `Const.LONG_PRESS_MS`) |
| `Const.mc` / `Util.mc` | Labels, Tuning-Werte; ISO-UTC, lokale Anzeige, Vibration |

Rückruf-Muster: `method()` existiert nur auf Objekten → kleine
Träger-Klassen (`TrackCb`, `CprCb`, `UploaderCb`) reichen Callbacks an die
Module weiter.

**Build:** VS Code + Monkey-C-Erweiterung + Connect-IQ-SDK + JDK;
Entwickler-Schlüssel via „Generate a Developer Key". Ziel `fenix6pro`,
Debug-Build; Sideload: `.prg` nach `GARMIN/Apps/`. Einstellungen
(Server-URL/Geräte-ID/Schlüssel) über Connect-IQ-Settings am Handy.

## 6. Deployment

Push auf `main` mit Änderungen unter `server/` → GitHub Action
(`.github/workflows/deploy.yml`) lädt per **FTPS** hoch. Ausgenommen:
`config.php`, `install.lock` (existieren nur auf dem Server). Secrets:
`FTP_SERVER` (nackter Hostname!), `FTP_USERNAME`, `FTP_PASSWORD`.
`.gitignore` hält `watch/bin/`, `*.prg` und `config.php` aus dem Repo.

## 7. Betrieb (Runbook)

**Gerät verloren / Schlüssel kompromittiert:** Web → „Geräte" (oder
Verwaltung) → **Deaktivieren**. Wirkt sofort (Ingest antwortet `403`);
Daten bleiben. Neue Uhr = neues Gerät anlegen.

**Code-Update mit DB-Änderung ausrollen:** pushen (Deploy läuft automatisch)
→ als Admin **`/update.php`** aufrufen → alle Zeilen müssen ✔ zeigen.
Fehlgeschlagene Migrationen werden nicht verbucht und beim nächsten Aufruf
erneut versucht; Folge-Migrationen stoppen bis dahin.

**Neue Zusatzfelder für Einsätze:** 1) Migration in `update.php` ergänzen
(`ALTER TABLE missions ADD COLUMN …`), 2) Eintrag in `mission_fields.php`.
Formular, Speichern, API und Anzeige übernehmen automatisch.

**Backup:** regelmäßiger MySQL-Dump (alle Tabellen; `mysqldump` oder
Hoster-Backup). Wiederherstellung: Dump einspielen; `config.php` bleibt
unberührt. Die Uhr sendet nach einer Wiederherstellung fehlende jüngste
Daten idempotent nach, sofern lokal noch vorhanden.

**Neuinstallation:** leere DB + `server/` hochladen → `index.php` leitet zum
Installer; nach Erfolg sperrt `install.lock`; `install.php` danach löschen.

**Deploy schlägt fehl:** Actions-Log lesen. `ENOTFOUND` = `FTP_SERVER`-Secret
prüfen (nur Hostname, kein Schema/Pfad). Auth-Fehler = Zugangsdaten;
SFTP-only-Hoster brauchen einen anderen Workflow.

**Karte zeigt „Access blocked":** Referrer-Policy prüfen
(muss `strict-origin-when-cross-origin` sein), Hard-Reload.

**Diagnose Uhr lädt nicht hoch:** Web „Geräte" → „Zuletzt gesehen"; Gerät
aktiv? Connect-IQ-Einstellungen (URL exakt mit `/ingest.php`, ID, Schlüssel)?
Uhr online (Handy-Kopplung/WLAN)? Anzeige „Sync ausstehend" verschwindet nach
erfolgreichem Upload.

## 8. Backlog (bewusst offen)

1. Reanimations-Zeiten im Nachtrage-/Bearbeitungsformular
2. Serverseitige Track-Vereinfachung (Douglas-Peucker) für die Web-Darstellung
3. GPX-Export (Datenmodell dafür vorbereitet: lat/lon/ele/ts je `seq`)
4. Geteilte Flugtage (Crew-weit statt je NutzerIn)
5. Geräte-Limit pro NutzerIn
6. Weitere Zielgeräte (Fenix 7/8, Touch-Bedienung)
7. Kosmetik Uhr-Code: Typprüfer-Warnungen („container access") auflösen
