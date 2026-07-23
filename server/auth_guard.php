<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

session_set_cookie_params([
    'httponly' => true, 'secure' => true, 'samesite' => 'Strict', 'path' => '/',
]);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId   = (int)$_SESSION['user_id'];
$userRole = (string)($_SESSION['role'] ?? 'user');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function require_admin(): void {
    global $userRole;
    if ($userRole !== 'admin') { http_response_code(403); exit('Kein Zugriff.'); }
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403); exit('Ungültiges Formular-Token.');
    }
}

// Inaktivitaets-Timeout: nach 30 Minuten ohne Anfrage neu anmelden
const SESSION_TIMEOUT_S = 1800;
if (isset($_SESSION['last_seen']) && (time() - (int)$_SESSION['last_seen']) > SESSION_TIMEOUT_S) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['last_seen'] = time();

// Anzeigename fuer die Kopfleiste (name-Spalte existiert erst nach Migration)
$u = db()->prepare('SELECT * FROM users WHERE id = ?');
$u->execute([$userId]);
$row = $u->fetch();
$userEmail = $row ? (string)$row['email'] : '';
$userName  = ($row && isset($row['name'])) ? $row['name'] : null;

// Pflicht-Verschlüsselung: aktiv, sobald der Inhaltsschluessel verpackt
// vorliegt. Ohne Huelle wird die Ersteinrichtung erzwungen (unten).
$patWrapPw = ($row && isset($row['pat_wrap_pw'])) ? $row['pat_wrap_pw'] : null;
$patReady  = $patWrapPw !== null;
$kdfSalt   = ($row && isset($row['kdf_salt'])) ? $row['kdf_salt'] : null;

// Erzwungene Ersteinrichtung: Konto ist auf Browser-Schluessel umgestellt,
// hat aber noch keinen Inhaltsschluessel -> zuerst einrichtung.php.
// Ausgenommen: die Einrichtung selbst, Abmelden, Wartung und die JSON-APIs.
if (!$patReady) {
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $isApi = strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false;
    if (!$isApi && !in_array($script, ['einrichtung.php', 'logout.php', 'update.php'], true)) {
        header('Location: einrichtung.php');
        exit;
    }
}
require_once __DIR__ . '/ui.php';

run_cleanup_if_due();   // taegliche Wartung, huckepack auf Web-Anfragen
