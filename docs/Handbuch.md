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

### 2.2 Die Oberflächen

Mit **kurz UP/DOWN** blätterst du im Kreis durch: **Uhr → Tempo →
Statistik → Sync → Reanimation**.

**Uhr (Hauptanzeige):** groß die Uhrzeit, darunter klein das Datum, darunter
die aktuelle Phase (Zahl + Name). Läuft eine Reanimation, umschließt ein roter
Ring die Anzeige — auf einen Blick erkennbar.

**Sync:** Zeigt, ob alle abgeschlossenen Pakete beim Server angekommen sind
(grün „Sync vollständig" mit Haken) oder wie viele noch offen sind, bei
Problemen mit Fehlergrund. Darunter steht die **GPS-Güte**: „GPS gut" oder
„GPS ausreichend" (grün) heißt, dass Positionen aufgezeichnet werden; „GPS zu
schwach" (rot) bedeutet, dass die Uhr gerade keine Punkte speichert — die
Schwelle entspricht exakt der, die auch die Aufzeichnung verwendet. Außerhalb
eines Dienstes steht dort „GPS aus". Unten die App-Version; mit **START
gedrückt halten** startest du hier die Geräte-Kopplung.

- **kurz START** schaltet zur nächsten Phase (mit Zeitstempel und Position):
  1 Frei → 2 Alarmierung (= Einsatzbeginn) → 3 Abflug → 4 Ankunft Einsatzort →
  5 Ankunft PatientIn → 6 Transportbeginn → 7 Landung Krankenhaus →
  8 Übergabezeit → 9 Endzeit → 10 Beendigung (= Einsatzende, zurück zu 1).
- **lang START** öffnet das **Schnellmenü**: eine Phase direkt anspringen
  (erneutes Setzen erzeugt einen *zusätzlichen* Zeitstempel — nichts wird

  Drückst du während des langen START-Drucks zusätzlich eine andere Taste,
  bleibt das Menü zu — die App erkennt daran die **Tastensperre** der Uhr. So
  kollidiert das Sperren nicht mehr mit dem Schnellmenü.
  überschrieben), „Einsatzübersicht Zeiten" (Liste aller Zeitstempel) und
  „Einsatztag beenden".
- **BACK** fragt nach, bevor die App verlassen wird.


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
den vollständigen Empfang bestätigt hat. Den aktuellen Stand zeigt die
**Sync-Seite** (vom Startbildschirm mit DOWN, im Dienst zwischen Statistik und
Reanimation).

## 3. Die Web-Oberfläche

Die Kopfleiste zeigt links das GenEM-Icon mit „Einsatzdokumentation
Luftrettung – *Name*" (Name im Profil setzbar, sonst E-Mail), rechts die
Menüs **Übersicht**, **Administration** (nur Admin) und **⚙ Einstellungen**
(Profil, Geräte, Standortdaten, Backup; Abmelden fragt sicherheitshalber nach). Nach 30 Minuten
ohne Aktivität meldet das System automatisch ab. Die Einsatztage-Leiste links begleitet alle
Inhaltsseiten — auch Einsatzansicht und Formular; ein Tagesklick führt zur
Übersicht des Tages.

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
- **Tabelle** der Einsätze: Nr., Beginn, Dauer, **Einsatzort** (Ortschaft aus
  der verschlüsselten Adresse), **Alter**, **Diagnose**, Winde, Bergwacht,
  Kilometer — Klick öffnet den Einsatz, Klick auf einen Spaltenkopf sortiert
  (außer Winde/Bergwacht). Die Einsatzort-Pins auf der Karte tragen die Farbe
  des jeweiligen Einsatzes; Tracklinien werden beim Rauszoomen dicker.
  Die Dauer rechnet von der Alarmierung bis Phase 9; fehlt Phase 9, steht dort
  „kein Ende".
- **„+ Einsatz nachtragen"** öffnet das Eingabeformular für diesen Tag.

### 3.3 Einsatzübersicht

Titel „Einsatz N · Uhrzeit" (N = Nummer des Tages nach Alarmierungszeit),
darunter **Bearbeiten** und **Löschen** (mit Bestätigung; entfernt auch Phasen,
Reanimationen und Track — die Uhr legt den Einsatz danach nicht wieder an).
Karte mit Track (Start grün, Ende rot) und ggf. dem Einsatzort-Pin aus den
lokal entschlüsselten Koordinaten (Diagnose, Alter und Einsatzort erscheinen
mit 🔒 in der Feldliste); auf dem
Track sitzen **Phasen-Nummern** an den GPS-Positionen der Zeitstempel
(Umschalter unter der Karte). Zeigt man auf eine Phasenzeile oder eine Kachel,
leuchtet das Gegenstück orange auf (am Handy: antippen). Darunter die
Phasen-Tabelle und je Reanimation eine eigene Zeiten-Tabelle.

### 3.4 Einsätze nachtragen und bearbeiten

Das Formular dient beidem. Phasen werden als Zeilen erfasst (Phase wählen,
Uhrzeit eintragen, Zeilen hinzufügen/entfernen — auch dieselbe Phase mehrfach).
**In chronologischer Reihenfolge eintragen**; Zeiten nach Mitternacht werden
automatisch dem Folgetag zugerechnet.

Diagnose, Alter und **Einsatzort** liegen gemeinsam im verschlüsselten Block
(siehe 3.7). Der Einsatzort hat ein Suchfeld: Ab drei Buchstaben erscheinen
Adressvorschläge (OpenStreetMap); die Auswahl eines Vorschlags speichert die
Koordinaten und setzt den Pin auf den Karten. Freitext ohne Vorschlag geht
auch — dann ohne Pin.

Dazu die Zusatzfelder: Einsatznummer, Transportziel, Beschreibung Einsatzort (nur Detailansicht)
(erscheint in der Tagestabelle), **Windeneinsatz** (Haken öffnet Cycles,
Cycles mit Patient, Luftverladung), **Bergwacht** (Haken öffnet Bereitschaft
aus den Stammdaten plus Namen/Infos), Anderer Notarzt, Weitere Rettungsmittel
und Notizen.

Beim Bearbeiten eines **Uhr-Einsatzes** gilt: Nach dem Speichern ist er als
„manuell" markiert — die Uhr überschreibt ihn dann nicht mehr (nur der
GPS-Track wird weiter ergänzt). Das Formular weist vorher darauf hin.
Reanimations-Zeiten lassen sich im Formular derzeit nicht erfassen.

