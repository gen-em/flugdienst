# Einsatzdoku — Handbuch

*Stand: 18.07.2026 · Für die technische Struktur siehe `Technik.md`, für Änderungen `CHANGELOG.md`.*

## 1. Was ist die Einsatzdoku?

Die Einsatzdoku dokumentiert Hubschraubereinsätze direkt vom Handgelenk: Eine
Garmin-Uhr-App erfasst Einsatzphasen mit Zeitstempeln, GPS-Tracks und
Reanimations-Ereignisse und lädt alles automatisch auf einen eigenen Server.
Die Web-Oberfläche (luftrettung.net) zeigt Flugtage mit Karte, Einsatz-Details
und Reanimations-Protokollen — und erlaubt Nachtragen und Bearbeiten von Hand.

**Wichtig (Datenschutz):** Die Einsatzdoku ist für *einsatzbezogene* Zeiten und
Orte gedacht. **Keine Patientendaten eintragen** — auch nicht in Freitextfelder.

## 2. Die Uhr-App

### 2.1 Dienst beginnen und beenden

Beim Öffnen der App erscheint **„Dienst beginnen?"**. Erst ein Druck auf
**START** aktiviert die App und die GPS-Aufzeichnung — vorher passiert nichts.
Der Flugtag läuft, bis du ihn über das Schnellmenü mit **„Einsatztag beenden"**
(Sicherheitsabfrage) schließt; dabei werden Restdaten hochgeladen. Das Datum
des Flugtags ist das Datum des Dienstbeginns — auch bei Diensten über
Mitternacht.

Ein Neustart der Uhr oder der App mitten im Dienst ist unkritisch: Phase,
Track und eine laufende Reanimation werden nahtlos fortgesetzt.

### 2.2 Die vier Oberflächen

Mit **kurz UP/DOWN** blätterst du im Kreis durch: **Uhr → Karte → Tempo → Reanimation**.

**Uhr (Hauptanzeige):** Uhrzeit und aktuelle Phase (Zahl + Name). Unten
erscheint bei Bedarf „REA läuft" oder „Sync ausstehend".

- **kurz START** schaltet zur nächsten Phase (mit Zeitstempel und Position):
  1 Frei → 2 Alarmierung (= Einsatzbeginn) → 3 Abflug → 4 Ankunft Einsatzort →
  5 Ankunft PatientIn → 6 Transportbeginn → 7 Landung Krankenhaus →
  8 Übergabezeit → 9 Endzeit → 10 Beendigung (= Einsatzende, zurück zu 1).
- **lang START** öffnet das **Schnellmenü**: eine Phase direkt anspringen
  (erneutes Setzen erzeugt einen *zusätzlichen* Zeitstempel — nichts wird
  überschrieben), „Einsatzübersicht Zeiten" (Liste aller Zeitstempel) und
  „Einsatztag beenden".
- **BACK** fragt nach, bevor die App verlassen wird.

**Karte:** aktuelle Position und der GPS-Track des laufenden Einsatzes.
Zwischen Einsätzen wird im Hintergrund ein Ruhe-Track aufgezeichnet (auf der
Uhr nicht sichtbar, im Web schwarz dargestellt).

**Tempo:** aktuelle Geschwindigkeit (km/h) groß, darunter die im Einsatz
zurückgelegten Kilometer.

**Reanimation:** siehe nächster Abschnitt.

### 2.3 Reanimationsmodus

Zwei Timer: oben klein und **lila** die Gesamtdauer seit Rea-Beginn, mittig
groß der **2:00-Countdown** für den Zyklus. Bei 0:00 vibriert die Uhr zweimal
kurz; der Countdown bleibt rot auf 0:00 stehen, bis er neu gestartet wird.

| Taste | Wirkung |
|---|---|
| kurz START | Reanimation **beginnen** / Countdown manuell neu starten |
| lang UP | **Adrenalingabe** dokumentieren |
| lang DOWN | **Rhythmuskontrolle** dokumentieren (setzt Countdown auf 2:00) |
| lang START | Untermenü öffnen |
| kurz UP/DOWN | Oberfläche wechseln (Timer laufen weiter) |
| BACK | zurück zur Hauptanzeige (Timer laufen weiter) |

**Untermenü** (farbcodiert, endlos scrollbar): Defibrillation, Intubation,
Amiodaron, Sonographie, ROSC, Tod — je ein Zeitstempel; **Übersicht** zeigt
alle Zeiten der laufenden Rea; **„Rea beenden"** (rot) schließt die
Reanimation nach Sicherheitsabfrage. Danach startet **kurz START** eine
*neue* Reanimation — mehrere pro Einsatz sind möglich, jede bekommt im Web
ihre eigene Tabelle. Bei Einsatzende wird eine laufende Rea automatisch
geschlossen.

