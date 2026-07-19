<?php
declare(strict_types=1);
/**
 * Geraete-Kopplung: Die Uhr tauscht einen kurzlebigen Einmal-Code gegen
 * frische Zugangsdaten. POST JSON {"code": "..."} — keine Auth-Header.
 * Antwort: {"device_id": "...", "api_key": "..."}
 */
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
usleep(300000);   // kleine Bremse gegen Durchprobieren

$b = json_decode(file_get_contents('php://input'), true);
$code = strtoupper(trim((string)($b['code'] ?? '')));
if (!preg_match('/^[A-Z0-9]{4,8}$/', $code)) {
    http_response_code(400); echo json_encode(['error' => 'code']); exit;
}

$pdo = db();
$st = $pdo->prepare('SELECT id, user_id FROM pair_codes
                     WHERE code = ? AND used_at IS NULL
                       AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
$st->execute([$code]);
$pc = $st->fetch();
if (!$pc) {
    http_response_code(404); echo json_encode(['error' => 'invalid']); exit;
}

$devId = 'dev-' . bin2hex(random_bytes(4));
$key   = bin2hex(random_bytes(24));
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE pair_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL')
        ->execute([(int)$pc['id']]);
    $pdo->prepare('INSERT INTO devices (user_id, device_id, api_key_hash, label)
                   VALUES (?,?,?,?)')
        ->execute([(int)$pc['user_id'], $devId,
                   password_hash($key, PASSWORD_DEFAULT),
                   'Uhr (gekoppelt ' . date('d.m.Y') . ')']);
    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
    http_response_code(500); echo json_encode(['error' => 'server']); exit;
}

echo json_encode(['device_id' => $devId, 'api_key' => $key]);
