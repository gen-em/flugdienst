# JSON-Vertrag Uhr → Server

**Version:** 1.1 — mehrere Reanimationen pro Einsatz (resus_sessions)
**Endpunkt:** `POST https://<host>/ingest.php`
**Content-Type:** `application/json`

## 1. Authentifizierung (jede Anfrage)

| Header | Inhalt |
|---|---|
| `X-Device-Id` | Öffentliche Geräte-ID (vom Admin beim Anlegen des Geräts vergeben) |
| `X-Api-Key` | Geheimer Geräteschlüssel (Klartext nur auf der Uhr; Server speichert Hash) |

Antwort bei ungültigem Schlüssel: `401 {"error":"auth"}`.

## 2. Grundprinzipien

- **Zeitstempel:** ISO 8601 in UTC mit `Z`-Suffix, Sekundenauflösung (`2026-07-16T08:31:05Z`). Track-Punkte nutzen kompakte Unix-Epochen (Sekunden, UTC).
- **Idempotenz:** Jeder Einsatz und jedes Ruhe-Segment trägt eine von der Uhr erzeugte `client_ref` (eindeutig pro Gerät). Wiederholtes Senden derselben Daten ist unschädlich.
- **Inkrementeller Track:** Track-Punkte werden mit fortlaufender Sequenznummer gesendet. Die Uhr sendet ab `seq_from`; der Server ignoriert bereits bekannte Sequenzen und antwortet mit `next_seq`, ab dem die Uhr weitersenden soll. Nach bestätigtem Empfang darf die Uhr ihren lokalen Puffer bis `next_seq` leeren.
- **Betriebstag:** Feld `day` = Datum des Dienstbeginns (Format `YYYY-MM-DD`); die Uhr bestimmt es einmal bei „Dienst beginnen" und verwendet es für alle Uploads des Tages.
- **Nachzügler:** Bei fehlender Verbindung puffert die Uhr und sendet später identisch nach — keine Sonderfelder nötig.

## 3. Nachricht `mission` (Einsatz)

Gesendet bei Phase 10 (`final: true`) sowie optional zwischendurch als Teil-Upload (`final: false`).

```json
{
  "kind": "mission",
  "client_ref": "m-20260716-0831-a3",
  "day": "2026-07-16",
  "started_at": "2026-07-16T08:31:05Z",
  "ended_at": "2026-07-16T09:12:40Z",
  "distance_m": 148230,
  "ascent_m": 410,
  "final": true,
  "phases": [
    { "phase": 2, "at": "2026-07-16T08:31:05Z", "lat": 47.7261, "lon": 10.3186 },
    { "phase": 3, "at": "2026-07-16T08:36:22Z", "lat": 47.7259, "lon": 10.3190 },
    { "phase": 4, "at": "2026-07-16T08:51:02Z", "lat": 47.5601, "lon": 10.7002 }
  ],
  "resus_sessions": [
    {
      "started_at": "2026-07-16T08:55:10Z",
      "events": [
        { "type": "rhythmuskontrolle", "at": "2026-07-16T08:57:10Z" },
        { "type": "adrenalin",         "at": "2026-07-16T08:58:02Z" },
        { "type": "defibrillation",    "at": "2026-07-16T08:59:15Z" },
        { "type": "rosc",              "at": "2026-07-16T09:06:40Z" }
      ]
    }
  ],
  "track": {
    "seq_from": 0,
    "points": [
      [47.72611, 10.31862, 712.0, 1784279465],
      [47.72640, 10.31901, 713.5, 1784279475]
    ]
  }
}
```

Regeln:

- `ended_at` ist `null`, solange `final: false`.
- `phases[]` enthält **alle bisher gesetzten** Phasen-Zeitstempel (vollständige Liste, kein Delta) — der Server ersetzt die Phasenliste des Einsatzes bei jedem Upload. Mehrfache Einträge derselben Phasennummer sind erlaubt (erneutes Setzen einer früheren Phase, siehe Anforderungen 1.2).
- `resus_sessions` ist eine **Liste** — jede Reanimation des Einsatzes ist ein Eintrag (mehrere pro Einsatz möglich; „Aufzeichnung beenden" auf der Uhr schließt eine Sitzung, ein erneuter Start eröffnet die nächste). Fehlt oder leer = keine Reanimation. Vollständige Liste, Server ersetzt. Das ältere Einzelobjekt `resus` wird aus Kompatibilität weiterhin akzeptiert.
- `events[].type` ∈ `adrenalin`, `rhythmuskontrolle`, `defibrillation`, `intubation`, `amiodaron`, `sonographie`, `rosc`, `tod`.
- `track.points`: Array aus `[lat, lon, ele_m, epoch_s]`. `ele_m` darf `null` sein. Die Sequenznummer des i-ten Punkts ist `seq_from + i`.
- `distance_m` / `ascent_m` werden von der Uhr fortlaufend berechnet und beim `final`-Upload als verbindlich übernommen.

## 4. Nachricht `rest_segment` (Ruhe-Track-Segment)

Periodisch (z. B. stündlich bzw. bei Verbindung) und beim Beenden des Segments (Einsatzbeginn oder „Einsatztag beenden") mit `final: true`.

```json
{
  "kind": "rest_segment",
  "client_ref": "r-20260716-0700-01",
  "day": "2026-07-16",
  "started_at": "2026-07-16T05:02:11Z",
  "ended_at": null,
  "final": false,
  "track": {
    "seq_from": 240,
    "points": [
      [47.72611, 10.31862, 712.0, 1784275331]
    ]
  }
}
```

## 5. Antworten des Servers

Erfolg (`200`):

```json
{ "ok": true, "id": 17, "stored_points": 212, "next_seq": 452 }
```

- `id`: Server-ID des Einsatzes/Segments.
- `next_seq`: erste noch nicht gespeicherte Sequenznummer → Uhr sendet beim nächsten Mal `seq_from = next_seq` und darf lokal alles davor verwerfen.

Fehler:

| Code | Body | Bedeutung / Verhalten der Uhr |
|---|---|---|
| 400 | `{"error":"payload"}` | Nachricht fehlerhaft — nicht wiederholen, lokal als fehlerhaft markieren |
| 401 | `{"error":"auth"}` | Schlüssel ungültig — Upload pausieren, Hinweis anzeigen |
| 405 | `{"error":"method"}` | Falsche HTTP-Methode |
| 413 | `{"error":"too_large"}` | Chunk zu groß — Uhr halbiert die Chunk-Größe und wiederholt |
| 5xx | — | Später unverändert erneut versuchen (Backoff) |

## 6. Chunk-Größen

- Richtwert: **max. 500 Track-Punkte pro Anfrage** (Connect-IQ-Payload-Limit und Mobilfunk-Robustheit). Größere Bestände werden in mehreren aufeinanderfolgenden Anfragen gesendet (`seq_from` fortlaufend).
- Serverseitige Obergrenze: 512 KB Body (`413` bei Überschreitung).

## 7. Phasen-Nummern (Referenz)

`1` Frei · `2` Alarmierung · `3` Abflug · `4` Ankunft Einsatzort · `5` Ankunft PatientIn · `6` Transportbeginn · `7` Landung Krankenhaus · `8` Übergabezeit · `9` Endzeit des Einsatzes · `10` Beendigung Einsatz.

Phase 1 und 10 erzeugen keine eigenen Einträge in `phases[]` außer: Phase 10 wird als letzter Eintrag mitgesendet (sie liefert `ended_at`).
