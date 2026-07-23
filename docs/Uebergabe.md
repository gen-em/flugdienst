# Projektübergabe — HEMS Einsatzdokumentation

**Stand:** 23. Juli 2026 · **Web 2.2.3** · **Uhr 1.3.6**
Dieses Dokument ist so geschrieben, dass jemand ohne Vorwissen nahtlos
weiterarbeiten kann. Es beschreibt den Ist-Zustand, die Begründungen hinter den
Entscheidungen und die Fallen, in die wir bereits getappt sind.

---

## 1. Was das Projekt ist

Eine Einsatzdokumentation für die Luftrettung (HEMS), bestehend aus zwei Teilen:

1. **Garmin-Uhr-App** (Monkey C, Connect IQ) — erfasst während des Dienstes
   Einsatzphasen per Tastendruck, zeichnet den GPS-Track auf, protokolliert
   Reanimationen und überträgt alles an den eigenen Server.
2. **Weboberfläche** (PHP + MySQL) — Nachbearbeitung, Kartenansicht,
   Auswertung, Stammdatenpflege, Backup.

Beide Teile werden **unabhängig voneinander versioniert und ausgeliefert**.

- **Lizenz:** GNU AGPL v3.0
- **Repositorium:** https://github.com/gen-em/einsatzdoku-luftrettung
- **Produktivsystem:** https://luftrettung.net
- **Zielgerät:** Garmin Fenix 6 Pro (`minApiLevel 3.1.0`)
- **Serveranforderung:** PHP ≥ 8.1 (`declare(strict_types=1)` überall), MySQL/MariaDB
- **Sprache:** Sämtliche Oberfläche, Doku und Code-Kommentare auf Deutsch.
  Code-Kommentare bewusst **ohne Umlaute** (ASCII), Oberflächentexte **mit**.

---

## 2. ⚠️ Zuerst lesen: Zustand der Auslieferung

**Das Repositorium ist NICHT auf dem aktuellen Stand.**

| Ort | Web-Version | Bemerkung |
|---|---|---|
| Arbeitsstand (dieses Paket) | **2.2.3** | vollständig, geprüft |
| Repositorium (`main`, Commit `992c63e`) | **2.1.0** | 22 Dateien veraltet |
| Produktivsystem luftrettung.net | **gemischt** | siehe unten |

Auf dem Produktivsystem lag zuletzt eine `version.php` mit 2.2.1, daneben aber
eine `index.php` von **vor** 2.2.0 — erkennbar daran, dass die Seite
`assets/favicon.png` ohne Versionsanhang auslieferte. Genau daraus entstand der
zuletzt gesuchte Fehler (fehlendes Browser-Symbol nach dem Login).

**Es gibt zwei Auslieferungswege, die sich offenbar überlagert haben:**

