> **ARCHIVIERT** — Dieses Dokument war die Bau-Spezifikation (letzte Fassung v1.2, 18.07.2026) und wird nicht mehr gepflegt. Aktuelle Dokumentation: `Handbuch.md`, `Technik.md`, `CHANGELOG.md`.

# Anforderungsdefinition — HEMS Einsatz- & Reanimationsdoku

**Version:** 1.2
**Stand:** 18.07.2026 (v1.0 eingefroren am 16.07.2026; Änderungslisten in Abschnitt 5)
**Komponenten:** Garmin-Uhr-App (Connect IQ / Monkey C) + Web-App (PHP/MySQL)
**Produktivsystem:** luftrettung.net (FTP-Deploy via GitHub Actions)

---

## 0. Rahmen & Plattform

- **Zielgerät:** Garmin Fenix 6 Pro (primär). Codebasis so strukturiert, dass Fenix 7/8 später ergänzt werden können. Eine Codebasis, mehrere Zielgeräte.
- **Schnittstelle geräteneutral:** Der Server kennt nur den JSON-Vertrag — beliebige weitere Quellen (andere Uhren, Handy-Apps, Skripte) können mit eigenen Geräte-Zugangsdaten Daten einspielen.
- **App-Typ:** Vollwertige Device-App, dauerhaft laufende Vordergrund-App. **Sprache:** Deutsch. **Begriff:** Einsatzzustände heißen durchgängig **Phase**.
- **Branding:** GenEM (Brand Guidelines V03): Schnee/Rauch/Sand/Asphalt, Dunkelblau `#1A2E4D`, Philipp Orange `#FF8F1F` als Akzent, Max Blau `#4280E5`, Newroz Rot `#D63338`; Bricolage Grotesque + Open Sans; Logo mit Vogel-Bildmarke, helle Variante auf Dunkelblau, Favicon aus der Bildmarke.

### 0.1 Datenschutz-Hinweise (nicht-technisch, aber verbindlich)

