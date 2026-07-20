# Backup-Dateiformat (`.edbak`)

Der Export sichert **alle** Daten einer NutzerIn in einer einzelnen,
passwortverschlüsselten Datei. Seit **Version 2** passieren Ver- und
Entschlüsselung vollständig im Browser (`assets/crypto.js`) — der Server sieht
zu keinem Zeitpunkt Klartext. Weil die geschützten Angaben dabei entschlüsselt
in den Container wandern und beim Import mit dem Schlüssel des Zielkontos neu
verschlüsselt werden, lässt sich ein Backup **in jedes Konto** einspielen.

## 1. Container, Version 2

| Bytes   | Inhalt                                                          |
|---------|-----------------------------------------------------------------|
| 0–7     | Magie: ASCII `EDBAK2` + `0x00` + Formatversion `0x02`           |
| 8       | Flag: `1` = Inhalt gzip-komprimiert, `0` = roh                  |
| 9–24    | Salt für die Schlüsselableitung (16 Bytes, zufällig)            |
| 25–36   | AES-GCM-Initialisierungsvektor (12 Bytes, zufällig)             |
| ab 37   | Chiffretext, die letzten 16 Bytes sind das GCM-Auth-Tag         |

- **Schlüssel:** `PBKDF2-SHA256(Backup-Passwort, Salt, 310 000 Runden, 32 Bytes)`
- **Verfahren:** AES-256-GCM; die ersten **9 Bytes** (Magie + Flag) sind als
  *additional authenticated data* gebunden. Jede Änderung am Kopf oder am
  Inhalt lässt die Entschlüsselung scheitern — kein stilles Korrumpieren.
- **Klartext:** JSON (UTF-8), bei gesetztem Flag gzip-komprimiert.

Entschlüsselung von Hand (Beispiel, Python):

```python
import hashlib, gzip, json
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

b = open('backup.edbak', 'rb').read()
key = hashlib.pbkdf2_hmac('sha256', passwort.encode(), b[9:25], 310_000, 32)
roh = AESGCM(key).decrypt(b[25:37], b[37:], b[0:9])
daten = json.loads(gzip.decompress(roh) if b[8] == 1 else roh)
```

## 2. Inneres JSON

