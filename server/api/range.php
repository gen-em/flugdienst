<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth_guard.php';

/**
 * Einsaetze eines Jahres oder Monats — Grundlage der Zeitraum-Uebersicht.
 * Bewusst OHNE Trackpunkte: Die Ansicht zeigt keine Karte, und bei einem
 * ganzen Jahr waeren das schnell hunderttausende Koordinaten.
 * Verschluesselte Angaben gehen wie ueberall als `pat_blob` an den Browser,
 * der sie selbst entschluesselt.
 */

header('Content-Type: application/json; charset=utf-8');

$jahr  = (string)($_GET['y'] ?? '');
$monat = (string)($_GET['m'] ?? '');
if (!preg_match('/^\d{4}$/', $jahr)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiges Jahr']);
    exit;
}
if ($monat !== '' && !preg_match('/^\d{2}$/', $monat)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiger Monat']);
    exit;
}

if ($monat !== '') {
    $von  = sprintf('%s-%s-01', $jahr, $monat);
    $bis  = date('Y-m-t', strtotime($von));
} else {
    $von = $jahr . '-01-01';
    $bis = $jahr . '-12-31';
}

$st = db()->prepare('SELECT id, day, started_at, distance_m,
                       site_desc, winch, bergwacht, secondary, pat_blob,
                       (SELECT MAX(occurred_at) FROM mission_phases p
                        WHERE p.mission_id = missions.id AND p.phase = 9) AS p9_at
                     FROM missions
                     WHERE user_id = ? AND day BETWEEN ? AND ? AND deleted_at IS NULL
                     ORDER BY started_at');
$st->execute([$userId, $von, $bis]);

$missions = [];
foreach ($st->fetchAll() as $m) {
    $dur = null;
    if ($m['p9_at'] !== null) {
        $dur = (new DateTime($m['p9_at']))->getTimestamp()
             - (new DateTime($m['started_at']))->getTimestamp();
    }
    $missions[] = [
        'id'         => (int)$m['id'],
        'day'        => (string)$m['day'],
        'start_hhmm' => fmt_local($m['started_at']),
        'duration_s' => $dur,
        'distance_m' => $m['distance_m'] !== null ? (int)$m['distance_m'] : null,
        'site_desc'  => $m['site_desc'] !== null ? (string)$m['site_desc'] : null,
        'winch'      => (int)$m['winch'] === 1,
        'bergwacht'  => (int)$m['bergwacht'] === 1,
        'secondary'  => (int)$m['secondary'] === 1,
        'pat_blob'   => !empty($m['pat_blob']) ? (string)$m['pat_blob'] : null,
    ];
}

// Kennzahlen fuer die Kopfzeile
$tage = db()->prepare('SELECT COUNT(DISTINCT day) FROM missions
                       WHERE user_id = ? AND day BETWEEN ? AND ? AND deleted_at IS NULL');
$tage->execute([$userId, $von, $bis]);

echo json_encode([
    'jahr'     => $jahr,
    'monat'    => $monat !== '' ? $monat : null,
    'von'      => $von,
    'bis'      => $bis,
    'tage'     => (int)$tage->fetchColumn(),
    'missions' => $missions,
], JSON_UNESCAPED_UNICODE);