### 3.5 Geräte

Unter **⚙ Einstellungen → „Geräte"** verwaltet jede/r die eigenen Uhren: **„Gerät anlegen"**
erzeugt Geräte-ID und API-Schlüssel — der Schlüssel wird **nur einmal**
angezeigt, also sofort notieren bzw. eintragen. **Deaktivieren** sperrt den
Upload sofort (z. B. bei Verlust); alle bereits hochgeladenen Daten bleiben
erhalten, und **Aktivieren** schaltet dasselbe Gerät wieder frei.

### 3.5a Standortdaten

Unter **⚙ Einstellungen → „Standortdaten"** pflegst du Vorbelegungen: Standorte,
Hubschrauber (Kennung plus Häkchen, welche Rollen an Bord sind) sowie
Namenslisten je Rolle und Bergwacht-Bereitschaften. Am Flugtag wählst du
Maschine und Standort dann per Dropdown; die beim Hubschrauber angehakten
Rollen erscheinen als Besatzungs-Dropdowns mit deinen Vorbelegungen. Mit
„Als Standard" (★) markierte Maschine und Standort werden bei neuen Flugtagen
vorbelegt.

### 3.6 Administration (nur Admin)

NutzerInnen anlegen (verschickt automatisch den Passwort-Setz-Link) und
löschen (**Achtung:** entfernt alle Daten der Person unwiderruflich). Ein
Klick auf eine NutzerIn öffnet die Editierseite: Rolle wechseln, E-Mail
ändern und die verbundenen Geräte einsehen
(aktivieren/deaktivieren/löschen — Löschen lässt hochgeladene Daten
bestehen).
Nach Code-Updates mit Datenbank-Änderungen einmal **`update.php`** aufrufen
(siehe Technik-Doku, Betrieb).

### 3.7 Verschlüsselte Angaben (Pflicht)

