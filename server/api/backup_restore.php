<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth_guard.php';      // liefert $userId
require_once __DIR__ . '/../backup_lib.php';

/**
 * POST api/backup_restore.php
 * Body: das im Browser entsiegelte Backup-JSON. Die verschluesselten Angaben
 * hat der Browser bereits mit dem Inhaltsschluessel DIESES Kontos neu
 * verschluesselt (`pat_blob`), deshalb laesst sich ein Backup in jedes Konto
 * einspielen. Header X-CSRF muss zum Session-Token passen.
 *
 * Antwort: { ok: true, stats: {...} }
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['error' => 'method'], 405); }
if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF'] ?? '')) {
    json_out(['error' => 'csrf'], 403);
}

$raw = file_get_contents('php://input');
if ($raw === '' || $raw === false) {
    json_out(['error' => 'leer', 'hinweis' =>
        'Es kamen keine Daten an — evtl. begrenzt der Server die Upload-Größe (post_max_size).'], 400);
}

$data = json_decode($raw, true);
if (!is_array($data) || ($data['format'] ?? '') !== 'einsatzdoku-backup') {
    json_out(['error' => 'format'], 400);
}

try {
    $stats = edbak_restore($userId, $data);
    json_out(['ok' => true, 'stats' => $stats]);
} catch (Throwable $ex) {
    json_out(['error' => 'restore', 'meldung' => $ex->getMessage()], 500);
}
