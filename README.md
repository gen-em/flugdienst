# HEMS Einsatzdoku

Dokumentation von Hubschraubereinsätzen: Garmin-Uhr-App (Fenix 6 Pro) erfasst
Phasen-Zeitstempel, GPS-Tracks und Reanimations-Ereignisse und lädt sie direkt
auf einen eigenen Server; eine PHP/MySQL-Web-App zeigt Tages- und
Einsatzübersichten mit Karte.

- **Anforderungen:** `Anforderungen_HEMS-App.md` (v1.0, separat übergeben)
- **Schnittstelle:** `docs/JSON-Vertrag.md` (v1.0)
- **Server:** `server/` — PHP ≥ 8.1, MySQL ≥ 5.7 / MariaDB ≥ 10.2, ohne Composer
- **Uhr:** `watch/` — Connect IQ / Monkey C, Ziel `fenix6pro`, minApiLevel 3.1.0

## Status

Der Server-Code ist vollständig und syntaxgeprüft (`php -l`), aber noch nicht
gegen eine echte Datenbank gelaufen. Der Uhr-Code ist ein vollständiger erster
Wurf, aber **noch nicht kompiliert** — der erste Build gegen das Connect-IQ-SDK
wird erfahrungsgemäß eine Handvoll API-Korrekturen verlangen (siehe unten).

## Server in Betrieb nehmen

1. `server/` auf den Webspace legen (DocumentRoot oder Unterverzeichnis) und eine
   leere MySQL-/MariaDB-Datenbank bereitstellen. Sicherstellen, dass das
   Verzeichnis für PHP schreibbar ist (der Installer legt dort `config.php` an).
2. Im Browser `index.php` (oder direkt `install.php`) aufrufen. Fehlt die
   `config.php`, startet automatisch der **Einrichtungs-Assistent**. Dort
   eintragen: DB-Zugang, Admin-E-Mail + Passwort, Basis-URL, optional SMTP
   (für Passwort-Reset-Mails). Der Installer testet die Verbindung, legt die
   Tabellen an, erstellt den Admin und schreibt `config.php`.
3. Nach Erfolg sperrt sich der Installer selbst (`install.lock`) und `index.php`
   nimmt den normalen Betrieb auf. Empfehlung: `install.php` anschließend vom
   Server löschen.
4. Als Admin unter **Verwaltung** ein Gerät anlegen. Geräte-ID und API-Schlüssel
   werden **einmalig** angezeigt → in die Uhr-App-Einstellungen übertragen.
5. Apache: `.htaccess` erzwingt HTTPS und schützt `config.php`, `schema.sql`,
   `install.lock` usw. Bei nginx die äquivalenten Deny-Regeln setzen.

Alternativ ist weiterhin manuelles Setup möglich (`config.example.php` nach
`config.php` kopieren, `schema.sql` einspielen, Admin per SQL anlegen), falls
der Installer in einer bestimmten Umgebung nicht genutzt werden soll.

## Uhr-App bauen

1. Connect-IQ-SDK (SDK-Manager) + VS-Code-Erweiterung „Monkey C" installieren.
2. In `watch/manifest.xml` die Platzhalter-`id` durch eine eigene UUID ersetzen
   (`uuidgen`, Bindestriche entfernen).
3. Projektordner `watch/` in VS Code öffnen → „Monkey C: Build for Device"
   (Ziel `fenix6pro`) oder erst im Simulator testen.
4. `HemsApp.prg` per USB in `GARMIN/Apps/` kopieren (Sideload).
5. In Garmin Connect Mobile → Connect-IQ-Einstellungen der App: Server-URL
   (`https://…/ingest.php`), Geräte-ID und API-Schlüssel eintragen.

## Bekannte Vorbehalte (erster Build)

- **MapView-API:** Signaturen von `setPolyline`, `MapPolyline.addLocation`,
  `setMapMarker`, `setMapVisibleArea` bitte gegen die aktuelle SDK-Doku prüfen —
  hier sind Abweichungen am wahrscheinlichsten.
- **Tastenerkennung Oberfläche 3:** lange Drücke auf DOWN/START werden manuell
  über `onKeyPressed`/`onKeyReleased` erkannt. Im Simulator prüfen, ob die
  Events auf der Fenix 6 Pro wie erwartet ankommen.
- **Storage-Limits:** Track-Chunks sind auf 200 Punkte ausgelegt (≈ unter der
  8-KB-Grenze pro Storage-Wert). Bei sehr langen Diensten Gesamtbelegung im
  Blick behalten.
- **Teil-Flush beim App-Ende:** Kommentar in `Track._flush()` beachten —
  Chunk-Ausrichtung beim Wiederanlauf gegentesten.
- **Rea ohne Einsatz** startet implizit einen Einsatz (bewusste Entscheidung,
  damit nichts verloren geht).

## Offen (Anforderungen v1.0)

- Bestätigung: 06:00-Heuristik ersatzlos gestrichen (Dienst-Klammer ersetzt sie).
- Später: mehrere Reanimationen pro Einsatz; serverseitige Track-Vereinfachung.