Diagnose, Alter und Einsatzort sind **Ende-zu-Ende-verschlüsselt**: Der
Browser ver- und entschlüsselt mit einem Schlüssel aus deinem Login-Passwort;
der Server speichert nur Chiffretext. Es gibt kein zweites Passwort und
keinen Schalter — die Verschlüsselung ist Pflicht.

**Ersteinrichtung:** Beim ersten Anmelden führt das System einmalig auf die
Einrichtungsseite. Dort wird dein **Wiederherstellungsschlüssel** erzeugt und
**nur dieses eine Mal** angezeigt — ausdrucken und sicher ablegen, dann per
Haken bestätigen.

**Unbedingt wissen:**
- Normales Passwort-Ändern (mit altem Passwort) ist völlig unkritisch — die
  Daten bleiben ohne Zutun lesbar.
- Nach „Passwort vergessen" sind die verschlüsselten Angaben gesperrt, bis du
  sie auf der Einrichtungsseite mit dem Wiederherstellungsschlüssel
  entsperrst. **Ohne ihn sind sie unwiederbringlich verloren** — auch Admins
  können nicht helfen (deshalb gibt es keine Admin-Passwortvergabe).
- Verschlüsselte Felder sind serverseitig nicht durchsuchbar; der Schutz
  wirkt gegen Datenbank-Diebstahl und Mitleser, prinzipbedingt nicht gegen
  einen vollständig übernommenen Server.

**Im Alltag:** Das Einsatzformular bündelt Diagnose, Alter und den
Einsatzort (mit Adressvorschlägen; ein gewählter Vorschlag setzt die
Karten-Pins) in einem verschlüsselten Block. Die Tagesübersicht zeigt die
Spalten Einsatzort (Ortschaft aus der Adresse), Alter und Diagnose — lokal
entschlüsselt und sortierbar. Zeigt eine Seite „gesperrt", genügt ab- und
neu anmelden bzw. der Entsperr-Link.

### 3.8 Backup

Unter **⚙ Einstellungen → „Backup"** lädst du alle deine Daten als einzelne
verschlüsselte Datei (`.edbak`) herunter — Passwort frei wählbar, mindestens
8 Zeichen, wird nirgends gespeichert (ohne Passwort ist die Datei wertlos).

Ver- und Entschlüsselung passieren **in deinem Browser**; der Server sieht die
Inhalte nie. Deshalb lässt sich ein Backup auch **in ein anderes Konto**
einspielen: Beim Import werden die geschützten Angaben automatisch mit dem
Schlüssel des Zielkontos neu verschlüsselt.

Der Import ergänzt nur, was fehlt — Vorhandenes bleibt unangetastet, und
mehrfaches Einspielen derselben Datei ist gefahrlos. Während Export und Import
zeigt eine Statuszeile den Fortschritt und am Ende die Zahl der übernommenen
Einsätze, Ruhesegmente und Flugtage.

Der Aufbau der Datei ist in `docs/Backup-Format.md` vollständig beschrieben —
sie lässt sich damit auch ohne dieses Programm entschlüsseln.


### 3.8a Jahres- und Monatsübersicht

In der Einsatztage-Leiste öffnet ein Klick auf die **Jahreszahl** oder den
**Monatsnamen** eine Tabelle aller Einsätze dieses Zeitraums — mit Datum statt
Tagesnummer, ohne Karte. Die Kopfzeile nennt Anzahl der Einsätze, Flugtage und
Gesamtkilometer; jede Zeile lässt sich anklicken und führt zum Einsatz. Ein
Klick auf das **Dreieck** davor klappt dagegen nur die Unterpunkte auf oder zu,
ohne die Übersicht zu öffnen.

### 3.8b Andere Rettungsmittel

Unter **Einstellungen › Standortdaten › Andere Rettungsmittel** legst du RTW,
NEF oder weitere Hubschrauber als Vorbelegung an. Im Einsatzformular tippst du
im Feld **Weitere Rettungsmittel** mindestens zwei Zeichen — dann erscheinen
die passenden Einträge zum Anklicken. Jeder übernommene Eintrag steht als
eigenes Element mit kleinem Kreuz zum Entfernen; mehrere sind möglich, doppelte
werden abgewiesen. Steht etwas nicht in der Vorbelegung, lässt es sich trotzdem
übernehmen — es gilt dann nur für diesen Einsatz.

