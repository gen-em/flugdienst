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
            if ($no < 2 || $no > 10) continue;
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

        // Zusatzfelder generisch aus der zentralen Definition uebernehmen
        $fieldCols = []; $fieldVals = [];
        foreach ($FIELDS as $col => $f) {
            $v = trim((string)($_POST['f_' . $col] ?? ''));
            if (($f['type'] ?? 'text') === 'number') {
                $v = ($v === '') ? null : (string)(float)str_replace(',', '.', $v);
            } else {
                $v = mb_substr($v, 0, (int)($f['max'] ?? 190));
                if ($v === '') { $v = null; }
            }
            $fieldCols[] = $col; $fieldVals[] = $v;
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { return (string)($_POST['f_' . $col] ?? ''); }
    return $mission !== null ? (string)($mission[$col] ?? '') : '';
}
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $editing ? 'Einsatz bearbeiten' : 'Einsatz nachtragen' ?> — Einsatzdoku</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/favicon.png"></head>
<body>
<header class="topbar">
  <a class="brand" href="index.php"><img src="assets/logo-weiss.png" alt="GenEM Einsatzdoku"></a>
  <nav><a href="index.php">Übersicht</a> <a href="geraete.php">Geräte</a> <a href="logout.php">Abmelden</a></nav>
</header>
<main class="page page-narrow">
  <h1><?= $editing ? 'Einsatz bearbeiten' : 'Einsatz nachtragen' ?></h1>
  <?php if ($editing && !(int)$mission['manual']): ?>
    <p class="alert alert-info">Dieser Einsatz stammt von der Uhr. Nach dem Speichern gilt er als
       manuell bearbeitet — spätere Uhr-Uploads überschreiben ihn dann nicht mehr
       (GPS-Track wird weiterhin ergänzt).</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>

  <form method="post">
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

    <label>Betriebstag
      <input type="date" name="day" value="<?= e($day) ?>" required <?= $editing ? 'readonly' : '' ?>>
    </label>

    <h2>Phasen</h2>
    <p class="muted">In chronologischer Reihenfolge eintragen. Zeiten nach Mitternacht
       werden automatisch dem Folgetag zugerechnet.</p>
    <div id="phaserows"></div>
    <p><a href="#" id="addrow" class="add-link">+ Phase hinzufügen</a></p>

    <h2>Weitere Angaben</h2>
    <?php foreach ($FIELDS as $col => $f): ?>
      <label><?= e($f['label']) ?>
        <?php if (($f['type'] ?? 'text') === 'textarea'): ?>
          <textarea name="f_<?= e($col) ?>" rows="3" maxlength="<?= (int)($f['max'] ?? 190) ?>"
            placeholder="<?= e($f['placeholder'] ?? '') ?>"><?= e(fieldValue($col)) ?></textarea>
        <?php else: ?>
          <input type="<?= ($f['type'] ?? 'text') === 'number' ? 'number' : 'text' ?>"
            name="f_<?= e($col) ?>" value="<?= e(fieldValue($col)) ?>"
            <?= isset($f['max']) ? 'maxlength="' . (int)$f['max'] . '"' : '' ?>
            placeholder="<?= e($f['placeholder'] ?? '') ?>" step="any">
        <?php endif; ?>
      </label>
    <?php endforeach; ?>

    <button type="submit" class="btn-primary"><?= $editing ? 'Änderungen speichern' : 'Einsatz anlegen' ?></button>
    <?php if ($editing): ?>
      <p class="login-aux"><a href="einsatz.php?id=<?= $id ?>">Abbrechen</a></p>
    <?php endif; ?>
  </form>
</main>

<script>
const PHASE_LABELS = <?= json_encode(PHASE_LABELS) ?>;
const START_ROWS = <?= json_encode($prefillRows) ?>;

function addRow(no, time) {
  const div = document.createElement('div');
  div.className = 'phase-row';
  const sel = document.createElement('select');
  sel.name = 'ph_no[]';
  for (let p = 2; p <= 10; p++) {
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

START_ROWS.forEach(r => addRow(r[0], r[1] === '–' ? '' : r[1]));
document.getElementById('addrow').addEventListener('click', ev => {
  ev.preventDefault();
  const rows = document.querySelectorAll('.phase-row select');
  const last = rows.length ? parseInt(rows[rows.length - 1].value) : 1;
  addRow(Math.min(last + 1, 10), '').focus();   // direkt per Tastatur bedienbar
});
</script>
<footer class="sitefooter">© Gen-EM · <a href="https://github.com/gen-em/einsatzdoku-luftrettung/blob/main/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a></footer>
</body>
</html>
