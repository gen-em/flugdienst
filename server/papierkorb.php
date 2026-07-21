<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/trash_lib.php';

/**
 * Aktionen des Papierkorbs. Wiederherstellen laeuft direkt (harmlos,
 * jederzeit umkehrbar); das endgueltige Loeschen zeigt vorher eine
 * Zwischenseite mit dem Umfang.
 */

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$day    = (string)($_POST['day'] ?? $_GET['day'] ?? '');
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) { csrf_check(); }

if ($isPost && $action === 'restore_day' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    trash_restore_day($userId, $day);
    header('Location: index.php?day=' . urlencode($day)); exit;
}
if ($isPost && $action === 'restore_mission' && $id > 0) {
    trash_restore_mission($userId, $id);
    header('Location: index.php'); exit;
}
if ($isPost && $action === 'purge_day' && ($_POST['confirm'] ?? '') === 'ja') {
    trash_purge_day($userId, $day);
    header('Location: index.php'); exit;
}
if ($isPost && $action === 'purge_mission' && ($_POST['confirm'] ?? '') === 'ja') {
    trash_purge_mission($userId, $id);
    header('Location: index.php'); exit;
}

/* ---- Zwischenseite fuer das endgueltige Loeschen ----------------------- */
$istTag = ($action === 'purge_day');
if ($istTag && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    http_response_code(400); exit('Ungültiges Datum.');
}
if (!$istTag && $action !== 'purge_mission') { header('Location: index.php'); exit; }

if ($istTag) {
    $scope = trash_scope_day($userId, $day);
    // Der Umfang zaehlt nur nicht-geloeschte Zeilen; im Papierkorb sind alle
    // markiert, deshalb hier direkt zaehlen.
    $c = db()->prepare('SELECT COUNT(*) FROM missions
                        WHERE user_id = ? AND day = ? AND deleted_at IS NOT NULL');
    $c->execute([$userId, $day]);
    $anzahl = (int)$c->fetchColumn();
} else {
    $st = db()->prepare('SELECT * FROM missions WHERE id = ? AND user_id = ? AND deleted_at IS NOT NULL');
    $st->execute([$id, $userId]);
    $m = $st->fetch();
    if (!$m) { header('Location: index.php'); exit; }
}

require __DIR__ . '/ui.php';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Endgültig löschen · Einsatzdoku</title>
  <link rel="stylesheet" href="assets/style.css">
  <link rel="icon" type="image/png" href="assets/favicon.png">
</head>
<body>
<?php ui_topbar('uebersicht'); ?>
<div class="layout">
  <?php ui_days_sidebar(null); ?>
  <main class="page">
    <h1>Endgültig löschen?</h1>
    <div class="card">
      <?php if ($istTag): ?>
        <p><strong>Flugtag <?= e(date('d.m.Y', strtotime($day))) ?></strong>
           mit <?= $anzahl ?> Einsätzen, Ruhesegmenten und allen Tracks.</p>
      <?php else: ?>
        <p><strong>Einsatz vom <?= e(fmt_local((string)$m['started_at'], 'd.m.Y')) ?>,
           <?= e(fmt_local((string)$m['started_at'])) ?> Uhr</strong></p>
      <?php endif; ?>
      <p class="alert">Dieser Schritt lässt sich <strong>nicht</strong> rückgängig machen.
         Die Daten sind danach unwiederbringlich fort — auch die verschlüsselten Angaben.</p>
      <p class="muted">Anschließend werden die betroffenen Einsätze für die Uhr gesperrt,
         damit sie nicht durch Nachlieferungen erneut angelegt werden.</p>
    </div>
    <form method="post" action="papierkorb.php" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $istTag ? 'purge_day' : 'purge_mission' ?>">
      <input type="hidden" name="day" value="<?= e($day) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="confirm" value="ja">
      <button class="btn-red">Ja, endgültig löschen</button>
      <a class="btn-link" href="index.php">Abbrechen</a>
    </form>
  </main>
</div>
<?php ui_footer(); ?>
</body>
</html>
