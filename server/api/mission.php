<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth_guard.php';

$id = (int)($_GET['id'] ?? 0);

$st = db()->prepare('SELECT id, day, started_at, ended_at, distance_m, ascent_m
                     FROM missions WHERE id = ? AND user_id = ?');   // Datentrennung!
$st->execute([$id, $userId]);
$m = $st->fetch();
if (!$m) json_out(['error' => 'not_found'], 404);

$pt = db()->prepare('SELECT lat, lon FROM track_points
                     WHERE owner_type = \'mission\' AND owner_id = ? ORDER BY seq');
$pt->execute([$id]);
$track = array_map(fn($p) => [(float)$p['lat'], (float)$p['lon']], $pt->fetchAll());

$ph = db()->prepare('SELECT phase, occurred_at FROM mission_phases
                     WHERE mission_id = ? ORDER BY occurred_at');
$ph->execute([$id]);
$phases = array_map(fn($p) => [
    'phase' => (int)$p['phase'],
    'label' => PHASE_LABELS[(int)$p['phase']] ?? ('Phase ' . $p['phase']),
    'time'  => fmt_local($p['occurred_at']),
], $ph->fetchAll());

$resus = null;
$rs = db()->prepare('SELECT id, started_at FROM resus_sessions WHERE mission_id = ?');
$rs->execute([$id]);
if ($sess = $rs->fetch()) {
    $ev = db()->prepare('SELECT type, occurred_at FROM resus_events
                         WHERE session_id = ? ORDER BY occurred_at');
    $ev->execute([(int)$sess['id']]);
    $events = [['label' => RESUS_LABELS['beginn'], 'time' => fmt_local($sess['started_at'])]];
    foreach ($ev->fetchAll() as $e2) {
        $events[] = ['label' => RESUS_LABELS[$e2['type']] ?? $e2['type'],
                     'time'  => fmt_local($e2['occurred_at'])];
    }
    $resus = $events;
}

json_out([
    'id' => (int)$m['id'], 'day' => $m['day'],
    'start_hhmm' => fmt_local($m['started_at']),
    'end_hhmm'   => fmt_local($m['ended_at']),
    'distance_m' => $m['distance_m'] !== null ? (int)$m['distance_m'] : null,
    'ascent_m'   => $m['ascent_m']   !== null ? (int)$m['ascent_m']   : null,
    'track' => $track, 'phases' => $phases, 'resus' => $resus,
]);
