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
│   ├── einstellungen.php  Profil/Geräte/Standortdaten/Backup · admin.php + admin_user.php Verwaltung
│   ├── einsatz_loeschen.php · flugtag_loeschen.php · papierkorb.php  Löschen mit Vorschau
│   ├── api/               day.php · mission.php · backup_data.php · backup_restore.php
│   ├── einrichtung.php    E2E-Ersteinrichtung (Wiederherstellungsschlüssel) & Entsperren
│   ├── pair.php           Uhr-Kopplung per Code · geraete.php Geräte (Altseite)
│   ├── backup_lib.php     Backup-Serialisierung · trash_lib.php Papierkorb-Logik
│   ├── login/logout/reset_request/reset_confirm.php   Auth-Flows · auth_salt.php KDF-Salt
│   ├── install.php        Serverinstallation · update.php Migrations-Runner
│   ├── db.php             PDO, Helfer, Labels, Aufräumjob · ui.php Kopf-/Seitenleisten
│   ├── auth_guard.php     Session/CSRF/Rollen, erzwungene E2E-Einrichtung · smtp.php SMTPS
│   ├── api/day.php        Tage + Tagesdaten (GET), Flugtag-Meta (POST)
│   ├── api/mission.php    Einzeleinsatz inkl. Feldern/Rea-Sitzungen
│   ├── assets/            style.css, crypto.js (WebCrypto-Helfer), Logos, Favicon
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
| `users` | Login (E-Mail = Username), Rolle `user`/`admin`; Löschen kaskadiert alles; **Browser-Schlüsselableitung** (`kdf_salt`, `kdf_ver`) und **E2E-Schlüssel-Hüllen** `pat_wrap_pw`/`pat_wrap_rc` (Inhaltsschlüssel passwort- bzw. wiederherstellungsverpackt) |
| `password_resets` | Token-Hashes (sha256), 1 h gültig; Aufräumjob entsorgt Altbestand |
| `devices` | Upload-Zugang je Gerät: `device_id` (öffentlich) + `api_key_hash`; **`active`-Flag** (deaktivieren statt löschen); virtuelle Geräte `manual-<userId>` für Handeinträge (dauerhaft inaktiv, aus Listen gefiltert) |
| `missions` | Einsatz; `UNIQUE(device_id, client_ref)` = Idempotenz-Anker; `day` = Flugtag; **`manual`-Marker** (Schutz vor Uhr-Überschreiben); `deleted_at`/`deleted_with_day` (Papierkorb); Zusatzfelder lt. `mission_fields.php`; **`pat_blob`** = E2E-Chiffretext (Diagnose, Alter, Einsatzort — Klartext-Ortsspalten existieren seit der Pflicht-Migration nicht mehr) |
| `mission_phases` | Phasen-Zeitstempel (2–10, Mehrfach-Einträge erlaubt) inkl. Position |
| `resus_sessions` / `resus_events` | Reanimationen: **mehrere Sitzungen je Einsatz**, Ereignisse typisiert |
| `rest_segments` | Ruhe-Track-Segmente (gleiches Idempotenz-Schema wie Einsätze) |
| `track_points` | GPS-Punkte für Einsätze **und** Segmente; PK `(owner_type, owner_id, seq)`; bewusst ohne FK (polymorph) → Aufräumjob entfernt Waisen |
| `days` | Flugtag-Metadaten; **Verknüpfung über natürlichen Schlüssel `(user_id, day)`**, entsteht lazy beim ersten Speichern |
| `pair_codes` | Kopplungscodes für die Uhr (5 Zeichen, 60 min, einmalig; Aufräumjob) |
| `deleted_refs` | Sperrliste gelöschter `client_ref`s (90 Tage) gegen Wieder-Upload durch die Uhr |
| `app_state` | Schlüssel/Wert (z. B. `last_cleanup`, `salt_secret`) |
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

**Ende-zu-Ende-Verschlüsselung (Pflicht):** Beim Login leitet der Browser per
PBKDF2-SHA256 (310 000 Runden) aus Passwort + `kdf_salt` zwei Werte ab: ein
Auth-Token (ersetzt das Passwort gegenüber dem Server) und einen Datenschlüssel
(bleibt im Browser, `sessionStorage`). Ein zufälliger **Inhaltsschlüssel**
verschlüsselt `pat_blob` (`{dx, age, loc:{addr,lat,lon}}`, AES-GCM) und liegt
doppelt verpackt in `users`: mit dem Datenschlüssel (`pat_wrap_pw`) und mit dem
aus dem Wiederherstellungsschlüssel abgeleiteten Schlüssel (`pat_wrap_rc`).
`auth_guard.php` erzwingt die Ersteinrichtung (einrichtung.php), solange die
Hüllen fehlen; dieselbe Seite entsperrt nach einem Passwort-Reset per
Wiederherstellungsschlüssel. Passwort-Ändern re-wrappt clientseitig; eine
Admin-Passwortvergabe existiert bewusst nicht. Alt-Konten (kdf_ver 0) werden
beim ersten Login transparent migriert; `auth_salt.php` liefert Salts (mit
deterministischem Fake für unbekannte Adressen gegen User-Enumeration).