### 2.4 Datenübertragung

Die Uhr lädt selbstständig hoch: Einsätze bei Phase 10, den Ruhe-Track etwa
stündlich, den Rest beim Dienstende. Ohne Verbindung puffert die Uhr sicher im
Speicher und sendet später nach — gelöscht wird lokal erst, wenn der Server
den vollständigen Empfang bestätigt hat. „Sync ausstehend" auf der
Hauptanzeige heißt nur: Es wird später erneut versucht.

## 3. Die Web-Oberfläche

### 3.1 Anmelden & Passwort

Anmeldung mit E-Mail-Adresse und Passwort. Über **„Passwort vergessen oder
erstmalig setzen"** kommt ein Link per E-Mail (1 Stunde gültig) — derselbe Weg
dient auch der Erst-Einrichtung nach dem Anlegen durch den Admin.

### 3.2 Tagesübersicht

Links die Liste der Flugtage; der neueste ist vorausgewählt. Pro Tag:

- **Flugtag-Daten** (aufklappbar): Maschine, Basis/Standort, Besatzung,
  Notizen — direkt editier- und speicherbar. Die Kopfzeile zeigt eine
  Kurzfassung.
- **Karte** mit allen Einsätzen des Tages (jeder in eigener Farbe, beginnend
  mit Orange/Blau/Rot) und dem Ruhe-Track in Schwarz.
- **Tabelle** der Einsätze (Beginn, Dauer, Kilometer) — Klick öffnet den
  Einsatz.
- **„+ Einsatz nachtragen"** öffnet das Eingabeformular für diesen Tag.

### 3.3 Einsatzübersicht

Karte mit Track (Start grün, Ende rot), darunter die Phasen-Tabelle und — bei
Reanimationen — je Reanimation eine eigene Zeiten-Tabelle. In der Kopfzeile:
Datum, Zeitfenster, Kilometer, ggf. das Badge **„manuell"** sowie der Link
**„Bearbeiten"**.

### 3.4 Einsätze nachtragen und bearbeiten

Das Formular dient beidem. Phasen werden als Zeilen erfasst (Phase wählen,
Uhrzeit eintragen, Zeilen hinzufügen/entfernen — auch dieselbe Phase mehrfach).
**In chronologischer Reihenfolge eintragen**; Zeiten nach Mitternacht werden
automatisch dem Folgetag zugerechnet. Dazu Zusatzfelder wie Einsatznummer und
Notizen.

Beim Bearbeiten eines **Uhr-Einsatzes** gilt: Nach dem Speichern ist er als
„manuell" markiert — die Uhr überschreibt ihn dann nicht mehr (nur der
GPS-Track wird weiter ergänzt). Das Formular weist vorher darauf hin.
Reanimations-Zeiten lassen sich im Formular derzeit nicht erfassen.

### 3.5 Geräte

Unter **„Geräte"** verwaltet jede/r die eigenen Uhren: **„Gerät anlegen"**
erzeugt Geräte-ID und API-Schlüssel — der Schlüssel wird **nur einmal**
angezeigt, also sofort notieren bzw. eintragen. **Deaktivieren** sperrt den
Upload sofort (z. B. bei Verlust); alle bereits hochgeladenen Daten bleiben
erhalten, und **Aktivieren** schaltet dasselbe Gerät wieder frei.

### 3.6 Verwaltung (nur Admin)

NutzerInnen anlegen (verschickt automatisch den Passwort-Setz-Link) und
löschen (**Achtung:** entfernt alle Daten der Person unwiderruflich), dazu die
Gesamtübersicht aller Geräte mit denselben Aktivieren/Deaktivieren-Schaltern.
Nach Code-Updates mit Datenbank-Änderungen einmal **`update.php`** aufrufen
(siehe Technik-Doku, Betrieb).

## 4. Eine neue Uhr einrichten (Kurzanleitung)

1. Im Web unter **„Geräte" → „Gerät anlegen"** — Geräte-ID und API-Schlüssel
   werden einmalig angezeigt.
2. App auf die Uhr laden (siehe `Technik.md`, Abschnitt Build/Sideload).
3. In **Garmin Connect** am Handy: Connect-IQ-Einstellungen der Einsatzdoku
   öffnen und eintragen: Server-URL (`https://luftrettung.net/ingest.php`),
   Geräte-ID, API-Schlüssel.
4. Auf der Uhr „Dienst beginnen" — nach dem ersten Upload erscheint das Gerät
   im Web unter „Zuletzt gesehen".
