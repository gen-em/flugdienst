<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth_guard.php';   // liefert $userId

/**
 * GET api/day.php            -> { days: ["2026-07-16", ...], latest: "..." }
 * GET api/day.php?day=Y-m-d  -> Tagesdaten: Einsaetze (inkl. Track, Phasenzeiten),
 *                               Ruhe-Segmente (Track), fuer Karte + Tabelle
 */

$day = (string)($_GET['day'] ?? '');

if ($day === '') {
    $st = db()->prepare('SELECT DISTINCT day FROM (
                           SELECT day FROM missions      WHERE user_id = ?
                           UNION SELECT day FROM rest_segments WHERE user_id = ?
                         ) t ORDER BY day DESC LIMIT 120');
    $st->execute([$userId, $userId]);
    $days = array_column($st->fetchAll(), 'day');
    json_out(['days' => $days, 'latest' => $days[0] ?? null]);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) json_out(['error' => 'payload'], 400);

$pt = db()->prepare('SELECT lat, lon, ts FROM track_points
                     WHERE owner_type = ? AND owner_id = ? ORDER BY seq');

$st = db()->prepare('SELECT id, started_at, ended_at, distance_m, final
                     FROM missions WHERE user_id = ? AND day = ? ORDER BY started_at');
$st->execute([$userId, $day]);
$missions = [];
foreach ($st->fetchAll() as $m) {
    $pt->execute(['mission', (int)$m['id']]);
    $dur = null;
    if ($m['ended_at'] !== null) {
        $dur = (new DateTime($m['ended_at']))->getTimestamp() - (new DateTime($m['started_at']))->getTimestamp();
    }
    $missions[] = [
        'id'         => (int)$m['id'],
        'start_hhmm' => fmt_local($m['started_at']),
        'duration_s' => $dur,
        'distance_m' => $m['distance_m'] !== null ? (int)$m['distance_m'] : null,
        'final'      => (bool)$m['final'],
        'track'      => array_map(fn($p) => [(float)$p['lat'], (float)$p['lon']], $pt->fetchAll()),
    ];
}

$st = db()->prepare('SELECT id FROM rest_segments WHERE user_id = ? AND day = ? ORDER BY started_at');
$st->execute([$userId, $day]);
$rest = [];
foreach ($st->fetchAll() as $r) {
    $pt->execute(['rest', (int)$r['id']]);
    $track = array_map(fn($p) => [(float)$p['lat'], (float)$p['lon']], $pt->fetchAll());
    if ($track) $rest[] = $track;
}

json_out(['day' => $day, 'missions' => $missions, 'rest_segments' => $rest]);
