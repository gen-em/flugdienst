<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/backup_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: einstellungen.php?t=backup'); exit; }
csrf_check();

$pw = (string)($_POST['bpw1'] ?? '');
if (strlen($pw) < 8 || $pw !== (string)($_POST['bpw2'] ?? '')) {
    header('Location: einstellungen.php?t=backup&err=pw'); exit;
}

$blob = edbak_seal(edbak_build($userId), $pw);
$name = 'einsatzdoku-backup-' . date('Y-m-d') . '.edbak';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($blob));
echo $blob;
exit;
