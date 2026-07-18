<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth_guard.php';   // liefert $userId

/**
 * GET  api/day.php            -> { days: ["2026-07-16", ...], latest: "..." }
 * GET  api/day.php?day=Y-m-d  -> Tagesdaten: Flugtag-Meta, Einsaetze (inkl.
 *                                Track, Phasenzeiten), Ruhe-Segmente
 * POST api/day.php            -> Flugtag-Felder speichern (Upsert)
 *                                JSON-Body {day, aircraft, base, crew, notes},
 *                                Header X-CSRF muss zum Session-Token passen
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF'] ?? '')) {
        json_out(['error' => 'csrf'], 403);
    }
    $b = json_decode(file_get_contents('php://input'), true);
    $day = (string)($b['day'] ?? '');
    if (!is_array($b) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        json_out(['error' => 'payload'], 400);
    }
    $trim = fn($k, $max) => mb_substr(trim((string)($b[$k] ?? '')), 0, $max) ?: null;
    db()->prepare('INSERT INTO days (user_id, day, aircraft, base, crew, notes)
                   VALUES (?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE aircraft = VALUES(aircraft),
                     base = VALUES(base), crew = VALUES(crew), notes = VALUES(notes)')
        ->execute([$userId, $day, $trim('aircraft', 64), $trim('base', 64),
                   $trim('crew', 190), $trim('notes', 2000)]);
    json_out(['ok' => true]);
}

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

// Flugtag-Metadaten (null, wenn noch keine gespeichert)
$mt = db()->prepare('SELECT aircraft, base, crew, notes FROM days
                     WHERE user_id = ? AND day = ?');
$mt->execute([$userId, $day]);
$meta = $mt->fetch() ?: null;

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

json_out(['day' => $day, 'meta' => $meta, 'missions' => $missions, 'rest_segments' => $rest]);