Löschst du später ein Rettungsmittel aus der Vorbelegung, behalten bereits
dokumentierte Einsätze ihren Eintrag: Die Zuordnung wird je Einsatz gespeichert
und hängt nicht an der Liste.

### 3.8c Flugtag von Hand anlegen

Lief die Uhr an einem Tag nicht, legst du den Flugtag über **+ Flugtag
anlegen** unten in der Einsatztage-Spalte an. Danach lassen sich Maschine,
Besatzung und nachgetragene Einsätze wie gewohnt erfassen.

### 3.9 Löschen und Papierkorb

Einsätze und ganze Flugtage landen beim Löschen zunächst im **Papierkorb** und
bleiben dort **90 Tage** wiederherstellbar; danach räumt das System sie
automatisch endgültig weg.

- **Einsatz löschen:** in der Einsatzansicht über „Löschen". Es erscheint eine
  Seite, die vorher zeigt, was mitgeht (Phasen, Reanimationen, Trackpunkte).
- **Flugtag löschen:** unten auf der Tagesübersicht. Achtung — das entfernt
  **den kompletten Tag**: alle Einsätze, Ruhesegmente, Tracks, Reanimationen
  und die Flugtag-Angaben. Beim Wiederherstellen kehrt alles gemeinsam zurück.
- **Papierkorb:** eigene Seite, erreichbar über das Papierkorb-Symbol unten in der
  Einsatztage-Spalte (ausgegraut, solange nichts darin liegt) —
  je eine Tabelle für gelöschte Flugtage und einzeln gelöschte Einsätze, mit
  „Wiederherstellen" und „Endgültig löschen". Endgültiges Löschen fragt noch
  einmal nach und ist unwiderruflich.

Solange etwas im Papierkorb liegt, nimmt der Server Nachlieferungen der Uhr
für diese Einsätze zwar entgegen, verwirft sie aber — gelöschte Einsätze
wachsen also nicht wieder an. Erst beim endgültigen Löschen wird die Referenz
dauerhaft gesperrt, sodass die Uhr sie nicht neu anlegt.

Alle Rückfragen erscheinen als Fenster **innerhalb der Seite**, nicht als
Browser-Dialog. Das ist Absicht: Bei Browser-Dialogen lässt sich „keine
weiteren Dialoge dieser Seite anzeigen" ankreuzen — danach würden Löschungen
kommentarlos durchlaufen. Seiteneigene Fenster kann der Browser nicht
abschalten.

Stammdaten (Standorte, Maschinen, Besatzung, Bergwacht) und Geräte werden
direkt nach einer kurzen Rückfrage gelöscht — sie sind schnell wieder angelegt.
**Bereits dokumentierte Flugtage bleiben davon unberührt:** Besatzungsnamen und
Bergwacht-Angaben stehen ohnehin als Text im Flugtag, und beim Löschen einer
Maschine oder eines Standorts wird deren Name vorher in die betroffenen
Flugtage übernommen. Die Historie bleibt also vollständig lesbar.
Ein **Nutzerkonto** zu löschen verlangt zusätzlich das Abtippen der
E-Mail-Adresse und geht nicht über den Papierkorb.

## 4. Eine neue Uhr einrichten (Kurzanleitung)

1. App auf die Uhr laden (siehe `Technik.md`); als Server-Einstellung genügt
   die Domain (z. B. `luftrettung.net`).
2. Im Web unter **⚙ Einstellungen → „Geräte" → „Kopplungscode erzeugen"** —
   der 5-Zeichen-Code ist 60 Minuten gültig und einmal verwendbar.
3. Auf der Uhr am Startbildschirm **UP halten**, den Code eintippen und
   bestätigen — die Uhr meldet „Gekoppelt ✓" und ist einsatzbereit. Das Gerät
   erscheint im Web in der Geräteliste („Uhr (gekoppelt …)").
4. Alternative ohne Code: Gerät manuell anlegen und Geräte-ID/API-Schlüssel
   in die Einstellungen eintragen (nur nötig, wenn die Kopplung nicht möglich
   ist).
