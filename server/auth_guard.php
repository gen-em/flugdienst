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

// Anzeigename fuer die Kopfleiste (name-Spalte existiert erst nach Migration)
$u = db()->prepare('SELECT * FROM users WHERE id = ?');
$u->execute([$userId]);
$row = $u->fetch();
$userEmail = $row ? (string)$row['email'] : '';
$userName  = ($row && isset($row['name'])) ? $row['name'] : null;
require_once __DIR__ . '/ui.php';

run_cleanup_if_due();   // taegliche Wartung, huckepack auf Web-Anfragen
