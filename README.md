# HEMS Einsatzdoku

Dokumentation von Hubschraubereinsätzen: Garmin-Uhr-App (Fenix 6 Pro,
Connect IQ) erfasst Phasen, GPS-Tracks und Reanimations-Ereignisse und lädt
sie auf einen eigenen Server; die Web-App (PHP/MySQL) zeigt Flugtage,
Einsätze und Rea-Protokolle und erlaubt Nachtragen/Bearbeiten.

## Dokumentation

| Dokument | Inhalt |
|---|---|
| [`docs/Handbuch.md`](docs/Handbuch.md) | Vorstellung und Bedienung aller Funktionen (Uhr + Web) |
| [`docs/Technik.md`](docs/Technik.md) | Architektur, Datenmodell, Abläufe, Build, Deployment, **Betrieb/Runbook** |
| [`docs/CHANGELOG.md`](docs/CHANGELOG.md) | Änderungshistorie |
| [`docs/JSON-Vertrag.md`](docs/JSON-Vertrag.md) | Schnittstelle Uhr/Fremdquellen → Server |
| `docs/archiv/` | eingefrorene Bau-Spezifikation (nicht mehr gepflegt) |

## Schnellstart

**Server:** `server/` auf den Webspace, leere MySQL-DB bereitstellen,
`index.php` aufrufen → der Installer führt durch die Einrichtung
(Details: Technik-Doku, Abschnitt Betrieb).

**Uhr:** `watch/` mit VS Code + Monkey-C-Erweiterung + Connect-IQ-SDK bauen
(Ziel `fenix6pro`), `.prg` per USB nach `GARMIN/Apps/`, Zugangsdaten über die
Connect-IQ-Einstellungen eintragen (Details: Technik-Doku, Abschnitt 5;
Geräte-Anlage: Handbuch, Abschnitt 4).

**Deployment:** Push auf `main` deployt `server/` automatisch per FTPS
(GitHub Actions). Nach DB-Änderungen als Admin `update.php` aufrufen.
