<?php
declare(strict_types=1);

$CFG = require __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    global $CFG;
    if ($pdo === null) {
        $pdo = new PDO($CFG['db']['dsn'], $CFG['db']['user'], $CFG['db']['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** UTC-DATETIME (aus DB) -> Anzeige in App-Zeitzone */
function fmt_local(?string $utc, string $format = 'H:i'): string {
    global $CFG;
    if ($utc === null || $utc === '') return '–';
    $dt = new DateTime($utc, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone($CFG['app']['timezone']));
    return $dt->format($format);
}

/** ISO-8601-UTC (Uhr) -> 'Y-m-d H:i:s' fuer DATETIME-Spalten; null bei Murks */
function iso_to_sql(?string $iso): ?string {
    if ($iso === null) return null;
    $dt = DateTime::createFromFormat(DateTime::ATOM, $iso)
       ?: DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $iso, new DateTimeZone('UTC'));
    if ($dt === false) return null;
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

const PHASE_LABELS = [
    1 => 'Frei', 2 => 'Alarmierung', 3 => 'Abflug', 4 => 'Ankunft Einsatzort',
    5 => 'Ankunft PatientIn', 6 => 'Transportbeginn', 7 => 'Landung Krankenhaus',
    8 => 'Übergabezeit', 9 => 'Endzeit des Einsatzes', 10 => 'Beendigung Einsatz',
];

const RESUS_LABELS = [
    'beginn' => 'Reanimationsbeginn', 'adrenalin' => 'Adrenalingabe',
    'rhythmuskontrolle' => 'Rhythmuskontrolle', 'defibrillation' => 'Defibrillation',
    'intubation' => 'Intubation', 'amiodaron' => 'Amiodaron',
    'sonographie' => 'Sonographie', 'rosc' => 'ROSC', 'tod' => 'Tod',
];

/**
 * Automatischer Aufraeumjob — laeuft hoechstens einmal pro Tag, huckepack auf
 * normalen Anfragen (Web-Login und Uhr-Uploads), daher kein Cronjob noetig.
 * Entsorgt: verwaiste Trackpunkte (Einsatz/Segment geloescht) und alte
 * Passwort-Reset-Tokens. Scheitert leise, falls die app_state-Tabelle noch
 * nicht existiert (Migration noch nicht gelaufen).
 */
function run_cleanup_if_due(): void {
    try {
        $pdo = db();
        $today = (new DateTime('now'))->format('Y-m-d');
        $st = $pdo->prepare('SELECT v FROM app_state WHERE k = ?');
        $st->execute(['last_cleanup']);
        if ($st->fetchColumn() === $today) { return; }

        // Marke zuerst setzen: verhindert Doppel-Laeufe paralleler Anfragen
        $pdo->prepare('INSERT INTO app_state (k, v) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE v = VALUES(v)')
            ->execute(['last_cleanup', $today]);

        $pdo->exec("DELETE tp FROM track_points tp
                    LEFT JOIN missions m ON m.id = tp.owner_id
                    WHERE tp.owner_type = 'mission' AND m.id IS NULL");
        $pdo->exec("DELETE tp FROM track_points tp
                    LEFT JOIN rest_segments r ON r.id = tp.owner_id
                    WHERE tp.owner_type = 'rest' AND r.id IS NULL");
        $pdo->exec("DELETE FROM password_resets
                    WHERE used_at IS NOT NULL
                       OR expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    } catch (Throwable $ex) {
        // bewusst still: Wartung darf nie eine Anfrage kaputt machen
    }
}
