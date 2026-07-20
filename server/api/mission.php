<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth_guard.php';

$id = (int)($_GET['id'] ?? 0);

$st = db()->prepare('SELECT * FROM missions WHERE id = ? AND user_id = ?');   // Datentrennung!
$st->execute([$id, $userId]);
$m = $st->fetch();
if (!$m) json_out(['error' => 'not_found'], 404);

// Zusatzfelder generisch aus der zentralen Definition (mission_fields.php)
$FIELDS = require __DIR__ . '/../mission_fields.php';
$fields = [];
$collect = function (string $col, array $f) use (&$collect, &$fields, $m) {
    $type = $f['type'] ?? 'text';
    $v = $m[$col] ?? null;
    if ($type === 'checkbox') {
        if ((int)$v === 1) {
            $fields[] = ['label' => $f['label'], 'value' => 'Ja'];
            foreach (($f['children'] ?? []) as $cc => $cf) { $collect($cc, $cf); }
        }
        return;
    }
    if ($v !== null && $v !== '') {
        $fields[] = ['label' => $f['label'], 'value' => (string)$v];
    }
};
foreach ($FIELDS as $col => $f) { $collect($col, $f); }

// Tagesnummer nach Alarmierungszeit (frueheste = 1)
$no = db()->prepare('SELECT COUNT(*) + 1 FROM missions
                     WHERE user_id = ? AND day = ? AND started_at < ?');
$no->execute([$userId, $m['day'], $m['started_at']]);
$dayNo = (int)$no->fetchColumn();

// Phase 9 vorhanden? (Basis fuer Ende/Dauer)
$p9 = db()->prepare('SELECT MAX(occurred_at) FROM mission_phases
                     WHERE mission_id = ? AND phase = 9');
$p9->execute([$id]);
$p9at = $p9->fetchColumn() ?: null;

$pt = db()->prepare('SELECT lat, lon FROM track_points
                     WHERE owner_type = \'mission\' AND owner_id = ? ORDER BY seq');
$pt->execute([$id]);
$track = array_map(fn($p) => [(float)$p['lat'], (float)$p['lon']], $pt->fetchAll());

$ph = db()->prepare('SELECT phase, occurred_at, lat, lon FROM mission_phases
                     WHERE mission_id = ? ORDER BY occurred_at');
$ph->execute([$id]);
$phases = array_map(fn($p) => [
    'phase' => (int)$p['phase'],
    'label' => PHASE_LABELS[(int)$p['phase']] ?? ('Phase ' . $p['phase']),
    'time'  => fmt_local($p['occurred_at']),
    'lat'   => $p['lat'] !== null ? (float)$p['lat'] : null,
    'lon'   => $p['lon'] !== null ? (float)$p['lon'] : null,
], $ph->fetchAll());

$resus = null;
$rs = db()->prepare('SELECT id, started_at FROM resus_sessions
                     WHERE mission_id = ? ORDER BY started_at');
$rs->execute([$id]);
$sessions = $rs->fetchAll();
if ($sessions) {
    $ev = db()->prepare('SELECT type, occurred_at FROM resus_events
                         WHERE session_id = ? ORDER BY occurred_at');
    $resus = [];
    foreach ($sessions as $sess) {
        $ev->execute([(int)$sess['id']]);
        $events = [['label' => RESUS_LABELS['beginn'], 'time' => fmt_local($sess['started_at'])]];
        foreach ($ev->fetchAll() as $e2) {
            $events[] = ['label' => RESUS_LABELS[$e2['type']] ?? $e2['type'],
                         'time'  => fmt_local($e2['occurred_at'])];
        }
        $resus[] = $events;   // eine Tabelle je Reanimation
    }
}

json_out([
    'id' => (int)$m['id'], 'day' => $m['day'],
    'start_hhmm' => fmt_local($m['started_at']),
    'end_hhmm'   => fmt_local($m['ended_at']),
    'distance_m' => $m['distance_m'] !== null ? (int)$m['distance_m'] : null,
    'ascent_m'   => $m['ascent_m']   !== null ? (int)$m['ascent_m']   : null,
    'manual'     => (int)($m['manual'] ?? 0) === 1,
    'day_no'     => $dayNo,
    'has_p9'     => $p9at !== null,
    'fields'     => $fields,
    'pat_blob'   => !empty($m['pat_blob']) ? (string)$m['pat_blob'] : null,
    'pat_wrap'   => $patWrapPw,
    'track' => $track, 'phases' => $phases, 'resus' => $resus,
]);