- Einsatzzeiten und -orte sind schon für sich sensibel.
- Sobald patientenbezogene Angaben hinzukämen: Gesundheitsdaten nach **Art. 9 DSGVO** (Freitextfelder tragen den Hinweis „keine Patientendaten").
- Dienstliche Einsätze: Verarbeitung auf privatem Gerät/Server **vorab mit der Organisation klären**.
- EU-Hosting; HTTPS erzwungen; Passwörter und Geräteschlüssel nur als Hash.

---

## 1. Uhr-App

### 1.1 App-Start & Betriebstag-Klammer

- Startbildschirm **„Dienst beginnen"**; erst **kurz START** aktiviert Anzeige und Aufzeichnung.
- **Betriebstag (Flugtag)** geklammert durch Dienstbeginn und **„Einsatztag beenden"** (mit Bestätigung). Datum = Datum des Dienstbeginns. 06:00-Heuristik endgültig gestrichen.
- **Neustart-Robustheit:** App-/Uhren-Neustart mitten im Dienst stellt Phase, Track (chunk-ausgerichtet, verlustfrei) und eine **laufende Reanimation** (beide Timer, epochenbasiert) nahtlos wieder her.

### 1.2 Oberfläche 1 — Standard / Hauptanzeige

**Daueranzeige:** Uhrzeit; Phase (Zahl + Bezeichnung); Statuszeile (laufende Rea / ausstehender Sync).

**Phasen per kurz START** (UTC-Zeitstempel + Position):

| Phase | Bezeichnung | Bedeutung |
|------:|-------------|-----------|
| 1 | Frei | Startzustand |
| 2 | Alarmierung | **Einsatzbeginn** (Ruhe-Track stoppt, Einsatz-Track startet) |
| 3 | Abflug | |
| 4 | Ankunft Einsatzort | |
| 5 | Ankunft PatientIn | |
| 6 | Transportbeginn | |
| 7 | Landung Krankenhaus | |
| 8 | Übergabezeit | |
| 9 | Endzeit des Einsatzes | |
| 10 | Beendigung Einsatz | **Einsatzende** → Phase 1, Upload, neuer Ruhe-Track |

**Lang START → Schnellmenü:** Phasen-Schnellauswahl (erneutes Setzen = zusätzlicher Zeitstempel), „Einsatzübersicht Zeiten", „Einsatztag beenden" (Bestätigung). BACK fragt vor App-Verlassen nach.

### 1.3 Oberfläche 2 — Karte

- Position + Einsatz-Track (eine Polylinie). Ruhe-Track wird als Segmente aufgezeichnet, auf der Uhr nicht dargestellt.
- Anzeige-Cap ~1.000 Punkte mit Dichte-Halbierung (ganzer Track bleibt sichtbar).

### 1.4 Oberfläche 3 — Tempo

- Aktuelle Geschwindigkeit groß (km/h, aus GPS, auch zwischen gespeicherten Punkten aktuell); darunter Einsatzdistanz in km (orange) bzw. „kein Einsatz".

### 1.5 Oberfläche 4 — Reanimation

- **Kleiner Timer (vorwärts, lila):** Gesamtdauer. **Großer 2:00-Countdown:** vibriert bei 0:00 zweimal kurz, bleibt stehen (rot); Neustart per Rhythmuskontrolle oder kurz START. Keine Bedien-Hinweistexte.
- **Tasten:** kurz UP/DOWN Navigation · kurz START Beginn/Countdown-Neustart · lang UP Adrenalin · lang DOWN Rhythmuskontrolle (+Reset) · lang START Untermenü · BACK verlässt.
- **Untermenü:** selbst gezeichnet, farbcodiert, Endlos-Scrollen: Defibrillation (bernstein), Intubation (blau), Amiodaron (violett), Sonographie (türkis), ROSC (grün), Tod (grau), Übersicht (weiß), **„Rea beenden" (rot, Bestätigung)**.
- **Mehrere Reanimationen pro Einsatz:** „Rea beenden" schließt die Sitzung, erneuter START eröffnet eine neue; Einsatzende/„Einsatztag beenden" schließen automatisch. Timer läuft app-weit weiter und übersteht Neustarts. Phasen- und Rea-Zeitstempel nie gemeinsam angezeigt.

### 1.6 Aufzeichnung & GPS-Ausdünnung

- UTC intern, Anzeige Europe/Berlin (lokale Anzeigezeit wird beim Setzen mitgespeichert).
- Punkt bei ≥ 15 m oder ≥ 10 s, max. 1×/s; Flash-Chunks à 200 Punkte; Einsatz-km = Einsatz-Track (werden bei Einsatzende **eingefroren**, damit verzögerte Uploads korrekt bleiben); Startwerte justierbar.

### 1.7 Datensicherheit auf der Uhr

- **Lösch-Schutz:** Entfernen lokaler Daten erst nach Server-Bestätigung aller Punkte (`next_seq`) bei `final`-Markierung; Bestätigungsmarken werden mit aufgeräumt.
- Offline: Puffern im Flash, vollständiges Nachsenden; Backoff bei Fehlern. Deaktivierte Geräte erhalten `403` und zeigen „Sync ausstehend".

---

## 2. Kommunikation → Server (geräteneutral)

- **POST** JSON an `ingest.php`; Auth per **`X-Device-Id`** / **`X-Api-Key`** (Hash serverseitig). Jede Quelle mit gültigem Schlüsselpaar kann einspielen — Garmin-spezifisch ist nichts.
- Upload: Einsatz bei Phase 10; Ruhe-Track stündlich/bei Verbindung; Rest bei Dienstende. Inkrementell & idempotent (`client_ref`, `seq_from`/`next_seq`, max. 500 Punkte/Chunk, 512-KB-Limit, `seq_from ≥ 0` validiert).
- **JSON-Vertrag v1.1:** Reanimationen als Liste `resus_sessions`. Tracks optional (Einsätze ohne GPS werden korrekt angezeigt).
- **Schutz manuell bearbeiteter Einsätze:** Trägt ein Einsatz den Marker `manual`, überschreiben Uhr-Uploads Metadaten/Phasen/Rea nicht mehr; Trackpunkte werden weiterhin ergänzt (append-only).

---

## 3. Web-App

### 3.1 Login / Nutzer- & Geräteverwaltung

- **Admin** legt NutzerInnen an/löscht sie (Anlegen verschickt Passwort-Setz-Link per SMTP/Stalwart). Username = E-Mail; Reset-Token 1 h; nur Hashes; Bruteforce-Bremse; Loginseite mit GenEM-Logo.
- **Geräte-Selbstverwaltung („Geräte"-Seite, alle NutzerInnen):** eigene Geräte anlegen (Geräte-ID + API-Schlüssel **einmalig** angezeigt, inkl. Server-URL-Hinweis), eigene Geräte **deaktivieren/aktivieren**.
- **Deaktivieren statt Löschen:** Deaktivieren sperrt den Upload-Schlüssel sofort; **alle hochgeladenen Daten bleiben erhalten**; Reaktivieren mit denselben Zugangsdaten möglich. Geräte-Löschung existiert in der Oberfläche nicht mehr. (NutzerIn löschen entfernt weiterhin kaskadierend alles — gewollt.)
- Admin behält Gesamtübersicht aller Geräte mit denselben Schaltern; Anlegen *für andere* bleibt Admin-Funktion. Virtuelle „Manuell"-Geräte (siehe 3.4) erscheinen in keiner Liste.

### 3.2 Tagesübersicht (Flugtag)

- **Flugtag-Daten:** aufklappbarer Block mit editierbaren Feldern Maschine, Basis/Standort, Besatzung, Notizen; Kurzfassung in der Kopfzeile. Verknüpfung über **(user_id, Datum)**; Datensatz entsteht beim ersten Speichern; Felder je NutzerIn.
- Einsatz-Tabelle (Beginn/Dauer/km, Farb-Swatch) + Karte (Gründerfarben zuerst, Ruhe-Track Asphalt, Auto-Zoom ~75 %); Seitenmenü frühere Tage; Klick öffnet Einsatzübersicht.
- **„+ Einsatz nachtragen"** öffnet das Formular mit dem gewählten Tag vorbelegt.

### 3.3 Einsatzübersicht

- Karte (Philipp Orange, Start-/Ende-Marker, Auto-Zoom ~75 %); Kopfzeile mit Datum/Zeiten/km, **„Bearbeiten"-Link** und **„manuell"-Badge** bei von Hand angelegten/bearbeiteten Einsätzen.
- **Zusatzfelder** (z. B. Einsatznummer, Notizen) werden generisch angezeigt, sofern gefüllt.
- Phasen-Tabelle (HH:MM, Berlin); Reanimationen je Sitzung als eigene Tabelle („Reanimation 1/2/…").

### 3.4 Manuelle Einsätze (anlegen & bearbeiten)

- **Ein Formular für beides** (`einsatz_form.php`): Nachtragen (`?day=`) und Bearbeiten (`?id=`).
- **Phasen als dynamische Zeilen** (Phase 2–10 wählen + Uhrzeit; Zeilen hinzufügen/entfernen; doppelte Phasen möglich). Chronologische Eingabe; Zeiten nach Mitternacht werden automatisch dem Folgetag zugerechnet. Einsatzbeginn/-ende = erste/letzte Phase.
- **Virtuelles Gerät:** Manuell angelegte Einsätze hängen an einem pro NutzerIn automatisch erzeugten, dauerhaft deaktivierten Gerät „Manuelle Einträge" (kein Schema-Sonderfall, Idempotenz-Logik bleibt einheitlich).
- **Bearbeiten von Uhr-Einsätzen** setzt den `manual`-Marker (mit Vorab-Hinweis im Formular); Schutzwirkung siehe Abschnitt 2.
- **Erweiterbare Zusatzfelder:** zentrale Definition in `mission_fields.php` (Spalte, Label, Typ, Länge). Formular, Speichern, API und Anzeige lesen dieselbe Definition — ein neues Feld = eine Migration + ein Listeneintrag. Startbestand: Einsatznummer, Notizen.
- **Noch nicht enthalten:** Erfassen/Bearbeiten von Reanimations-Zeiten im Formular (siehe offene Punkte).

### 3.5 Betrieb & Wartung

- **Einrichtung:** Web-Installer `install.php` (DB-Test, Schema, Admin, `config.php`), Selbst-Sperre via `install.lock`.
- **Schema-Updates:** `update.php` mit Migrationsliste und Buchführung (`schema_migrations`); mehrfach aufrufbar, admin-geschützt.
- **Automatischer Aufräumjob:** max. 1×/Tag huckepack auf Web-/Uhr-Anfragen (kein Cron); entsorgt verwaiste Trackpunkte und alte Reset-Tokens; scheitert grundsätzlich still.
- **Deployment:** GitHub-Repo; Actions-Workflow lädt `server/` per FTPS; `config.php`/`install.lock` ausgenommen; `.gitignore` schützt Builds und Zugangsdaten.

### 3.6 Technischer Rahmen Web

- PHP ≥ 8.1 + MySQL/MariaDB, EU-Webspace; Leaflet/OSM; Referrer-Policy `strict-origin-when-cross-origin`.
- Sicherheits-Minimum: HTTPS; PDO Prepared Statements; `password_hash`; Session-Cookie HttpOnly/Secure/SameSite=Strict; CSRF (Formulare + JSON-Header); Leseabfragen und Schreibzugriffe nach `user_id` gefiltert; Ingest-Größen- und Wertevalidierung; `.htaccess` schützt sensible Dateien.
- GenEM-Branding in allen Seiten (inkl. Installer-Akzente).

---

## 4. Datenmodell-Grundsätze

- Einsätze (mit `manual`-Marker + Zusatzfeldern), Ruhe-Segmente, Phasen, Rea-Sitzungen/-Ereignisse, Trackpunkte (`owner_type`), Flugtage, Geräte (mit `active`-Flag), NutzerInnen, `app_state`, `schema_migrations` — alles per Migration erweiterbar.
- **GPX-Export vorbereitet** (lat/lon/ele/ts in Reihenfolge); Skalierung unkritisch (~1 Mio. Punkte/Jahr); verwaiste Daten räumt der Wartungsjob.

---

## 5. Änderungshistorie

**v1.1 → v1.2:**

| Änderung | Abschnitt |
|---|---|
| Geräte **deaktivieren statt löschen** (Daten bleiben; Reaktivierung möglich) | 3.1, 1.7 |
| **Geräte-Selbstverwaltung** für alle NutzerInnen | 3.1 |
| **Manuelle Einsätze**: Formular für Nachtragen **und** Bearbeiten | 3.4 |
| **Erweiterbare Zusatzfelder** über zentrale Definition (`mission_fields.php`) | 3.4, 3.3 |
| `manual`-Schutzmarker gegen Überschreiben durch Uhr-Uploads | 2, 3.4 |
| Geräteneutrale Schnittstelle ausdrücklich dokumentiert (Fremdquellen) | 0, 2 |
| Review-Fixes: Track-Chunk-Ausrichtung nach Neustart (Datenverlust), Rea-Timer-Persistenz, eingefrorene Einsatz-km, `seq_from`-Validierung | 1.1, 1.5, 1.6, 2 |

**v1.0 → v1.1:** Dienst-Klammer statt 06:00-Heuristik; Schnellmenü auf lang START; Tempo-Ansicht; mehrere Reanimationen („Rea beenden", farbcodiertes Endlos-Menü, lila Gesamtzeit); Vertrag v1.1 (`resus_sessions`); Flugtag-Felder; mehrere Rea-Tabellen; Installer/Migrations-Runner/Aufräumjob/FTP-Deploy; GenEM-Branding.

## 6. Offene / verschobene Punkte

| # | Punkt | Status |
|---|-------|--------|
| 1 | Reanimations-Zeiten im Nachtrage-/Bearbeitungsformular | nächster Ausbau bei Bedarf |
| 2 | Serverseitige Track-Vereinfachung (Douglas-Peucker) | optional/Kür |
| 3 | GPX-Export als Funktion im Web | vorbereitet, bei Bedarf |
| 4 | Geteilte Flugtage (Crew-weit statt je NutzerIn) | bei Bedarf |
| 5 | Geräte-Limit pro NutzerIn | optional |
| 6 | Weitere Zielgeräte (Fenix 7/8, Touch) | später |
