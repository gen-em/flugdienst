<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$st = db()->prepare('SELECT id, day, device_id, client_ref FROM missions WHERE id = ? AND user_id = ?');
$st->execute([$id, $userId]);
$m = $st->fetch();
if (!$m) { http_response_code(404); exit('Einsatz nicht gefunden.'); }

$pdo = db();
$pdo->beginTransaction();
try {
    // Sperrliste: verhindert, dass die Uhr den Einsatz aus dem Puffer neu
    // anlegt (Eintraege verfallen nach 90 Tagen via Aufraeumjob).
    // Virtuelle Manuell-Eintraege ('man-…') laedt nie jemand erneut hoch.
    if ($m['device_id'] !== null && strpos((string)$m['client_ref'], 'man-') !== 0) {
        $pdo->prepare('INSERT IGNORE INTO deleted_refs (device_id, client_ref) VALUES (?,?)')
            ->execute([(int)$m['device_id'], $m['client_ref']]);
    }
    $pdo->prepare("DELETE FROM track_points WHERE owner_type = 'mission' AND owner_id = ?")
        ->execute([$id]);
    $pdo->prepare('DELETE FROM missions WHERE id = ? AND user_id = ?')
        ->execute([$id, $userId]);          // Phasen/Rea kaskadieren
    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
    http_response_code(500); exit('Löschen fehlgeschlagen.');
}

header('Location: index.php?day=' . urlencode($m['day']));
exit;
