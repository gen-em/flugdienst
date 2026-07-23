<?php
declare(strict_types=1);

require_once __DIR__ . '/version.php';

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

/**
 * Adresse einer statischen Datei mit angehaengter Version.
 * Nach einem Update aendert sich dadurch die Adresse, und der Browser laedt
 * Stylesheet bzw. Skript neu — ohne dass jemand den Zwischenspeicher leeren muss.
 */
function asset(string $pfad): string {
    return $pfad . '?v=' . WEB_VERSION;
}

/**
 * Verweise auf das Browser-Symbol (Favicon), zentral an einer Stelle.
 *
 * Zwei Angebote, weil Browser sich unterschiedlich verhalten: das PNG mit
 * Versionsnummer (laedt nach einem Wechsel automatisch neu) und die .ico im
 * Wurzelverzeichnis. Letztere fragen Browser zusaetzlich von sich aus unter
 * /favicon.ico ab — sie greift also selbst dann, wenn der Verweis im
 * Seitenkopf einmal ins Leere laufen sollte.
 */
function favicon_tags(): string {
    // Wurzelbezogener Pfad statt eines relativen: So spielt es keine Rolle,
    // unter welcher Adresse die Seite gerade aufgerufen wird.
    $basis = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

    // PNG zuerst: Es ist die Fassung, die wir sicher ausliefern. Die .ico ohne
    // sizes-Angabe hinterher — mit sizes="any" wuerden manche Browser sie
    // bevorzugen und bei ihrem Fehlen gar kein Symbol zeigen.
    return '<link rel="icon" type="image/png" href="' . e($basis . '/' . asset('assets/images/favicon.png')) . '">' . "\n"
         . '<link rel="icon" href="' . e($basis . '/favicon.ico') . '">'
         . '<link rel="apple-touch-icon" href="' . e($basis . '/' . asset('assets/images/favicon.png')) . '">';
}

/**
 * Pfad zum Logo fuer Anmelde- und Einrichtungsseite.
 * Die Einstellung 'logo_path' darf auf eine eigene Datei zeigen; existiert
 * sie nicht, wird die mitgelieferte Bildmarke genommen. Ohne diese Pruefung
 * bliebe die Seite bei einem veralteten Eintrag in der config.php ohne Logo.
 */
function logo_src(): string {
    global $CFG;
    $standard = 'assets/images/gen-em_logo_helicopter.svg';
    $pfad = (string)($CFG['app']['logo_path'] ?? '');
    if ($pfad !== '' && is_file(__DIR__ . '/' . ltrim($pfad, '/'))) {
        return asset($pfad);
    }
    return asset($standard);
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
    'zugang' => 'Zugang',
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
        $pdo->exec("DELETE FROM pair_codes
                    WHERE used_at IS NOT NULL
                       OR created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $pdo->exec("DELETE FROM deleted_refs
                    WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        // Papierkorb: abgelaufene Eintraege endgueltig entfernen
        require_once __DIR__ . '/trash_lib.php';
        trash_purge_expired($pdo);

        $pdo->exec("DELETE FROM password_resets
                    WHERE used_at IS NOT NULL
                       OR expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    } catch (Throwable $ex) {
        // bewusst still: Wartung darf nie eine Anfrage kaputt machen
    }
}