```jsonc
{
  "format": "einsatzdoku-backup",       // Kennung, immer dieser Wert
  "version": 2,
  "created_at": "2026-07-20T18:00:00+00:00",   // Export-Zeitpunkt (UTC)
  "app": "einsatzdoku-luftrettung",
  "user": { "email": "...", "name": "..." },   // informativ

  "stammdaten": {
    "bases":        [ { "name": "Kempten", "is_default": 1 } ],
    "aircraft":     [ { "registration": "Christoph 17", "p1": 1, "p2": 0,
                        "hems": 1, "fr": 0, "other": 0, "is_default": 1 } ],
    "crew_presets": [ { "role": "p1|p2|hems|fr|other", "name": "…" } ],
    "bw_units":     [ { "name": "Bereitschaft Oberstdorf" } ]
  },

  // Flugtage; Maschinen-/Standort-Verweise sind als NAMEN aufgelöst
  // (aircraft_reg / base_name), damit das Backup portabel ist.
  "days": [ {
    "day": "2026-07-19",
    "aircraft_reg": "Christoph 17", "base_name": "Kempten",
    "crew_p1": "…", "crew_p2": null, "crew_hems": "…",
    "crew_fr": null, "crew_other": null, "notes": "…"
  } ],

  "missions": [ {
    "client_ref": "m-1721383200",       // eindeutige Referenz (Dubletten-Schutz)
    "day": "2026-07-19",                // lokales Datum des Einsatzbeginns
    "started_at": "2026-07-19 08:15:00",  // DATETIME, UTC
    "ended_at":   "2026-07-19 09:02:00",  // null = kein Abschluss
    "manual": 0, "final": 1,
    "distance_m": 38400, "ascent_m": 550,
    "mission_no": "…", "transport_dest": "…", "site_desc": "…",
    "winch": 0, "winch_cycles": null, "winch_cycles_pat": null,
    "winch_airload": 0, "bergwacht": 0, "bw_unit": null, "bw_info": null,
    "other_ema": null, "other_resources": null, "notes": null,

    // Geschützte Angaben — im Container KLARTEXT (der Container selbst ist
    // ja verschlüsselt). Beim Import werden sie mit dem Inhaltsschlüssel des
    // Zielkontos verschlüsselt und als `pat_blob` gespeichert.
    "pat": { "dx": "Polytrauma", "age": 41,
             "loc": { "addr": "Ringstr. 18, 87439 Kempten",
                      "lat": 47.72, "lon": 10.31 } },
    // "pat_unreadable": true  -> stand beim Export nicht zur Verfügung

    "phases": [ { "phase": 2, "occurred_at": "2026-07-19 08:15:00",
                  "lat": 47.72, "lon": 10.31 } ],
    "resus": [ { "started_at": "2026-07-19 08:40:00",
                 "events": [ { "type": "adrenalin",
                               "occurred_at": "2026-07-19 08:43:00" } ] } ],
    "track": [ [0, 47.72, 10.31, 712.5, 1721383200] ]
    //          seq  lat    lon    ele    ts(Unix-Sekunden UTC); ele kann null sein
  } ],

  "rest_segments": [ {
    "client_ref": "r-…", "day": "2026-07-19",
    "started_at": "…", "ended_at": "…", "final": 1,
    "track": [ [0, 47.72, 10.31, 712.5, 1721383200] ]
  } ]
}
```

### Feldkonventionen

- Zeitstempel `started_at`/`ended_at`/`occurred_at`: `YYYY-MM-DD HH:MM:SS` in
  **UTC**; Trackpunkt-`ts` ist Unix-Epoche in Sekunden (UTC).
- `day` ist das **lokale** Kalenderdatum des Beginns (Tageswechsel 0:00).
- Zusatzfelder der Einsätze folgen `server/mission_fields.php`; künftige
  Versionen können Felder ergänzen (Import ignoriert Unbekanntes).

## 3. Import-Verhalten

- Import immer in das **eigene, angemeldete** Konto; bestehende Daten werden
  nie überschrieben.
- Dubletten-Erkennung: Einsätze und Ruhesegmente über `client_ref`, Flugtage
  über das Datum, Stammdaten über ihre Namen — Vorhandenes wird übersprungen,
  nur Fehlendes ergänzt. Der Import ist damit gefahrlos wiederholbar.
- Die geschützten Angaben werden vor dem Senden im Browser mit dem
  Inhaltsschlüssel des Zielkontos verschlüsselt; der Server speichert nur
  Chiffretext.
- Standard-Markierungen (★) werden nur importiert, wenn noch kein Standard
  gesetzt ist (es bleibt bei genau einem).

## 4. Altformat Version 1 (nur noch Import)

Dateien mit der Magie `EDBAK1` stammen aus der früheren, serverseitigen
Fassung: Kopf `EDBAK1 0x00 0x01`, danach Salt (16), IV (12), GCM-Tag (16) und
AES-256-GCM über gzip-JSON, Schlüssel per PBKDF2-SHA256 mit **200 000** Runden,
AAD = die 8 Magie-Bytes. Der Import erkennt solche Dateien automatisch und
verarbeitet sie auf dem Server weiter.

**Wichtige Einschränkung:** In Format 1 liegen die geschützten Angaben als
Chiffretext des Ursprungskontos im Backup. Sie lassen sich deshalb nur im
selben Konto lesen — in ein anderes Konto kommen nur die übrigen Daten
(Einsätze, Zeiten, Tracks, Flugtage, Stammdaten). Genau diese Einschränkung
behebt Format 2.
