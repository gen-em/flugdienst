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

1. `server/` auf den Webspace legen (DocumentRoot oder Unterverzeichnis).
2. Datenbank anlegen und `server/schema.sql` einspielen.
3. `config.example.php` nach `config.php` kopieren und ausfüllen
   (DB-Zugang, `base_url`, SMTP-Zugang zum Stalwart). `config.php` nie committen.
4. Ersten Admin anlegen (einmalig per SQL):
   ```sql
   INSERT INTO users (email, role) VALUES ('deine@adresse.de', 'admin');
   ```
   Dann auf der Loginseite „Passwort vergessen oder erstmalig setzen" nutzen —
   der Setz-Link kommt per Mail.
5. Als Admin unter **Verwaltung** ein Gerät anlegen. Geräte-ID und API-Schlüssel
   werden **einmalig** angezeigt → in die Uhr-App-Einstellungen übertragen.
6. Apache: `.htaccess` erzwingt HTTPS und schützt `config.php`. Bei nginx die
   äquivalenten Regeln setzen (deny für `config.php`, `schema.sql` usw.).

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
