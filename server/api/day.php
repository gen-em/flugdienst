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

    // Dropdown-IDs nur uebernehmen, wenn sie der NutzerIn gehoeren
    $checkId = function (?int $id, string $table) use ($userId): ?int {
        if ($id === null || $id <= 0) { return null; }
        $q = db()->prepare("SELECT id FROM `$table` WHERE id = ? AND user_id = ?");
        $q->execute([$id, $userId]);
        return $q->fetchColumn() !== false ? $id : null;
    };
    $acId   = $checkId(isset($b['aircraft_id']) ? (int)$b['aircraft_id'] : null, 'aircraft');
    $baseId = $checkId(isset($b['base_id']) ? (int)$b['base_id'] : null, 'bases');

    db()->prepare('INSERT INTO days (user_id, day, aircraft_id, base_id,
                     crew_p1, crew_p2, crew_hems, crew_fr, crew_other, notes)
                   VALUES (?,?,?,?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE aircraft_id = VALUES(aircraft_id),
                     base_id = VALUES(base_id), crew_p1 = VALUES(crew_p1),
                     crew_p2 = VALUES(crew_p2), crew_hems = VALUES(crew_hems),
                     crew_fr = VALUES(crew_fr), crew_other = VALUES(crew_other),
                     notes = VALUES(notes)')
        ->execute([$userId, $day, $acId, $baseId,
                   $trim('crew_p1', 120), $trim('crew_p2', 120), $trim('crew_hems', 120),
                   $trim('crew_fr', 120), $trim('crew_other', 120), $trim('notes', 2000)]);
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
$mt = db()->prepare('SELECT d.aircraft_id, d.base_id, d.crew_p1, d.crew_p2, d.crew_hems,
                            d.crew_fr, d.crew_other, d.notes,
                            d.aircraft, d.base, d.crew,
                            a.registration AS aircraft_name, b.name AS base_name
                     FROM days d
                     LEFT JOIN aircraft a ON a.id = d.aircraft_id
                     LEFT JOIN bases b ON b.id = d.base_id
                     WHERE d.user_id = ? AND d.day = ?');
$mt->execute([$userId, $day]);
$meta = $mt->fetch() ?: null;

$pt = db()->prepare('SELECT lat, lon, ts FROM track_points
                     WHERE owner_type = ? AND owner_id = ? ORDER BY seq');

$st = db()->prepare('SELECT id, started_at, ended_at, distance_m, final,
                       site_desc, winch, bergwacht, pat_blob,
                       (SELECT MAX(occurred_at) FROM mission_phases p
                        WHERE p.mission_id = missions.id AND p.phase = 9) AS p9_at
                     FROM missions WHERE user_id = ? AND day = ? ORDER BY started_at');
$st->execute([$userId, $day]);
$missions = [];
foreach ($st->fetchAll() as $m) {
    $pt->execute(['mission', (int)$m['id']]);
    // Dauer = Alarmierung bis Phase 9; ohne Phase 9 bewusst null
    // (Anzeige "kein Ende" — auch bei abgeschlossenen Einsaetzen ohne 9er)
    $dur = null;
    if ($m['p9_at'] !== null) {
        $dur = (new DateTime($m['p9_at']))->getTimestamp() - (new DateTime($m['started_at']))->getTimestamp();
    }
    $missions[] = [
        'id'         => (int)$m['id'],
        'start_hhmm' => fmt_local($m['started_at']),
        'duration_s' => $dur,
        'distance_m' => $m['distance_m'] !== null ? (int)$m['distance_m'] : null,
        'final'      => (bool)$m['final'],
        'has_p9'     => $m['p9_at'] !== null,
        'site_desc'  => $m['site_desc'] !== null ? (string)$m['site_desc'] : null,
        'winch'      => (int)$m['winch'] === 1,
        'bergwacht'  => (int)$m['bergwacht'] === 1,
        'pat_blob'   => !empty($m['pat_blob']) ? (string)$m['pat_blob'] : null,
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
