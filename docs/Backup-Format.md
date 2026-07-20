# Backup-Dateiformat (`.edbak`), Version 1

Der Export sichert **alle** Daten einer NutzerIn in einer einzelnen,
passwortverschlüsselten Datei. Dieses Dokument beschreibt den Aufbau so
vollständig, dass die Datei auch ohne die Web-App gelesen werden kann.

## 1. Äußerer Container (binär)

| Bytes   | Inhalt                                                        |
|---------|---------------------------------------------------------------|
| 0–7     | Magie: ASCII `EDBAK1` + `0x00` + Formatversion `0x01`         |
| 8–23    | Salt für die Schlüsselableitung (16 Bytes, zufällig)          |
| 24–35   | AES-GCM-Initialisierungsvektor (12 Bytes, zufällig)           |
| 36–51   | GCM-Authentifizierungs-Tag (16 Bytes)                         |
| ab 52   | Chiffretext                                                   |

- **Schlüssel:** `PBKDF2-SHA256(Passwort, Salt, 200 000 Runden, 32 Bytes)`
- **Verfahren:** AES-256-GCM; die 8 Magie-Bytes sind als *additional
  authenticated data* gebunden — jede Änderung an Kopf oder Inhalt lässt die
  Entschlüsselung mit einem Fehler scheitern (kein stilles Korrumpieren).
- **Klartext:** gzip-komprimiertes JSON (UTF-8), Aufbau siehe unten.

Entschlüsselung von Hand (Beispiel, PHP):

```php
$b = file_get_contents('backup.edbak');
$key = hash_pbkdf2('sha256', $passwort, substr($b, 8, 16), 200000, 32, true);
$json = gzdecode(openssl_decrypt(substr($b, 52), 'aes-256-gcm', $key,
        OPENSSL_RAW_DATA, substr($b, 24, 12), substr($b, 36, 16), substr($b, 0, 8)));
```

## 2. Inneres JSON

```jsonc
{
  "format": "einsatzdoku-backup",       // Kennung, immer dieser Wert
  "version": 1,
  "created_at": "2026-07-20T18:00:00+00:00",   // Export-Zeitpunkt (UTC)
  "app": "einsatzdoku-luftrettung",
  "user": { "email": "...", "name": "..." },   // informativ

  // PatientInnendaten-Modul: Schlüssel-Hüllen (selbst Chiffretext!).
  // null, wenn das Modul nie aktiviert wurde.
  "pat_module": {
    "wrap_pw": "base64…",               // Inhaltsschlüssel, passwortverpackt
    "wrap_rc": "base64…"                // Inhaltsschlüssel, wiederherstellungsverpackt
  },

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
    "crew_fr": null, "crew_other": null,
    "aircraft": null, "base": null, "crew": null,   // Altfelder (Freitext)
    "notes": "…"
  } ],

  "missions": [ {
    "client_ref": "m-1721383200",       // eindeutige Referenz (Dubletten-Schutz)
    "day": "2026-07-19",
    "started_at": "2026-07-19 08:15:00",  // DATETIME, UTC
    "ended_at":   "2026-07-19 09:02:00",  // null = kein Abschluss
    "manual": 0, "final": 1,
    "distance_m": 38400, "ascent_m": 550,
    "mission_no": "…", "transport_dest": "…", "site_desc": "…",
    "winch": 0, "winch_cycles": null, "winch_cycles_pat": null,
    "winch_airload": 0, "bergwacht": 0, "bw_unit": null, "bw_info": null,
    "other_ema": null, "other_resources": null, "notes": null,
    "pat_blob": "base64…",              // E2E-Chiffretext (Diagnose, Alter,
                                        // Einsatzort), bleibt verschlüsselt
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
- `pat_blob` und die `wrap_*`-Werte sind Base64 von `IV(12) || Chiffretext`
  (AES-256-GCM) aus dem PatientInnendaten-Modul — sie bleiben im Backup
  verschlüsselt und sind nur mit passendem Login-Passwort bzw.
  Wiederherstellungsschlüssel lesbar.
- Zusatzfelder der Einsätze folgen `server/mission_fields.php`; künftige
  Versionen können Felder ergänzen (Import ignoriert Unbekanntes).

## 3. Import-Verhalten

- Import immer in das **eigene** Konto; bestehende Daten werden nie
  überschrieben.
- Dubletten-Erkennung: Einsätze und Ruhesegmente über `client_ref`,
  Flugtage über das Datum, Stammdaten über ihre Namen — Vorhandenes wird
  übersprungen, nur Fehlendes ergänzt.
- `pat_module` wird nur übernommen, wenn das Konto noch keine
  Schlüssel-Hüllen besitzt.
- Standard-Markierungen (★) werden nur importiert, wenn noch kein Standard
  gesetzt ist (es bleibt bei genau einem).