- `.github/workflows/deploy.yml` lädt bei jedem Push auf `main` das Verzeichnis
  `server/` per FTPS nach `./httpdocs/` hoch (Action `SamKirkland/FTP-Deploy-Action@v4.4.0`,
  Zugangsdaten in den Repository-Secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`).
- Zusätzlich wurden Dateien von Hand hochgeladen.

**Erste Aufgabe für den Nachfolger:** Diesen Zustand bereinigen. Den
Arbeitsstand vollständig ins Repositorium committen und pushen, dann prüfen, ob
die Action durchläuft und das Produktivsystem danach durchgängig 2.2.3 meldet
(Fußzeile jeder Seite). Erst danach weiterentwickeln, sonst jagt man
Phantomfehler.

---

## 3. Arbeitsweise, die sich bewährt hat

### 3.1 Ablauf

1. Repositorium nach `/home/claude/repo` klonen bzw. `git pull`.
2. Arbeitskopie unter `/home/claude/hems`.
3. **Vor jeder Lieferung** Abgleich `diff -rq repo/server hems/server` —
   sonst baut man auf einem veralteten Stand weiter.
4. Lieferung als Zip (`hems-projekt.zip`) **plus** die einzeln geänderten Dateien.
5. Der Betreiber committet und pusht selbst.

### 3.2 Harte Regeln, die aus Fehlern entstanden sind

- **Assertion-Pflicht:** Jede automatisierte Textersetzung (Python) muss
  `assert anker in inhalt` verwenden. Es gab mehrere stille Fehlschläge, bei
  denen ein Skript scheinbar lief, aber nichts änderte.
- **Marker-Verifikation gegen das fertige Zip**, nicht gegen die Arbeitskopie.
  Also `unzip -p … | grep -c …` nach dem Packen.
- **Immer die Version erhöhen** (`server/version.php`), sonst greift die
  Zwischenspeicher-Umgehung nicht und der Betreiber sieht alte Dateien.
- **CSS-Ballast:** Neue Regeln **ersetzen** statt anhängen. Mehrfach hatten sich
  bis zu fünf widersprüchliche Regelblöcke für dasselbe Element angesammelt.

### 3.3 Prüfmöglichkeiten in der Sandbox

- **PHP:** `cp config.example.php config.php && php -l datei.php && rm config.php`
  (`config.php` wird gebraucht, weil `db.php` sie einbindet).
- **JavaScript:** Skriptblöcke aus PHP extrahieren, PHP-Ausdrücke durch
  Platzhalter ersetzen, dann `node --check`.
- **Krypto:** `assets/crypto.js` lässt sich in Node testen über
  `eval(src + '; EdCrypto')` mit `global.crypto = require('crypto').webcrypto`
  sowie Stubs für `sessionStorage`, `btoa`, `atob`, `TextEncoder`.
- **Monkey C:** Es gibt keinen Compiler in der Sandbox. Geprüft wird nur
  Klammerbilanz (Strings und Kommentare vorher entfernen) und Glyph-Scan.
- **MariaDB:** In der Sandbox vorhanden, aber **instabil** — startet und stirbt
  zwischen Aufrufen. Für Datenbanktests unbrauchbar; stattdessen Logik isoliert
  in PHP nachbauen.

---

## 4. Architektur Weboberfläche

### 4.1 Verzeichnis `server/`

| Datei | Zweck |
|---|---|
| `version.php` | `const WEB_VERSION` — einzige Stelle für die Versionsnummer |
| `db.php` | PDO-Verbindung, `e()` (HTML-Maskierung), `asset()`, `favicon_tags()`, `logo_src()`, `fmt_local()` |
| `auth_guard.php` | Sitzung, Anmeldepflicht, Rollenprüfung, Zwang zur Ersteinrichtung; bindet `ui.php` ein |
| `auth_salt.php` | Liefert das Ableitungs-Salz zu einer E-Mail (JSON), für die Anmeldung |
| `login.php` / `logout.php` | Anmeldung (Token-basiert), Abmeldung |
| `reset_request.php` / `reset_confirm.php` | Passwort zurücksetzen **mit Wiederherstellungsschlüssel** |
| `einrichtung.php` | Ersteinrichtung der Verschlüsselung, Entsperren per Wiederherstellungsschlüssel |
| `index.php` | Tagesübersicht: Karte (Leaflet) + sortierbare Einsatztabelle |
| `einsatz.php` | Einzelansicht mit Karte, Phasentabelle, Reanimationsprotokoll |
| `einsatz_form.php` | Anlegen/Bearbeiten eines Einsatzes (größte Datei, 609 Zeilen) |
| `zeitraum.php` | Jahres-/Monatsübersicht als Tabelle, ohne Karte |
| `einstellungen.php` | Profil, Standortdaten, Backup, Geräte (830 Zeilen) |
| `papierkorb.php` | Gelöschte Einsätze/Flugtage, Wiederherstellen und endgültig löschen |
| `flugtag_neu.php` | Flugtag von Hand anlegen (für Tage ohne Uhr) |
| `flugtag_loeschen.php` / `einsatz_loeschen.php` | Löschbestätigungen |
| `admin.php` / `admin_user.php` | Nutzerverwaltung |
| `geraete.php` | Nur noch Weiterleitung — Geräte sind ein Reiter der Einstellungen |
| `ingest.php` | **Schnittstelle für die Uhr** — nimmt Einsätze und Ruhesegmente an |
| `pair.php` | Kopplung: Einmalcode → Geräte-ID + API-Schlüssel |
| `update.php` | Migrations-Runner (auch per CLI: `php update.php`) |
| `install.php` | Einrichtungsassistent; bindet bewusst **keine** `config.php` ein |
| `schema.sql` | Vollständiges Schema für Neuinstallationen |
| `mission_fields.php` | **Zentraler Feldkatalog** der Einsatz-Zusatzfelder |
| `backup_lib.php` | Export/Import des gesamten Datenbestands |
| `trash_lib.php` | Papierkorb-Logik (Soft-Delete, 90 Tage) |
| `ui.php` | `ui_topbar()`, `ui_days_sidebar()`, `ui_settings_sidebar()`, `ui_footer()` |
| `smtp.php` | Mailversand |
| `.htaccess` | HTTPS-Zwang, Sperre sensibler Dateien, Sicherheits-Kopfzeilen |

**`server/api/`** — JSON-Endpunkte: `day.php` (ein Flugtag inkl. Tracks),
`mission.php` (ein Einsatz), `range.php` (Jahr/Monat, **bewusst ohne Tracks**),
`backup_data.php`, `backup_restore.php`.

**`server/assets/`** — `style.css` (586 Zeilen), `crypto.js` (Verschlüsselung),
`patient.js` (Altersberechnung), `daylist.js` (Akkordeon der Tagesleiste),
`confirm.js` (Bestätigungsdialoge), `images/` (Logo als SVG farbig + weiß,
`favicon.png`). Zusätzlich `server/favicon.ico` im Wurzelverzeichnis.

### 4.2 Datenbank

Tabellen: `users`, `password_resets`, `devices`, `missions`, `mission_phases`,
`resus_sessions`, `resus_events`, `rest_segments`, `bases`, `aircraft`,
`crew_presets`, `resources`, `mission_resources`, `bw_units`, `days`,
`pair_codes`, `deleted_refs`, `app_state`, `track_points`, `schema_migrations`.

**`users`** (verschlüsselungsrelevant):
```
password_hash  Hash des Auth-Tokens (nicht des Passworts!)
kdf_salt       Salz der Browser-Schlüsselableitung (hex, 32 Zeichen)
kdf_ver        1 = abgeleitetes Token (0 existiert nicht mehr, siehe 6.3)
pat_wrap_pw    Inhaltsschlüssel, mit dem Passwort-Schlüssel verpackt
pat_wrap_rc    Inhaltsschlüssel, mit dem Wiederherstellungsschlüssel verpackt
```

**`missions`** — Klartextspalten für alles Unkritische (`day`, `started_at`,
`distance_m`, `winch`, `bergwacht`, `secondary`, `schockraum`, `mission_no`,
`transport_dest`, `site_desc`, `notes` …) plus **`pat_blob`** für alles
Geschützte. `deleted_at` und `deleted_with_day` für den Papierkorb. `manual = 1`
bedeutet: von Hand bearbeitet, die Uhr überschreibt nicht mehr.

**Migrationen** (`update.php`, in dieser Reihenfolge):
```
2026_07_16_mehrere_reanimationen   2026_07_20_einsatzfelder_ort
2026_07_17_flugtage                2026_07_20_stammdaten_defaults
2026_07_17_wartung                 2026_07_20_kopplung
2026_07_18_geraete_status          2026_07_20_patientinnendaten
2026_07_18_manuelle_einsaetze      2026_07_21_pflicht_e2e
2026_07_19_phase10_entfernen       2026_07_22_tag_zuordnung
2026_07_19_profil_name             2026_07_22_papierkorb
2026_07_19_geraete_entkoppeln      2026_07_23_sekundaer_schockraum
2026_07_19_stammdaten              2026_07_24_rettungsmittel
```

> **Regel:** Neue Migration → ID **zusätzlich** in die `skipped`-Liste am Ende
> von `schema.sql` eintragen, damit Neuinstallationen sie nicht unnötig
> ausführen.

### 4.3 Verschlüsselung (Kernstück)

**Ende-zu-Ende, verpflichtend.** Der Server sieht geschützte Daten nie im Klartext.

```
Passwort ──PBKDF2-SHA256(310 000 Runden, kdf_salt)──> { authToken, dataKey }
                                                          │        │
                          an den Server (dort gehasht) ◄──┘        │
                                                                   ▼
                                        entpackt users.pat_wrap_pw ──> CK
Wiederherstellungsschlüssel ──> rcKey ──entpackt pat_wrap_rc────────> CK
                                                                     │
                                              missions.pat_blob ◄────┘
                                              AES-256-GCM
```

- **CK (Inhaltsschlüssel):** 256 Bit Zufall, **nicht** vom Passwort abgeleitet.
  Deshalb kostet ein Passwortwechsel kein Neuverschlüsseln der Daten — nur die
  Verpackung wird erneuert.
- **`pat_blob`** enthält: `{ last, first, dob, dx, age, loc: { addr, lat, lon } }`
- **Wiederherstellungsschlüssel:** Base32, 20 Zeichen, Format
  `ABCD-EFGH-JKMN-PQRS-TVWX`, rund 98 Bit Entropie. Eingabe ist tolerant
  (Kleinschreibung, Leerzeichen statt Bindestriche).
- **Sitzung:** Der Datenschlüssel liegt im `sessionStorage`.
- **`assets/crypto.js` (EdCrypto)** exportiert: `deriveKeys`, `encrypt`,
  `decrypt`, `randomHex`, `newRecoveryCode`, `recoveryKeyHex`, `setDataKey`,
  `getDataKey`, `getContentKey`, `clearSession`, `sealBackup`, `openBackup`,
  `isBackupFile`.

**Was NICHT verschlüsselt ist** (bewusste Entscheidung, siehe 6.6):
GPS-Tracks, Zeiten, `site_desc`, Häkchenfelder, Notizen.

### 4.4 Versionierung und Zwischenspeicher

`asset('assets/style.css')` liefert `assets/style.css?v=2.2.3`. Die Nummer wird
**zur Laufzeit** aus `version.php` gelesen — eine Versionsänderung erfordert
also nur diese eine Datei. Alle ~30 Einbindungen laufen darüber.

`favicon_tags()` erzeugt zentral drei Verweise (PNG, ICO, apple-touch-icon),
wurzelbezogen über `SCRIPT_NAME`.

### 4.5 Wiederkehrende UI-Bausteine

- **Tagesleiste** (`ui_days_sidebar`): Jahr → Monat → Tage als verschachtelte
  `<details>`. PHP bestimmt, welches Jahr/welcher Monat offen ist;
  `daylist.js` erzwingt das Akkordeon (nur eines offen je Ebene) und trennt die
  Klickbereiche: Beschriftung → `zeitraum.php`, Dreieck → nur auf/zu.
- **Bestätigungsdialoge** (`confirm.js`): über `data-confirm`,
  `data-confirm-ok`, `data-confirm-tone="normal|danger"`.
- **Farben:** `--accent` Orange (Hauptaktionen), `--rot` (Löschen),
  `--blau` #4280E5 „Max-Blau" (Fokus, Sortierpfeile, Kontrollkästchen,
  „Flugtag anlegen"), `--navy` (Kopfleiste), `--schnee`/`--rauch` (Zebrastreifen).

---

## 5. Architektur Uhr-App

### 5.1 Dateien (`watch/source/`)

| Datei | Zweck |
|---|---|
| `HemsApp.mc` | Einstiegspunkt |
| `StartView.mc` | Startbildschirm „Dienst beginnen“; zeigt Hinweise zu Server-Adresse und Kopplung |
| `Const.mc` | `APP_VERSION`, Phasenbeschriftungen, Reanimations-Ereignistypen, GPS-Ausdünnung |
| `Nav.mc` | Pager: `[:clock, :speed, :stats, :sync, :cpr]` |
| `ClockView.mc` | Hauptanzeige, Phasenschaltung, Schnellmenü |
| `SpeedView.mc` / `StatsView.mc` | Tempo, Statistik |
| `SyncView.mc` | Übertragungsstand, Kopplung, Versionsanzeige |
| `CprView.mc` / `Cpr.mc` | Reanimationsprotokoll |
| `Model.mc` | Zustand, Warteschlange |
| `Track.mc` | GPS-Aufzeichnung, Ausdünnung |
| `Uploader.mc` | HTTP, `hasCredentials()`, `hasServer()`, `serverBase()` |
| `Pair.mc` | Kopplung per Einmalcode |
| `Util.mc` | Hilfsfunktionen |

### 5.2 Konfiguration

- `manifest.xml`: **UUID ist noch der Platzhalter** `a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6`
  — bewusst nicht geändert (siehe To-Dos).
- `resources/settings/properties.xml`: `serverUrl`, `deviceId`, `apiKey` —
  alle **ohne Vorgabewert**. Die Adresse trägt jeder Nutzer in Garmin Connect
  ein; Geräte-ID und Schlüssel füllt die Kopplung automatisch.
- `resources/drawables/launcher_icon.png`: 40×40, aus der **hellen** Logofassung
  (die farbige ist zur Hälfte dunkel und wäre auf dem schwarzen App-Menü
  unsichtbar).

### 5.3 Schnittstelle zum Server

`POST /ingest.php` mit Kopfzeilen `X-Device-Id` und `X-Api-Key`.
Rumpf: `{ kind: "mission"|"rest_segment", client_ref, day, started_at,
ended_at, … }`. Details in `docs/JSON-Vertrag.md`.

`client_ref` + `device_id` bilden einen eindeutigen Schlüssel — mehrfaches
Senden desselben Einsatzes ist unschädlich. Einträge, die im Papierkorb liegen,
quittiert der Server und verwirft sie, damit die Uhr sie nicht endlos erneut sendet.

---

## 6. Technische Entscheidungen und ihre Begründung

### 6.1 Zentraler Feldkatalog statt verstreuter Formularfelder
`mission_fields.php` definiert alle Zusatzfelder einmal. Formular, API und
Anzeige lesen daraus. Ein neues Feld kostet eine Migration plus einen Eintrag.
Ausnahme: `other_resources` hat den Sondertyp `resources` und **keine**
Datenbankspalte (siehe 6.5).

### 6.2 Inhaltsschlüssel getrennt vom Passwort
Weil sonst jeder Passwortwechsel ein vollständiges Neuverschlüsseln erzwingen
würde. Dieses Muster nutzen auch etablierte Passwortmanager.

### 6.3 Unterstützung unverschlüsselter Konten vollständig entfernt (2.1.0)
Auf ausdrücklichen Wunsch, da alle Konten neu aufgesetzt wurden. Damit
verschwand auch die letzte Stelle, an der ein Passwort im Klartext zum Server
ging. Browser ohne Web-Krypto bekommen eine klare Fehlermeldung statt eines
stillen Rückfalls.

### 6.4 Alter wird berechnet, nicht gespeichert
Bezogen auf den **Einsatztag**, nicht auf heute — ein Einsatz von vor drei
Jahren zeigt weiterhin das damalige Alter. Ist ein Geburtsdatum vorhanden, wird
das Feld gesperrt und gar nicht gespeichert; ohne Geburtsdatum (unbekannte
Person, im Rettungsdienst häufig) bleibt es von Hand eintragbar. Gemeinsame
Berechnung in `assets/patient.js`.

### 6.5 Rettungsmittel als eigene Datensätze
`resources` (Vorbelegung je Nutzer) und `mission_resources` (Zuordnung je
Einsatz). Grund: Jedes Rettungsmittel soll einzeln entfernbar sein, und das
Löschen einer Vorbelegung darf dokumentierte Einsätze nicht verändern. Die
Migration teilt bestehende Freitexte an Komma und Semikolon auf.

### 6.6 Tracks bleiben unverschlüsselt
Ohne Klartextkoordinaten gäbe es keine Karten. Bewusster Kompromiss — mit der
Einschränkung, dass sich der Einsatzort aus dem Trackende rekonstruieren lässt.
**Für eine Veröffentlichung muss die Beschreibung präzise sagen, was geschützt ist.**

### 6.7 `api/range.php` ohne Trackpunkte
Bei einem ganzen Jahr wären es Hunderttausende Koordinaten, die niemand sieht —
die Zeitraumansicht hat bewusst keine Karte.

### 6.8 Kartenseite der Uhr entfernt (1.3.5)
Funktionierte auf dem Gerät nicht zuverlässig. `MapPage.mc` wurde **gelöscht**,
nicht deaktiviert — auf Wunsch soll eine künftige Kartenansicht von Grund auf
neu entstehen. Die alte Fassung steckt in der Git-Historie.

---

## 7. Gelöste Hürden — bitte lesen, bevor etwas geändert wird

### 7.1 `.btn-primary` trägt global `width:100%`
Für Formulare gewollt. In jedem anderen Zusammenhang (Aktionsleisten,
Dialoge, Tabellenzeilen) muss **ausdrücklich `width:auto`** gesetzt werden.
Dieser Fehler trat **dreimal** auf und kostete jedes Mal eine Runde, weil das
Symptom („Knopf zu breit") nach Ausrichtung aussieht, aber Breite ist. Alle
bekannten Stellen sind inzwischen abgesichert.

### 7.2 Spezifitätsfalle bei `.daylist a`
`.daylist a{display:block}` (0-1-1) schlägt `.trashlink{display:flex}` (0-1-0)
und steht zudem weiter unten. Das gewünschte `display:flex` griff daher **nie**;
Symbol und Text lagen monatelang nur auf der Schriftlinie. Erst als zusätzlich
`display:block` gesetzt wurde, stapelten sie sichtbar — und der Fehler fiel auf.
Regel: Selektoren innerhalb der Menüspalte müssen `.daylist a.klasse` lauten
und **nach** `.daylist a` stehen.

### 7.3 PHP wandelt numerische Array-Schlüssel in Ganzzahlen
`$baum["2026"]` wird zu `$baum[2026]`. Unter `strict_types` bricht `e()` dann
mit einem TypeError ab — die Seite lieferte **gar nichts** mehr aus. Zusätzlich
schlug ein Monatsvergleich ab Oktober fehl, weil `"12"` zur Zahl wird, `"07"`
wegen der führenden Null aber Text bleibt. Überall `(string)`-Umwandlung und
`str_pad` verwenden.

### 7.4 Passwort-Reset zerstörte das Konto (behoben in 2.1.0)
`reset_confirm.php` speicherte den Hash des **rohen Passworts**, während die
Anmeldung den Hash des **abgeleiteten Tokens** erwartet — nach einem Reset war
keine Anmeldung mehr möglich. Zusätzlich wurde der Inhaltsschlüssel nicht neu
verpackt, wodurch alle verschlüsselten Daten unlesbar geworden wären. Jetzt
verlangt der Reset den Wiederherstellungsschlüssel, und der Server schreibt
Token-Hash, Salz und Verpackung in **einer Transaktion**.

### 7.5 Fußzeile außerhalb des Inhaltsbereichs
Auf fünf neu angelegten Seiten stand `ui_footer()` hinter `</main>` statt davor
— Copyright und Lizenz waren unsichtbar. Immer **innerhalb** von `</main>`.

### 7.6 Doppelte Einbindung → HTTP 500
`require` statt `require_once` für `ui.php`, obwohl `auth_guard.php` sie schon
lädt → „Cannot redeclare". Neue Seiten immer mit `require_once`.

### 7.7 Fokusringe umschließen aufgeklappte Bereiche
`summary:focus` legt den Ring um den **gesamten** geöffneten Inhalt. Lösung:
`:focus-visible` statt `:focus`, und für `summary` eine Hintergrundfärbung
statt eines Rahmens.

### 7.8 Zwischenspeicher-Falle bei Bildern und Stylesheets
War der Grund für mehrere „der Fix wirkt nicht"-Runden. Seit 2.0.0 gelöst durch
`asset()`. **Version immer erhöhen.**

### 7.9 Wirkungslose Konfiguration `logo_path`
Stand jahrelang in der Konfiguration, wurde aber nie ausgewertet; der
Vorgabewert zeigte auf eine nie existierende Datei. Beim Scharfschalten in
2.2.0 blieb dadurch das Logo weg, weil bestehende `config.php` den alten Wert
enthielten. `logo_src()` prüft jetzt den tatsächlichen Dateibestand.

---

## 8. Was funktioniert

**Uhr (1.3.6):** Phasenerfassung, GPS-Aufzeichnung mit Ausdünnung,
Reanimationsprotokoll, Übertragung mit Warteschlange, Kopplung per Einmalcode,
Dienstende beendet die App sauber, Tastensperre löst kein Menü mehr aus
(Vorbehalt: am Gerät noch nicht bestätigt), Store-taugliche Einstellungstexte.

**Web (2.2.3):** Anmeldung mit Schlüsselableitung im Browser, Pflicht-Ende-zu-Ende-
Verschlüsselung mit Ersteinrichtung und Wiederherstellungsschlüssel,
Passwort-Reset, Tagesübersicht mit Karte und sortierbarer Tabelle,
Einzelansicht, Anlegen/Bearbeiten von Einsätzen, Flugtag von Hand anlegen,
Jahres-/Monatsübersicht, Papierkorb mit 90 Tagen, Stammdaten in fünf
aufklappbaren Abschnitten, Rettungsmittel mit Vorschlagssuche, geschützte
Patientenfelder inklusive berechnetem Alter, Backup mit Ver- und
Entschlüsselung, Nutzerverwaltung, Migrations-Runner.

---

## 9. Offene Aufgaben

### 9.1 Sofort (Blockierer)
1. **Repositorium und Produktivsystem auf 2.2.3 bringen** (siehe Abschnitt 2).
   Danach prüfen: Fußzeile zeigt überall 2.2.3, Quelltext enthält
   `/assets/images/favicon.png?v=2.2.3`.
2. **Alte Bilddateien vom Server löschen:** `assets/logo.png`,
   `assets/logo-weiss.png`, `assets/icon-weiss.png`, `assets/favicon.png`.
3. **`server/favicon.ico`** muss im Wurzelverzeichnis landen (neue Datei).

### 9.2 Zuletzt besprochen, noch nicht umgesetzt
4. **`asset()` auf Datei-Zeitstempel umstellen** — der Betreiber hat gefragt, ob
   nicht jede Datei ihren eigenen Stempel bekommen sollte, statt bei jedem
   Versionssprung alles neu zu laden. Vorschlag steht, Zustimmung offen:
   ```php
   function asset(string $pfad): string {
       $zeit = @filemtime(__DIR__ . '/' . $pfad);
       return $pfad . ($zeit ? '?v=' . $zeit : '');
   }
   ```
   `WEB_VERSION` bliebe für Fußzeile und Changelog erhalten.
   Vorbehalt: FTP-Übertragung setzt Zeitstempel teils neu.

### 9.3 Haltebank (vom Betreiber ausdrücklich vertagt)
5. **Eindeutige App-Kennung** für den Connect-IQ-Store erzeugen. **Achtung:**
   Dabei gehen die auf der Uhr gespeicherten Einstellungen verloren — die
   Server-Adresse muss einmalig neu eingetragen werden.
6. **Argon2id statt PBKDF2** — technisch möglich nur über eine
   WebAssembly-Bibliothek (`hash-wasm`, MIT, ~30 KB, lokal einbinden).
   Zwei Migrationswege wurden besprochen: (A) transparente Umstellung beim
   Anmelden mit `kdf_ver = 2`, (B) harter Schnitt über den Reset.
   Empfehlung war A. OWASP-Richtwert: mindestens 19 MiB / 2 Iterationen,
   solider 64 MiB / 3.
7. **Content-Security-Policy** als zusätzliche Verteidigungslinie ergänzen.
8. **PBKDF2-Runden** von 310 000 auf 600 000 anheben und Mindestpasswortlänge
   von 10 auf 12–14 Zeichen (falls Argon2id nicht kommt).
9. **Logo-SVG:** liegt vor. **Favicon** bleibt bewusst PNG/ICO.

### 9.4 Am Gerät zu prüfen
10. **Tastensperre** (1.3.3): Falls die Firmware die Tastenereignisse gar nicht
    an die App durchreicht, greift die Prüfung nicht. Ersatzlösung wäre, das
    Menü erst beim **Loslassen** von START zu öffnen.
11. **Neues Launcher-Icon** (1.3.4) auf dem Gerät begutachten.

### 9.5 Vor einer Store-Veröffentlichung
12. Datenschutzerklärung erstellen und verlinken.
13. In der Beschreibung klarstellen: eigener Server nötig, **HTTPS Pflicht**
    (Connect IQ verweigert unverschlüsselte Verbindungen), und **welche** Daten
    verschlüsselt sind (siehe 6.6).

---

## 10. Hinweise zum Stil der Zusammenarbeit

Der Betreiber ist Notfallmediziner, technisch versiert, arbeitet aber nicht
hauptberuflich als Entwickler. Bewährt hat sich:

- **Deutsch**, präzise, ohne Fachjargon-Nebel.
- **Ursachen benennen, nicht nur Symptome beheben.** Mehrfach hat sich gezeigt,
  dass ein Symptom drei Runden überlebt, weil die eigentliche Ursache
  woanders lag (siehe 7.1 und 7.2).
- **Eigene Fehler klar benennen.** Das hat wiederholt Zeit gespart.
- **Vor größeren Umbauten das Verständnis abgleichen** — der Betreiber
  formuliert Anforderungen präzise und korrigiert gern vorab.
- **Bei Vermutungen ehrlich sein.** Beim Favicon-Problem führten zwei falsche
  Hypothesen in die Irre; erst der Blick des Betreibers in den ausgelieferten
  Quelltext brachte die Lösung (veraltete Dateien auf dem Server).
- Lieferung immer als Zip **plus** Einzeldateien, mit kurzer Angabe, was zu tun
  ist (hochladen, `update.php` aufrufen, Version prüfen).
