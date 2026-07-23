# Changelog — Einsatzdoku

Format nach [Keep a Changelog](https://keepachangelog.com/de/).

**Weboberfläche** und **Uhr-App** werden getrennt gezählt, weil sie unabhängig
voneinander ausgeliefert werden: `server/version.php` bzw.
`watch/source/Const.mc`. Die Web-Version steht in der Fußzeile jeder Seite und
hängt an allen Stylesheet- und Skript-Adressen — nach einem Update lädt der
Browser sie dadurch von selbst neu. Die Uhr-Version steht auf der Sync-Seite.
Die Stände 1.0 bis 1.2 unten sind die frühen Spezifikations-Stände des
Gesamtprojekts, vor der getrennten Zählung.

## [Web 2.1.0] — 2026-07-22

### Behoben — Passwort zurücksetzen
- **Ein Reset machte das Konto unbrauchbar.** `reset_confirm.php` speicherte
  den Hash des rohen Passworts, während die Anmeldung den Hash des im Browser
  abgeleiteten Tokens erwartet — eine Anmeldung war danach unmöglich. Zusätzlich
  wurde der Inhaltsschlüssel nicht neu verpackt, sodass auch alle
  verschlüsselten Angaben unlesbar geworden wären.
- Der Reset verlangt jetzt den **Wiederherstellungsschlüssel**: Der Browser
  entpackt damit den Inhaltsschlüssel, leitet aus dem neuen Passwort Salz und
  Token ab und verpackt den Schlüssel neu. Server speichert alles in einer
  Transaktion — passt der Wiederherstellungsschlüssel nicht, bleibt das Konto
  unverändert.
- Kein Datenleck: Wer nur Zugriff auf das Postfach hat, kommt weiterhin nicht
  an die verschlüsselten Angaben.

### Entfernt — Unterstützung unverschlüsselter Konten
- Anmeldung, Salt-Endpunkt, Passwortwechsel und Zugriffsschutz kannten je einen
  Sonderweg für Konten ohne Browser-Schlüsselableitung (`kdf_ver = 0`). Da alle
  Konten umgestellt sind, sind diese Pfade entfallen — inklusive der Stelle, an
  der das Passwort einmalig im Klartext zum Server ging.
- Browser ohne Web-Krypto erhalten jetzt eine klare Meldung statt eines stillen
  Rückfalls auf den alten Weg.

## [Web 2.0.0] — 2026-07-22

### Versionierung eingeführt
- Die Weboberfläche hat jetzt eine eigene Version (`server/version.php`). Sie
  erscheint in der Fußzeile und hängt an allen Stylesheet- und Skript-Adressen,
  wodurch der Browser nach einem Update automatisch die neuen Dateien lädt —
  das manuelle Leeren des Zwischenspeichers entfällt.
- **Behoben:** Auf `zeitraum.php`, `papierkorb.php`, `flugtag_neu.php`,
  `einsatz_loeschen.php` und `flugtag_loeschen.php` stand die Fußzeile
  außerhalb des Inhaltsbereichs und war dadurch nicht sichtbar — Copyright und
  Lizenzhinweis fehlten auf diesen Seiten.

### Web
- **Neue geschützte Felder:** Nachname, Vorname und Geburtsdatum — wie
  Diagnose und Einsatzort Ende-zu-Ende-verschlüsselt im selben Container, also
  ohne Datenbankänderung und automatisch im Backup enthalten. Sie erscheinen
  nur in der Einsatzansicht, nicht in den Tabellenübersichten.
- **Alter wird aus dem Geburtsdatum berechnet** — bezogen auf den Einsatztag,
  nicht auf heute, und bei jeder Anzeige neu (kein Nachziehen bei Korrekturen).
  Ohne Geburtsdatum bleibt das Feld wie bisher von Hand eintragbar; die Spalte
  „Alter" in den Übersichten bleibt erhalten. Gemeinsame Berechnung in
  `assets/patient.js`, genutzt von Formular, Einsatzansicht, Tages- und
  Zeitraumübersicht.
- Papierkorb-Symbol und Beschriftung sind vertikal exakt mittig zueinander
  ausgerichtet (feste Zeilenhöhe hatte den Text nach oben versetzt).

- **Jahres- und Monatsübersicht:** Klick auf Jahreszahl oder Monatsnamen in der
  Einsatztage-Leiste öffnet `zeitraum.php` mit allen Einsätzen des Zeitraums als
  Tabelle (Datum statt Nummer, keine Karte, sortierbar, Zeile führt zum Einsatz)
  samt Kennzahlen. Das Dreieck klappt weiterhin nur auf und zu.
  Neuer Endpunkt `api/range.php` — bewusst ohne Trackpunkte, da bei einem
  ganzen Jahr sonst hunderttausende Koordinaten übertragen würden.
- **Standortdaten:** Nach dem Speichern wird jetzt gezielt zum jeweiligen
  Abschnitt umgeleitet — er klappt dadurch wieder auf, die Seite springt an die
  richtige Stelle, und ein Neuladen sendet das Formular nicht erneut ab.
- **Behoben:** „+ Einsatz nachtragen" lief weiterhin über die volle Breite. Der
  Knopf erbt aus dem Formular-Stil `width:100%`; in der Aktionsleiste fehlte das
  ausdrückliche `width:auto`, weshalb frühere Anläufe (Ausrichtung, Höhe) nichts
  bewirkten.

- **Kein Rahmen mehr an Aufklapp-Überschriften:** Der blaue Fokusrahmen passte
  nicht zur übrigen Form und umschloss bei geöffnetem Abschnitt den gesamten
  Inhalt. Ersetzt durch dieselbe dezente Färbung wie beim Überfahren mit der
  Maus — für Tastaturbedienung weiterhin erkennbar, ohne aufzufallen.
- **Fokusring bleibt nicht mehr nach Mausklicks stehen:** Er erscheint jetzt
  nur noch bei Tastaturbedienung (`:focus-visible`). Bei aufklappbaren
  Abschnitten umschloss er zuvor den gesamten geöffneten Bereich statt nur der
  Kopfzeile — dadurch wirkte die Umrandung von Jahr und Monat unterbrochen und
  überlagerte die Markierung des ausgewählten Tages. Bei Tastaturbedienung
  liegt der Ring nun innerhalb der Zeile.

- **Behoben: Übersicht blieb komplett leer.** Beim Gruppieren der Einsatztage
  wandelt PHP numerische Array-Schlüssel automatisch in Integer um („2026" →
  2026). Unter `strict_types` brach `e()` damit mit einem TypeError ab —
  mitten im Rendern der Leiste, sodass weder Tage noch Karte oder Tabelle
  erschienen. Zusätzlich schlug der Monatsvergleich ab Oktober fehl („12"
  wird zu 12, „07" bleibt Text), wodurch dort nie ein Monat aufgeklappt wäre.
  Beide Stellen wandeln jetzt ausdrücklich nach String.

- **Einsatztage-Leiste nach Jahr und Monat gruppiert:** Es ist immer genau
  ein Jahr geöffnet (echtes Akkordeon — ein anderes Jahr anklicken schließt
  das vorherige automatisch), darin genau ein Monat, standardmäßig der
  jüngste mit Einträgen. Springt man auf einen Tag in einem anderen
  Jahr/Monat (z. B. über den Papierkorb oder eine alte Verlinkung), klappt
  die Leiste automatisch dorthin auf.

- **Aktionsleiste und Papierkorb aufgeräumt:** Über mehrere Runden hatten
  sich für `.dayactions` und `.trashlink` mehrere, teils widersprüchliche
  Regeln im Stylesheet angesammelt. Zu einem einzigen Block zusammengeführt —
  „+ Einsatz nachtragen" und „Tag löschen" haben dadurch garantiert dieselbe
  Höhe, Schrift und Grundlinie; Papierkorb-Symbol und -Text sind horizontal
  zentriert und zueinander vertikal mittig ausgerichtet.
- **Kartenzoom vereinheitlicht:** Tagesübersicht und Einsatzansicht zoomen
  jetzt nach derselben Regel automatisch auf die Tracks (Rand proportional zur
  Kartengröße statt fester Pixelwert) und mit einer gemeinsamen Obergrenze —
  ein einzelner kurzer Track zoomt nicht mehr bis auf Gebäude-Ebene heran.
- **Max Blau** (Markenfarbe) sichtbarer eingesetzt: Fokusringe, Sortierpfeile
  in der Tagesübersicht, Kontrollkästchen und der „Flugtag anlegen"-Link
  nutzen jetzt Blau statt Orange — als ruhiger Gegenpart zu den
  Haupt-Aktionen (Orange) und Löschen (Rot).

- **Andere Rettungsmittel:** neue Vorbelegungsliste in den Standortdaten und
  Eingabe mit Vorschlägen im Einsatzformular (Suche ab zwei Zeichen, Klick
  übernimmt, freie Eingaben möglich). Jedes Rettungsmittel wird als eigener
  Datensatz gespeichert und lässt sich einzeln wieder entfernen; bisherige
  Freitexte werden bei der Migration automatisch aufgeteilt.
- **Standortdaten aufgeräumt:** Die fünf Bereiche sind jetzt aufklappbare
  Abschnitte und starten zugeklappt. Wer über einen Anker hineinspringt — etwa
  nach dem Speichern —, landet in einem automatisch geöffneten Abschnitt.
- **Flugtag von Hand anlegen** über die Einsatztage-Spalte, für Tage ohne Uhr.
- Kopfleiste bleibt beim Scrollen stehen; der Papierkorb ist beschriftet;
  „+ Einsatz nachtragen" und „Tag löschen" sind gleich hoch und gleich gesetzt.
- Kartenlinien durchgehend eine Stufe dünner, Einsatz- und Tagesansicht nutzen
  jetzt dieselbe Staffelung.

- Tagesübersicht besser lesbar: Zeilen abwechselnd schattiert, alle Spalten
  mittig ausgerichtet, Dauer kompakt gesetzt („3h 33min" statt „3 h 33 min"),
  damit die Spalte einzeilig bleibt. Bergwacht, Sekundär/Transport und
  „Flug km" haben mehr Luft bekommen; die Seite ist dafür 1200 px breit.

- **Neues Logo** (Hubschrauber-Bildmarke) für Kopfleiste, Login-,
  Einrichtungsseite und Favicon. Die Vorlagen wurden freigestellt (weißer
  Hintergrund → transparent, Kantenglättung erhalten); die weiße Fassung
  übernimmt die Maske der farbigen, damit beide deckungsgleich sitzen. Das
  Favicon liegt quadratisch mit Rand vor, damit es im Browser-Tab nicht
  verzerrt.

- Tagesübersicht: Spaltenüberschriften werden nicht mehr silbengetrennt —
  Winde, Bergwacht und „Flug km" stehen einzeilig, „Sekundär/Transport" bricht
  genau zwischen den Wörtern um; Alter ist so breit wie Beginn. Seitenbreite
  1150 px; die festen Spalten belegen rund 600 px, der Rest bleibt für
  Einsatzort und Diagnose.
- **Menüspalte bleibt stehen:** Die Einsatztage-Leiste nimmt die volle
  Fensterhöhe ein und scrollt bei vielen Tagen intern; der Papierkorb sitzt in
  einem festen Streifen darunter und ist dadurch immer sichtbar, ohne die Seite
  scrollen zu müssen.

- **Tagesübersicht zeigt Ladefehler an,** statt still leer zu bleiben: Liefert
  die Tages-API kein JSON (z. B. weil eine Migration fehlt), erscheint jetzt
  eine Meldung mit dem Anfang der Serverantwort. Vorher brach das Skript
  wortlos ab — Titel, Tabelle, Karte und der Löschknopf blieben leer.
- „Tag löschen" wird serverseitig eingeblendet und hängt nicht mehr am
  erfolgreichen Laden der Tagesdaten.
- Papierkorb: Aufbewahrung von 30 auf **90 Tage** verlängert; die Aktionen
  „Wiederherstellen" und „Endgültig löschen" sind gleich groß und bündig.
- Tagesübersicht: feste Tabellenaufteilung, damit die Spaltenbreiten wirklich
  greifen; Seitenbreite auf 1240 px erhöht, sodass Flugtag-Kasten, Karte und
  Tabelle gleich breit sind. Papierkorb-Symbol in fester Größe am unteren Rand
  der Einsatztage-Spalte.

- **Neue Felder:** „Sekundärtransport" (Haken, eigene sortierbare Spalte in der
  Tagesübersicht neben Bergwacht) und „Schockraum" (Haken beim Transportziel).
- **Papierkorb ist eine eigene Seite** und über ein Symbol unten in der
  Einsatztage-Spalte erreichbar — ausgegraut, solange er leer ist. Die
  Aktionen „Wiederherstellen" und „Endgültig löschen" sind jetzt Schaltflächen.
- Tagesübersicht: Spaltenbreiten in vier Stufen über Klassen statt Positionen
  (Farbe/Nr. sehr schmal; Alter, Winde, Bergwacht, Sekundärtransport schmal;
  Beginn, Dauer, Flugkilometer mittig und mittelbreit; Einsatzort und Diagnose
  bekommen den Rest). Neue Spalten verschieben dadurch keine Breiten mehr.
- Aktionsleiste unter der Tabelle: Schaltflächen nur so breit wie nötig,
  „Flugtag löschen" heißt jetzt „Tag löschen".
- Einsatzansicht: „Bearbeiten" und „Löschen" stehen rechts neben Titel und
  Uhrzeit statt darunter; Schaltflächen werden nicht mehr unterstrichen.
- „Abbrechen" auf den Löschseiten ist eine Schaltfläche statt eines Textlinks.
- **Altes Backup-Format entfernt:** Der serverseitige `.edbak`-Weg (Version 1)
  ist raus — Container-Funktionen in `backup_lib.php`, die Versionsweiche in
  `crypto.js`, der Import-Zweig samt Datei-Upload in `einstellungen.php` und
  Kapitel 4 der Formatdoku. Der Import prüft jetzt strikt die Dateikennung und
  lehnt alles andere mit klarer Meldung ab; damit kann kein zweiter Importweg
  mehr dazwischenfunken.
- Unter der Tagestabelle stehen jetzt zwei Schaltflächen: links „+ Einsatz
  nachtragen", rechts „Flugtag löschen" (weiterhin mit serverseitiger
  Bestätigungsseite).
- **Behoben:** Die neuen Seiten `flugtag_loeschen.php`, `einsatz_loeschen.php`
  und `papierkorb.php` banden `ui.php` ein zweites Mal ein (ohne `_once`),
  obwohl `auth_guard.php` sie bereits lädt — PHP brach mit „Cannot redeclare"
  ab, im Browser als Fehler 500 sichtbar.
- **Rückfragen laufen nicht mehr über Browser-Dialoge:** `window.confirm()` bot
  die Option „keine weiteren Dialoge dieser Seite anzeigen" — danach wären
  Löschungen ohne jede Nachfrage durchgelaufen. Alle Bestätigungen nutzen jetzt
  ein Fenster im Seiteninhalt (`assets/confirm.js`, `data-confirm`), das sich
  nicht abschalten lässt; „Abbrechen" ist vorausgewählt, Escape bricht ab.
- **Backup-Import schlug scheinbar fehl, obwohl er lief:** Der Formular-Handler
  brach das normale Absenden erst nach dem Einlesen der Datei ab. Bis dahin
  hatte der Browser das Formular längst mitgeschickt, sodass parallel der alte
  serverseitige Import lief und mit „Keine gültige Backup-Datei" antwortete —
  während der Browser-Import im Hintergrund korrekt durchlief. Das Absenden
  wird jetzt sofort unterbunden; Altformat-Dateien werden gezielt an den Server
  weitergereicht.
- **Papierkorb für Einsätze und Flugtage:** Gelöschtes wird zunächst nur
  markiert und bleibt 30 Tage wiederherstellbar (Anzeige unten auf der
  Übersicht, je Tabelle für Flugtage und Einsätze mit „Wiederherstellen" und
  „Endgültig löschen"). Der Aufräumjob entfernt Abgelaufenes automatisch.
- **Flugtag löschen** entfernt den kompletten Tag (Einsätze, Ruhesegmente,
  Tracks, Reanimationen, Flugtag-Angaben) und stellt ihn geschlossen wieder her.
- Schwere Löschungen laufen über eine **serverseitige Zwischenseite mit
  Umfangs-Anzeige** (ohne JavaScript wirksam) statt über einen Browser-Dialog.
- **Nutzer löschen** verlangt jetzt zusätzlich das Abtippen der E-Mail-Adresse;
  geprüft wird serverseitig.
- Uploads der Uhr für Einsätze im Papierkorb werden quittiert, aber verworfen;
  erst das endgültige Löschen sperrt die Referenz dauerhaft.
- **Backup läuft jetzt im Browser** (Format 2): Beim Export werden die
  geschützten Angaben lokal entschlüsselt und mit dem Backup-Passwort
  versiegelt; beim Import öffnet der Browser die Datei und verschlüsselt sie
  mit dem Schlüssel des **Zielkontos** neu. Damit lässt sich ein Backup in
  jedes Konto einspielen — der Server sieht zu keinem Zeitpunkt Klartext.
  Container: AES-256-GCM, PBKDF2 310 000 Runden, gzip, Kopf per AAD gebunden.
- Alt-Dateien (Format 1) werden am Kopf erkannt und weiterhin serverseitig
  importiert; ihre geschützten Angaben bleiben kontogebunden.
- Neue Endpunkte `api/backup_data.php` und `api/backup_restore.php`;
  `export_backup.php` entfällt.
- Ruhesegment-Tracks (Phase 1) auf der Tageskarte deutlich sichtbarer:
  warmes Grau statt Fast-Schwarz, kräftigere Linie mit Zoom-Anpassung.
- **Verschlüsselung ist jetzt Pflicht:** kein Modul-Schalter, keine
  Feldauswahl mehr — der Einstellungs-Reiter „PatientInnendaten" entfällt.
  Beim ersten Anmelden erzwingt das System die **Ersteinrichtung** mit
  einmalig angezeigtem Wiederherstellungsschlüssel (einrichtung.php); dieselbe
  Seite entsperrt nach einem Passwort-Reset per Wiederherstellungsschlüssel.
- Verschlüsselte Felder sind **Diagnose und Alter** (Nachname, Vorname und
  Geburtsdatum entfallen); der **Einsatzort** (Adresse + Koordinaten) wandert
  ebenfalls in den verschlüsselten Block — Klartext-Altbestände wurden per
  Migration verworfen (Spalten entfernt).
- Tagesübersicht: Spalten Nr. · Beginn · Dauer · **Einsatzort (Ortschaft aus
  der Adresse)** · **Alter** · **Diagnose** · Winde · Bergwacht · Kilometer;
  sortierbar außer Winde/Bergwacht. Karten-Pins entstehen jetzt aus den lokal
  entschlüsselten Koordinaten; Sperr-Banner mit Entsperr-Link nach Reset.
- **Admin-Passwortvergabe entfernt** (würde verschlüsselte Daten unlesbar
  machen); Hinweis auf „Passwort vergessen" + Wiederherstellungsschlüssel.
- Backup: exportiert die Schlüssel-Hüllen ohne Modul-Schalter; Alt-Backups
  mit Klartext-Ort werden beim Import toleriert (Ort wird verworfen).
- **Einsatzansicht komplett neu gebaut:** Bearbeiten-Link führt wieder zum
  richtigen Einsatz (die Seite hatte die Einsatz-ID verloren), volle Breite
  wie die Flugtag-Übersicht, Aktionsleiste nebeneinander.
- **Karten:** Einsatzort-Pins in der Farbe des jeweiligen Einsatzes (Ring in
  Trackfarbe); Tracklinien werden beim Rauszoomen dicker und nicht mehr
  vereinfacht — kurze Tracks bleiben auf der Tagesübersicht sichtbar.
- Überall „Flugtag" statt „Betriebstag" (Titel, Formular, Doku).
- Flugtag-**Notizen** stehen sichtbar im zugeklappten „Flugtag-Daten"-Kästchen.
- Standortdaten (vorher „Stammdaten", umbenannt): Hinweis „Rollen auf dem
  Hubschrauber:" vor den Häkchen.
- **Geräte umbenennbar** (gelber Bearbeiten-Button je Zeile).
- Administration: Name als eigene Spalte, ganze Zeile reagiert auf
  Hover/Klick; Abmelden fragt nach Bestätigung.
- **Backup (Export/Import):** Einstellungs-Reiter „Backup" sichert alle
  eigenen Daten (Einsätze inkl. Phasen/Reanimationen/Tracks, Ruhesegmente,
  Flugtage, Stammdaten, verschlüsselte PatientInnendaten samt
  Schlüssel-Hüllen) in eine einzelne `.edbak`-Datei — verschlüsselt mit frei
  wählbarem Passwort (AES-256-GCM, PBKDF2 200 000 Runden, manipulationssicher
  per GCM-Tag). Import ergänzt nur Fehlendes (Dubletten-Schutz über interne
  Referenzen), überschreibt nie. Formatbeschreibung: `docs/Backup-Format.md`.
- **PatientInnendaten-Modul (Ende-zu-Ende-verschlüsselt):** Felder Nachname,
  Vorname, Diagnose, Geburtsdatum, Alter (Alter automatisch aus Geburtsdatum,
  Stichtag Einsatzdatum; auch allein ausfüllbar). Ver- und Entschlüsselung
  ausschließlich im Browser (AES-256-GCM); der Login wurde auf
  Browser-Schlüsselableitung umgestellt (PBKDF2, 310 000 Runden) — der Server
  sieht das Passwort nie mehr und speichert nur Chiffretext. Eigener
  Einstellungs-Reiter: Aktivierung mit einmalig angezeigtem
  **Wiederherstellungsschlüssel**, Feldauswahl (Abwählen blendet nur aus),
  Modul an/aus, Zugriff-Wiederherstellen nach Passwort-Reset. Nachname-Spalte
  in der Tagesübersicht (lokal entschlüsselt, sortierbar). Bestehende Konten
  werden beim ersten Login transparent umgestellt.
- **Geräte-Kopplung per Kurzcode:** Im Web (Einstellungen → Geräte) einen
  5-Zeichen-Code erzeugen (60 Minuten gültig, einmal verwendbar), auf der Uhr
  am Startbildschirm **UP halten** und den Code eintippen — die Uhr holt sich
  ihre Zugangsdaten selbst und speichert sie dauerhaft. Geräte-ID und
  API-Schlüssel müssen nie mehr abgetippt werden; als einzige Einstellung
  bleibt die Server-Domain. Der bisherige Weg (manuell anlegen) bleibt als
  Alternative bestehen.
- **Stammdaten vereinheitlicht:** Alle vier Bereiche als helle Tabellen mit
  Aktionen in einer Zeile — Bearbeiten (gelb) und Löschen (rot); auch
  Besatzungs-Einträge sind jetzt umbenennbar; alles alphabetisch sortiert.
- **Standard-Maschine und Standard-Standort** (★): per „Als Standard" gesetzt;
  Flugtage ohne gespeicherte Auswahl werden damit vorbelegt.
- Kopfleiste: ⚙ ist jetzt ein Direktlink zu den Einstellungen (kein
  Aufklappmenü); mehr Abstand um Logo und Titel.
- **Sicherheit:** Automatische Abmeldung nach 30 Minuten Inaktivität (mit
  Hinweis auf der Login-Seite).
- **Einsatzfelder-Ausbau:** Feldsystem mit neuen Typen (Checkbox, Dropdown,
  bedingte Unterfelder, Tagesspalten-Flag). Neue Felder: Transportziel,
  Beschreibung Einsatzort, Windeneinsatz (Cycles 0–8, Cycles mit Patient,
  Luftverladung), Bergwacht (Bereitschaft aus Stammdaten + Namen/Infos),
  Anderer Notarzt, Weitere Rettungsmittel — alle als echte DB-Spalten.
- **Tagestabelle:** Spalten Nr./Einsatzort/Winde/Bergwacht, klickbare
  Spaltensortierung (Standard: Alarmierungszeit); Dauer strikt aus Phase 9 —
  ohne Phase 9 steht dort „kein Ende". Einsatz-Titel „Einsatz N · Zeit"
  (N = Tagesnummer nach Alarmierungszeit).
- **Einsatz löschen:** Button mit Bestätigung in der Einsatzansicht; Sperrliste
  verhindert Wiederanlage durch gepufferte Uhr-Daten (Einträge verfallen nach
  90 Tagen über den Aufräumjob).
- **Einsatzort:** Adressfeld mit Photon-Autocomplete (OSM, kostenlos, ohne
  Schlüssel) im Formular — auch für Uhr-Einsätze; Pin auf Einsatz- und
  Tageskarte.
- **Phasen-Marker:** Phasennummern an der GPS-Position auf dem Einsatz-Track
  (Kachel-Design, zoomfest, gestapelte versetzt), Umschalter unter der Karte;
  Hover-/Tipp-Kopplung in beide Richtungen zwischen Phasen-Tabelle und Karte.
- CSS-Fix: `hidden`-Attribut greift jetzt überall (u. a. Rollenfelder am
  Flugtag verschwinden korrekt).
- **Administration:** Klick auf eine NutzerIn öffnet die Editierseite (Rolle,
  E-Mail, neues Passwort, verbundene Geräte mit Aktivieren/Deaktivieren und
  Löschen). Admin-Geräteanlage ersatzlos entfernt (Selbstverwaltung genügt).
- **Geräte löschen ohne Datenverlust:** Löschen (mit Bestätigung, in
  Einstellungen → Geräte und auf der Admin-Editierseite) entfernt nur den
  Zugang — Einsätze und Tracks bleiben erhalten (Migration entkoppelt die
  Datenbank-Kaskade). Deaktivieren bleibt als sanfte Option.
- **Stammdaten** (Einstellungen → Stammdaten): Standorte, Hubschrauber mit
  Kennung und Rollen-Häkchen (Pilot 1/2, HEMS, Flugretter, Sonstige),
  Besatzungs-Vorbelegungen je Rolle, Bergwacht-Bereitschaften.
- **Flugtag mit Dropdowns:** Maschine und Standort aus den Stammdaten; die
  beim Hubschrauber angehakten Rollen erscheinen als Besatzungs-Dropdowns
  (gespeist aus den Vorbelegungen). Freitextfeld „Besatzung" entfällt; alte
  Freitext-Werte bleiben lesbar („alt"-Hinweis).
- **Web-Navigation neu:** Kopfleiste mit Vogel-Icon und „Einsatzdokumentation
  Luftrettung – Name" (Name im neuen Profil setzbar, sonst E-Mail); Menüs
  Übersicht / Administration / ⚙ Einstellungen (Profil, Geräte, Abmelden);
  „Verwaltung" heißt jetzt Administration. Geräte sind in die Einstellungen
  umgezogen (alte Adresse leitet weiter).
- **Profil:** Name und E-Mail änderbar; Passwortwechsel nur mit korrektem
  aktuellen Passwort (Migration: Namensfeld).
- Einsatztage-Leiste auf allen Inhaltsseiten (auch Einsatzansicht und
  Formular); Tagesklick öffnet die Übersicht des Tages. Einsatzansicht
  mittig. Fußzeile „© Gen-EM – OpenSource Software – AGPL-3.0" im
  Dokumentfluss rechts unter dem Inhalt, auch mobil.
- **Uhr-Paket:** Kartenmodus-Fix (Tasten werden im Browse-Modus ans System
  durchgereicht — Garmins Zoom/Verschieben erscheint); 2× Vibration nach
  „Dienst beginnen"; Rea-Gesamtdauer in Ziffernschrift (~50 % größer); neue
  **Statistik-Ansicht** (Einsätze/Alarmierungen des Tages); Hauptanzeige mit
  größerer, mittigerer Uhrzeit und Phase im unteren Drittel;
  **„Einsatztag beenden" sendet, bestätigt und schließt die App** — bei
  Sendeproblemen Rückfrage „Trotzdem beenden?" mit Warten-Option.
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
- Navigation: Auf der Geräte-Seite tauschten „Geräte" und „Verwaltung" beim
  Klick die Plätze (abweichende Link-Reihenfolge).
- Migration „Mehrere Reanimationen": Ersatzindex vor dem Entfernen des
  UNIQUE (MySQL 1553); Runner überspringt bereits erledigte Einzelschritte.

### Installation
- `schema.sql` legt die Migrations-Buchführung (`schema_migrations`) an und
  trägt alle bisherigen Migrationen als erledigt ein — eine frische
  Installation ist sofort auf Endstand.
- Der Installer löschte beim Zurücksetzen nur neun alte Tabellen; die Liste
  wird jetzt aus `schema.sql` gelesen und bleibt automatisch vollständig.
- Neue Migration „Papierkorb" (`deleted_at`, `deleted_with_day`).


## [Uhr 1.3.6] — 2026-07-22

- **Einrichtung in der richtigen Reihenfolge (v1.3.6):** Fehlt die
  Server-Adresse, weist die Uhr jetzt darauf hin, sie in Garmin Connect
  einzutragen — vorher kam zuerst „Nicht gekoppelt", und der Kopplungsversuch
  scheiterte anschließend mit „Erst Server-Domain setzen". Neue Prüfung
  `Uploader.hasServer()`.
- Einstellungstexte neutral gefasst (Beispiel `einsatz.beispiel.de` statt der
  eigenen Domain) und der Hinweis ergänzt, dass Geräte-ID und API-Schlüssel
  beim Koppeln automatisch gesetzt werden.
- **Kartenseite entfernt (v1.3.5):** Sie funktionierte auf dem Gerät nicht
  zuverlässig und wurde vollständig aus dem Code genommen (`MapPage.mc`
  gelöscht, kein Rest im Pager). Der Pager läuft jetzt Uhr → Tempo →
  Statistik → Sync → Rea. Eine spätere Kartenansicht wird neu aufgebaut; die
  alte Fassung steckt bei Bedarf in der Git-Historie.
- **Neues Launcher-Icon (v1.3.4):** Hubschrauber-Bildmarke in 40x40, aus der
  hellen Fassung erzeugt — auf dem schwarzen App-Menü der Fenix bleibt damit
  die ganze Silhouette sichtbar (die farbige Fassung ist zur Hälfte dunkel und
  wäre dort halb verschwunden). Motiv mittig auf transparenter Fläche, also
  ohne Verzerrung.
- **Tastensperre öffnet nicht mehr das Schnellmenü (v1.3.3):** Kommt während
  des langen START-Drucks eine beliebige weitere Taste dazu, wertet die App das
  als Sperr-Kombination der Uhr — das Menü bleibt zu, und auch die Seitenwahl
  springt nicht an. Der lange Druck allein öffnet das Menü unverändert. Gleiche
  Absicherung in der Reanimations-Ansicht, wo langes UP/DOWN Adrenalin und
  Rhythmus markiert.
- **Rea-Menü neu:** groß umrahmte Felder (~4 je Seite, größere Schrift),
  Gruppen mit dünnen Trennlinien (Rhythmuskontrolle/Defibrillation ·
  Adrenalin/Amiodaron · **Zugang** [neues Ereignis]/Intubation/Sonographie ·
  ROSC/Tod · Übersicht), dicke Linie vor **„Rea BEENDEN"** (vorher „ENDE").
  Server und Doku kennen den Ereignistyp `zugang`.
- **Einsatzzähler:** Die Statistik zählt nur noch abgeschlossene Einsätze
  (Alarmierung + dokumentiertes Ende); der laufende zählt nicht mehr mit.
- **Sync-Seite:** Grün „Sync vollständig ✓", sobald kein Rückstand besteht —
  das konstruktionsbedingt immer offene laufende Ruhesegment zählt nicht mehr
  als „offenes Paket". Der Koppel-Hinweis erscheint nur noch ungekoppelt.
- App-Version 1.2.0 (Sync-Seite).
- **Geräte-Kopplung umgezogen:** Die Code-Eingabe liegt jetzt auf der
  Sync-/Versionsseite und startet mit **START gedrückt halten** (1 s) — die
  frühere „UP halten"-Geste auf dem Startbildschirm löste auf dem Gerät nicht
  zuverlässig aus. Der Startbildschirm zeigt ungekoppelt den Hinweis
  „Nicht gekoppelt — DOWN drücken"; die Kopplungs-Rückmeldung („Gekoppelt ✓")
  erscheint auf der Sync-Seite.
- **Absturz bei Ablauf des 2:00-Timers:** Das 5×-Vibrationsmuster überschritt
  Garmins Hardware-Limit von 8 Vibrationsprofilen. Muster jetzt gesplittet
  (3 + 2 Pulse); alle Vibrationsaufrufe zusätzlich abgesichert.
- **Karte:** Eigene Zoom-Steuerung statt des unzuverlässigen System-Browse-
  Modus — kurz START = Zoom-Modus, UP/DOWN zoomen um die Position, BACK
  zurück zum Track-Fit.
- **Sync-Diagnose:** Startbildschirm und Statistik-Seite zeigen den konkreten
  Fehlergrund („Keine Server-URL", „Zugangsdaten fehlen", HTTP-Codes) statt
  nur „Sync ausstehend".
- Statistik-Seite zeigt nur noch die Einsätze des Tages (Alarmierungs-Zähler
  entfernt); Zahl deutlich größer.
- Jeder Neustart des 2:00-Zyklus (Rhythmuskontrolle, manuell, Rea-Start)
  bestätigt mit 2× Vibration.


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
