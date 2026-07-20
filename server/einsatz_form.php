<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
$FIELDS = require __DIR__ . '/mission_fields.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$editing = $id > 0;
$error = null;

/* ---- Helfer: lokale Uhrzeit (Berlin) -> UTC-DATETIME ---------------------- */
function local_to_utc(string $day, string $hhmm, int $addDays = 0): ?string {
    global $CFG;
    if (!preg_match('/^\d{2}:\d{2}$/', $hhmm)) return null;
    $dt = DateTime::createFromFormat('Y-m-d H:i', "$day $hhmm",
        new DateTimeZone($CFG['app']['timezone']));
    if ($dt === false) return null;
    if ($addDays > 0) { $dt->modify("+$addDays day"); }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/* ---- Bestehenden Einsatz laden (nur eigene!) ------------------------------ */
$mission = null; $phases = [];
if ($editing) {
    $st = db()->prepare('SELECT * FROM missions WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    $mission = $st->fetch();
    if (!$mission) { http_response_code(404); exit('Einsatz nicht gefunden.'); }
    $ph = db()->prepare('SELECT phase, occurred_at FROM mission_phases
                         WHERE mission_id = ? ORDER BY occurred_at');
    $ph->execute([$id]);
    $phases = $ph->fetchAll();
}
$day = $editing ? $mission['day']
     : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day'] ?? '') ? $_GET['day'] : date('Y-m-d'));

/* ---- Speichern ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $day = $_POST['day'] ?? $day;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { $error = 'Ungültiges Datum.'; }

    // Phasenzeilen einsammeln (chronologische Eingabe; Zeiten "nach Mitternacht"
    // werden automatisch dem Folgetag zugerechnet)
    $rows = [];
    if (!$error) {
        $nos = $_POST['ph_no'] ?? []; $times = $_POST['ph_time'] ?? [];
        $prev = null; $dayOffset = 0;
        foreach ((array)$nos as $i => $no) {
            $t = trim((string)($times[$i] ?? ''));
            if ($t === '') continue;
            $no = (int)$no;
            if ($no < 2 || $no > 9) continue;
            $ts = local_to_utc($day, $t, $dayOffset);
            if ($ts !== null && $prev !== null && $ts < $prev) {
                $dayOffset += 1;                       // Mitternacht ueberschritten
                $ts = local_to_utc($day, $t, $dayOffset);
            }
            if ($ts === null) { $error = 'Ungültige Uhrzeit in den Phasen.'; break; }
            $rows[] = [$no, $ts];
            $prev = $ts;
        }
        if (!$error && count($rows) === 0) { $error = 'Mindestens eine Phase mit Uhrzeit eintragen.'; }
    }

    if (!$error) {
        $startedAt = $rows[0][1];
        $endedAt   = $rows[count($rows) - 1][1];

        // Zusatzfelder generisch aus der zentralen Definition uebernehmen.
        // Checkbox-Unterfelder werden nur gespeichert, wenn der Haken gesetzt
        // ist — sonst geleert (kein Geister-Inhalt hinter "Nein").
        $fieldCols = []; $fieldVals = [];
        $readField = function (string $col, array $f, bool $parentOn = true) use (&$readField, &$fieldCols, &$fieldVals) {
            $type = $f['type'] ?? 'text';
            if ($type === 'checkbox') {
                $v = ($parentOn && isset($_POST['f_' . $col])) ? 1 : 0;
                $fieldCols[] = $col; $fieldVals[] = $v;
                foreach (($f['children'] ?? []) as $cc => $cf) {
                    $readField($cc, $cf, $v === 1);
                }
                return;
            }
            $raw = trim((string)($_POST['f_' . $col] ?? ''));
            if (!$parentOn) { $raw = ''; }
            if ($type === 'number') {
                $v = ($raw === '') ? null : (string)(float)str_replace(',', '.', $raw);
            } elseif ($type === 'select') {
                $opts = $f['options'] ?? null;   // options_src: freie Werte zulassen (Stammdaten aenderbar)
                $v = ($raw === '') ? null : mb_substr($raw, 0, (int)($f['max'] ?? 120));
                if ($opts !== null && $v !== null && !in_array($v, $opts, true)) { $v = null; }
            } else {
                $v = mb_substr($raw, 0, (int)($f['max'] ?? 190));
                if ($v === '') { $v = null; }
            }
            $fieldCols[] = $col; $fieldVals[] = $v;
        };
        foreach ($FIELDS as $col => $f) { $readField($col, $f); }


        // PatientInnendaten: der Browser liefert NUR Chiffretext (pat_blob).
        // Leerer Wert = Blob nicht anfassen (z. B. Sitzung nicht entsperrt).
        if ($patReady) {
            $pb = (string)($_POST['pat_blob'] ?? '');
            if ($pb !== '' && preg_match('/^[A-Za-z0-9+\/=]{16,8000}$/', $pb)) {
                $fieldCols[] = 'pat_blob'; $fieldVals[] = $pb;
            } elseif ($pb === '__CLEAR__') {
                $fieldCols[] = 'pat_blob'; $fieldVals[] = null;
            }
        }

        $pdo = db(); $pdo->beginTransaction();
        try {
            if ($editing) {
                $set = 'started_at = ?, ended_at = ?, manual = 1';
                foreach ($fieldCols as $c) { $set .= ", `$c` = ?"; }
                $pdo->prepare("UPDATE missions SET $set WHERE id = ? AND user_id = ?")
                    ->execute(array_merge([$startedAt, $endedAt], $fieldVals, [$id, $userId]));
            } else {
                // Virtuelles Geraet "Manuelle Einträge" (deaktiviert: kann nie hochladen)
                $devKey = 'manual-' . $userId;
                $q = $pdo->prepare('SELECT id FROM devices WHERE device_id = ?');
                $q->execute([$devKey]);
                $devId = $q->fetchColumn();
                if ($devId === false) {
                    $pdo->prepare('INSERT INTO devices (user_id, device_id, api_key_hash, label, active)
                                   VALUES (?,?,?,?,0)')
                        ->execute([$userId, $devKey,
                                   password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                                   'Manuelle Einträge']);
                    $devId = (int)$pdo->lastInsertId();
                }
                $cols = 'user_id, device_id, client_ref, day, started_at, ended_at, final, manual';
                $qms  = '?,?,?,?,?,?,1,1';
                foreach ($fieldCols as $c) { $cols .= ", `$c`"; $qms .= ',?'; }
                $pdo->prepare("INSERT INTO missions ($cols) VALUES ($qms)")
                    ->execute(array_merge(
                        [$userId, (int)$devId, 'man-' . uniqid(), $day, $startedAt, $endedAt],
                        $fieldVals));
                $id = (int)$pdo->lastInsertId();
            }

            // Phasen vollstaendig ersetzen
            $pdo->prepare('DELETE FROM mission_phases WHERE mission_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO mission_phases (mission_id, phase, occurred_at) VALUES (?,?,?)');
            foreach ($rows as $r) { $ins->execute([$id, $r[0], $r[1]]); }

            $pdo->commit();
            header('Location: einsatz.php?id=' . $id);
            exit;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $error = 'Speichern fehlgeschlagen.';
        }
    }
}

/* ---- Vorbelegung fuer die Anzeige ----------------------------------------- */
$prefillRows = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nos = (array)($_POST['ph_no'] ?? []); $times = (array)($_POST['ph_time'] ?? []);
    foreach ($nos as $i => $no) { $prefillRows[] = [(int)$no, (string)($times[$i] ?? '')]; }
} elseif ($editing) {
    foreach ($phases as $p) { $prefillRows[] = [(int)$p['phase'], fmt_local($p['occurred_at'])]; }
} else {
    $prefillRows[] = [2, ''];                          // Alarmierung als Startzeile
}
function fieldValue(string $col) {
    global $mission;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return isset($_POST['f_' . $col]) ? (string)$_POST['f_' . $col] : '';
    }
    return $mission !== null ? (string)($mission[$col] ?? '') : '';
}
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $editing ? 'Einsatz bearbeiten' : 'Einsatz nachtragen' ?> — Einsatzdoku</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/favicon.png"></head>
<body>
<?php ui_topbar('uebersicht'); ?>

<div class="layout">
  <?php ui_days_sidebar($day); ?>

<main class="page page-narrow">
  <h1><?= $editing ? 'Einsatz bearbeiten' : 'Einsatz nachtragen' ?></h1>
  <?php if ($editing && !(int)$mission['manual']): ?>
    <p class="alert alert-info">Dieser Einsatz stammt von der Uhr. Nach dem Speichern gilt er als
       manuell bearbeitet — spätere Uhr-Uploads überschreiben ihn dann nicht mehr
       (GPS-Track wird weiterhin ergänzt).</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>

  <form method="post" id="missionform">
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

    <label>Flugtag
      <input type="date" name="day" value="<?= e($day) ?>" required <?= $editing ? 'readonly' : '' ?>>
    </label>

    <h2>Phasen</h2>
    <p class="muted">In chronologischer Reihenfolge eintragen. Zeiten nach Mitternacht
       werden automatisch dem Folgetag zugerechnet.</p>
    <div id="phaserows"></div>
    <p><a href="#" id="addrow" class="add-link">+ Phase hinzufügen</a></p>

    <h2>PatientInnendaten &amp; Einsatzort
      <span class="muted" style="font-weight:400">(Ende-zu-Ende-verschlüsselt)</span></h2>
    <input type="hidden" name="pat_blob" id="pat_blob">
    <div id="patlocked" class="alert" hidden>Entschlüsselung nicht möglich —
      bitte einmal ab- und neu anmelden. Vorhandene verschlüsselte Angaben bleiben unverändert.</div>
    <div id="patfields">
      <label>Diagnose <input type="text" id="pat_dx" maxlength="190"></label>
      <label>Alter <input type="number" id="pat_age" min="0" max="120" step="1"></label>
      <div class="loc-widget">
        <label>Adresse Einsatzort
          <input type="text" id="locaddr" maxlength="255" autocomplete="off"
                 placeholder="tippen für Vorschläge, z. B. Ringstr. 18, Enger">
        </label>
        <input type="hidden" id="loclat">
        <input type="hidden" id="loclon">
        <ul id="locsuggest" class="loc-suggest" hidden></ul>
        <p class="muted" id="locstate"></p>
      </div>
    </div>

    <h2>Weitere Angaben</h2>
    <?php
      // Optionslisten aus Stammdaten aufloesen (options_src)
      $optSrc = function (array $f) use ($userId): array {
          if (($f['options_src'] ?? '') === 'bw_units') {
              $q = db()->prepare('SELECT name FROM bw_units WHERE user_id = ? ORDER BY name');
              $q->execute([$userId]);
              return $q->fetchAll(PDO::FETCH_COLUMN);
          }
          return $f['options'] ?? [];
      };
      $renderField = function (string $col, array $f, int $depth = 0) use (&$renderField, $optSrc): void {
          $type = $f['type'] ?? 'text';
          $val = fieldValue($col);
          if ($type === 'checkbox') {
              $on = ($val === '1' || $val === 1); ?>
            <div class="fld-check<?= $depth ? ' fld-sub' : '' ?>">
              <label class="checklabel">
                <input type="checkbox" name="f_<?= e($col) ?>" class="parentcheck"
                       data-target="ch_<?= e($col) ?>" <?= $on ? 'checked' : '' ?>>
                <?= e($f['label']) ?></label>
              <?php if (!empty($f['children'])): ?>
                <div class="childfields" id="ch_<?= e($col) ?>" <?= $on ? '' : 'hidden' ?>>
                  <?php foreach ($f['children'] as $cc => $cf) { $renderField($cc, $cf, $depth + 1); } ?>
                </div>
              <?php endif; ?>
            </div>
          <?php return; }
          if ($type === 'select') { $opts = $optSrc($f); ?>
            <label class="<?= $depth ? 'fld-sub' : '' ?>"><?= e($f['label']) ?>
              <select name="f_<?= e($col) ?>">
                <option value="">–</option>
                <?php foreach ($opts as $o): ?>
                  <option value="<?= e($o) ?>" <?= $val === (string)$o ? 'selected' : '' ?>><?= e($o) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php return; }
          if ($type === 'textarea') { ?>
            <label class="<?= $depth ? 'fld-sub' : '' ?>"><?= e($f['label']) ?>
              <textarea name="f_<?= e($col) ?>" rows="3" maxlength="<?= (int)($f['max'] ?? 190) ?>"
                placeholder="<?= e($f['placeholder'] ?? '') ?>"><?= e($val) ?></textarea>
            </label>
          <?php return; } ?>
            <label class="<?= $depth ? 'fld-sub' : '' ?>"><?= e($f['label']) ?>
              <input type="<?= $type === 'number' ? 'number' : 'text' ?>"
                name="f_<?= e($col) ?>" value="<?= e($val) ?>"
                <?= isset($f['max']) ? 'maxlength="' . (int)$f['max'] . '"' : '' ?>
                placeholder="<?= e($f['placeholder'] ?? '') ?>" step="any">
            </label>
      <?php };
      foreach ($FIELDS as $col => $f) { $renderField($col, $f); }
    ?>

    <button type="submit" class="btn-primary"><?= $editing ? 'Änderungen speichern' : 'Einsatz anlegen' ?></button>
    <?php if ($editing): ?>
      <p class="login-aux"><a href="einsatz.php?id=<?= $id ?>">Abbrechen</a></p>
    <?php endif; ?>
  </form>
<?php ui_footer(); ?>
</main>
</div>

<script src="assets/crypto.js"></script>
<script>
const PHASE_LABELS = <?= json_encode(PHASE_LABELS) ?>;
const START_ROWS = <?= json_encode($prefillRows) ?>;

function addRow(no, time) {
  const div = document.createElement('div');
  div.className = 'phase-row';
  const sel = document.createElement('select');
  sel.name = 'ph_no[]';
  for (let p = 2; p <= 9; p++) {
    const o = document.createElement('option');
    o.value = p; o.textContent = p + ' ' + PHASE_LABELS[p];
    if (p === no) o.selected = true;
    sel.appendChild(o);
  }
  const t = document.createElement('input');
  t.type = 'time'; t.name = 'ph_time[]'; t.value = time || '';
  const rm = document.createElement('button');
  rm.type = 'button'; rm.className = 'btn-danger'; rm.textContent = '✕';
  rm.addEventListener('click', () => div.remove());
  div.append(sel, t, rm);
  document.getElementById('phaserows').appendChild(div);
  return sel;
}

// ---- PatientInnendaten & Einsatzort: lokale Ver-/Entschluesselung ------
const PAT_WRAP = <?= json_encode($patWrapPw) ?>;
const PAT_PREV = <?= json_encode($mission['pat_blob'] ?? null) ?>;
let PAT_CK = null;

(async () => {
  PAT_CK = await EdCrypto.getContentKey(PAT_WRAP);
  if (!PAT_CK) {
    document.getElementById('patlocked').hidden = false;
    document.querySelectorAll('#patfields input').forEach(i => i.disabled = true);
    return;
  }
  if (PAT_PREV) {
    let o = {};
    try { o = JSON.parse(await EdCrypto.decrypt(PAT_CK, PAT_PREV)) || {}; } catch (e) { }
    if (o.dx != null) document.getElementById('pat_dx').value = o.dx;
    if (o.age != null) document.getElementById('pat_age').value = o.age;
    if (o.loc) {
      document.getElementById('locaddr').value = o.loc.addr || '';
      if (o.loc.lat != null) {
        document.getElementById('loclat').value = o.loc.lat;
        document.getElementById('loclon').value = o.loc.lon;
      }
    }
  }
  locSetState();
})();

document.getElementById('missionform').addEventListener('submit', async ev => {
  const f = ev.target;
  if (f.dataset.patDone === '1' || !PAT_CK) return;   // gesperrt: Blob bleibt
  ev.preventDefault();
  const o = {};
  const dx = document.getElementById('pat_dx').value.trim();
  const age = document.getElementById('pat_age').value.trim();
  if (dx !== '') o.dx = dx;
  if (age !== '') o.age = parseInt(age, 10);
  const addr = document.getElementById('locaddr').value.trim();
  if (addr !== '') {
    o.loc = { addr };
    const la = document.getElementById('loclat').value;
    const lo = document.getElementById('loclon').value;
    if (la !== '' && lo !== '') { o.loc.lat = parseFloat(la); o.loc.lon = parseFloat(lo); }
  }
  document.getElementById('pat_blob').value =
    Object.keys(o).length === 0 ? '__CLEAR__' : await EdCrypto.encrypt(PAT_CK, JSON.stringify(o));
  f.dataset.patDone = '1';
  f.submit();
});

// Unterfelder ein-/ausblenden, wenn der zugehoerige Haken wechselt
document.querySelectorAll('.parentcheck').forEach(cb => {
  cb.addEventListener('change', () => {
    const t = document.getElementById(cb.dataset.target);
    if (t) t.hidden = !cb.checked;
  });
});

// Einsatzort: Photon-Autocomplete (OSM-Daten, kostenlos, kein Schluessel)
const locIn = document.getElementById('locaddr');
const locList = document.getElementById('locsuggest');
const locState = document.getElementById('locstate');
let locTimer = null;
function locLabel(p) {
  const parts = [];
  if (p.name) parts.push(p.name);
  const street = [p.street, p.housenumber].filter(Boolean).join(' ');
  if (street && street !== p.name) parts.push(street);
  const city = [p.postcode, p.city].filter(Boolean).join(' ');
  if (city) parts.push(city);
  return parts.join(', ');
}
function locSetState() {
  locState.textContent = document.getElementById('loclat').value
    ? 'Koordinaten gespeichert — Pin erscheint auf der Karte.'
    : (locIn.value ? 'Nur Text (kein Vorschlag gewählt) — kein Karten-Pin.' : '');
}
locSetState();
locIn.addEventListener('input', () => {
  document.getElementById('loclat').value = '';
  document.getElementById('loclon').value = '';
  locSetState();
  clearTimeout(locTimer);
  const q = locIn.value.trim();
  if (q.length < 3) { locList.hidden = true; return; }
  locTimer = setTimeout(async () => {
    try {
      const r = await fetch('https://photon.komoot.io/api/?lang=de&limit=6&q=' + encodeURIComponent(q));
      const d = await r.json();
      locList.innerHTML = '';
      (d.features || []).forEach(ft => {
        const li = document.createElement('li');
        li.textContent = locLabel(ft.properties);
        li.addEventListener('mousedown', ev => {           // mousedown: vor blur
          ev.preventDefault();
          locIn.value = li.textContent;
          document.getElementById('loclat').value = ft.geometry.coordinates[1];
          document.getElementById('loclon').value = ft.geometry.coordinates[0];
          locList.hidden = true;
          locSetState();
        });
        locList.appendChild(li);
      });
      locList.hidden = locList.children.length === 0;
    } catch (e) { locList.hidden = true; }
  }, 300);
});
locIn.addEventListener('blur', () => setTimeout(() => { locList.hidden = true; }, 150));

START_ROWS.forEach(r => addRow(r[0], r[1] === '–' ? '' : r[1]));
document.getElementById('addrow').addEventListener('click', ev => {
  ev.preventDefault();
  const rows = document.querySelectorAll('.phase-row select');
  const last = rows.length ? parseInt(rows[rows.length - 1].value) : 1;
  addRow(Math.min(last + 1, 10), '').focus();   // direkt per Tastatur bedienbar
});
</script>
</body>
</html>
