<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth_guard.php';      // liefert $userId
require_once __DIR__ . '/../backup_lib.php';

/**
 * GET api/backup_data.php -> vollstaendiges Datenpaket der angemeldeten
 * NutzerIn als JSON (Format 2). Die verschluesselten Angaben stecken darin
 * unveraendert als Chiffretext (`pat_blob`) — der Browser entschluesselt sie
 * und ersetzt sie durch Klartext, bevor er die Datei mit dem Backup-Passwort
 * versiegelt. Der Server sieht dabei nie Klartext.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { json_out(['error' => 'method'], 405); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo edbak_build($userId);