**Backup (portabel, Format 2):** `api/backup_data.php` liefert alle Daten der
NutzerIn als Roh-JSON (geschützte Angaben weiterhin als Chiffretext). Der
Browser entschlüsselt sie mit dem Inhaltsschlüssel, ersetzt sie durch Klartext
und versiegelt das Ganze per `EdCrypto.sealBackup()` (AES-256-GCM, PBKDF2
310 000, gzip via CompressionStream) zur `.edbak`-Datei. Beim Import öffnet der
Browser die Datei, verschlüsselt die Angaben mit dem Schlüssel des **Zielkontos**
neu und schickt sie an `api/backup_restore.php` → `edbak_restore()`. Dadurch
sind Backups zwischen Konten übertragbar; der Server sieht nie Klartext.
Der Import prüft die Dateikennung und lehnt Fremddateien ab; einen
serverseitigen Import gibt es nicht mehr. Aufbau: `docs/Backup-Format.md`.

**Bestätigungen:** `assets/confirm.js` fängt Formulare und Links mit
`data-confirm` ab und zeigt ein `<dialog>` im Seiteninhalt statt
`window.confirm()` — native Dialoge lassen sich pro Seite dauerhaft
unterdrücken, was die Rückfrage wirkungslos machen würde. Eingebunden über
`ui_footer()`. Sicherheitskritische Löschungen hängen ohnehin nicht daran,
sondern an den serverseitigen Zwischenseiten.

**Einsatztage-Leiste:** `ui_days_sidebar()` gruppiert die Tage serverseitig
nach Jahr und Monat (`<details>`-Verschachtelung); welches Jahr/welcher Monat
offen ist, bestimmt PHP anhand von `$currentDay` bzw. des jüngsten Tages —
kein JavaScript nötig, da jede Navigation ohnehin einen Seitenaufruf auslöst.
`assets/daylist.js` erzwingt nur das Akkordeon-Verhalten (ein offenes Element
je Ebene) für Klicks ohne Seitenwechsel.

**Papierkorb (Soft-Delete):** Einsätze, Ruhesegmente und Flugtage tragen
`deleted_at`; alle Lesepfade (Übersicht, Tages-/Einsatz-API, Tagesliste,
Backup) filtern darauf. `trash_lib.php` bündelt Umfangsermittlung, weiches
Löschen, Wiederherstellen und endgültiges Entfernen; der Aufräumjob in `db.php`
räumt nach `TRASH_DAYS` (30) endgültig ab. Beim Löschen eines Flugtags werden
dessen Einsätze/Segmente mit `deleted_with_day = 1` markiert — sie hängen am
Tag und kehren mit ihm zurück. `ingest.php` quittiert Uploads für Einträge im
Papierkorb, verwirft sie aber; erst das endgültige Löschen schreibt die
Referenz nach `deleted_refs`. Schwere Löschungen laufen über serverseitige
Zwischenseiten mit Umfangsanzeige statt über Browser-Dialoge.

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
| `Nav.mc` | Pager Uhr → Karte → Tempo → Statistik → Sync → Rea |
| `StartView/ClockView/SpeedView/StatsView/SyncView/CprView.mc` | Oberflächen + Delegates; lange Tastendrücke manuell via `onKeyPressed/Released` (800 ms, `Const.LONG_PRESS_MS`) |
| `SyncView.mc` | Sync-Status (Backlog = nur abgeschlossene Pakete), App-Version, Kopplung per START-Halten |
| `Pair.mc` | Kopplungscode-Eingabe → tauscht Code gegen Geräte-Zugang (`Storage 'cred'`) |
| `Const.mc` / `Util.mc` | Labels, Tuning-Werte; ISO-UTC, lokale Anzeige, Vibration |

Rückruf-Muster: `method()` existiert nur auf Objekten → kleine
Träger-Klassen (`TrackCb`, `CprCb`, `UploaderCb`) reichen Callbacks an die
Module weiter.

**Build:** VS Code + Monkey-C-Erweiterung + Connect-IQ-SDK + JDK;
Entwickler-Schlüssel via „Generate a Developer Key". Ziel `fenix6pro`,
Debug-Build; Sideload: `.prg` nach `GARMIN/Apps/`. In `properties.xml` steht
nur die Server-Domain; die Zugangsdaten holt sich die Uhr selbst über die
**Kopplung per Code** (Web: Einstellungen → Geräte; Uhr: Sync-Seite → START
halten). `Const.APP_VERSION` bei Releases mitziehen (Anzeige Sync-Seite).

**Dienstende (Uhr):** „Einsatztag beenden" schließt Rea und Dienst, setzt den
Arbeitszustand zurück (Zähler, Phase, Tag) und beendet die App per
`System.exit()`; die Upload-Warteschlange bleibt erhalten. Der Wechsel zur
Sende-Ansicht läuft verzögert (Modul `EndDay`), weil ein direkter
`switchToView()` aus `ConfirmationDelegate.onResponse()` von der sich
schließenden Bestätigung wieder entfernt würde.

**Versionen:** Weboberfläche und Uhr zählen getrennt
(`server/version.php` bzw. `watch/source/Const.mc`). `asset()` in `db.php`
hängt `?v=<Version>` an jede Stylesheet- und Skript-Adresse; nach dem Erhöhen
der Nummer lädt der Browser die Dateien von selbst neu. Beim Ausliefern also
**immer die Version erhöhen** — sonst greift die Zwischenspeicher-Umgehung nicht.

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
