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

// PatientInnendaten-Modul (Spalten existieren erst nach Migration)
$patEnabled = $row && !empty($row['pat_enabled']);
$patFields  = [];
if ($patEnabled && !empty($row['pat_fields'])) {
    $pf = json_decode((string)$row['pat_fields'], true);
    if (is_array($pf)) { $patFields = array_values(array_intersect($pf, ['ln','fn','dx','dob','age'])); }
}
$patWrapPw = ($row && isset($row['pat_wrap_pw'])) ? $row['pat_wrap_pw'] : null;
$kdfSalt   = ($row && isset($row['kdf_salt'])) ? $row['kdf_salt'] : null;
$kdfVer    = ($row && isset($row['kdf_ver'])) ? (int)$row['kdf_ver'] : 0;
require_once __DIR__ . '/ui.php';

run_cleanup_if_due();   // taegliche Wartung, huckepack auf Web-Anfragen
