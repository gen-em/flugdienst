<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$row = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $st = db()->prepare('SELECT id, user_id FROM password_resets
                         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch();
}

$error = null; $done = false;
if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = $_POST['password'] ?? ''; $p2 = $_POST['password2'] ?? '';
    if (strlen($p1) < 10)      $error = 'Mindestens 10 Zeichen.';
    elseif ($p1 !== $p2)       $error = 'Die Passwörter stimmen nicht überein.';
    else {
        $pdo = db(); $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($p1, PASSWORD_DEFAULT), (int)$row['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
            ->execute([(int)$row['id']]);
        $pdo->commit();
        $done = true;
    }
}
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Neues Passwort — Einsatzdoku</title>
<link rel="stylesheet" href="<?= asset('assets/style.css') ?>">
<link rel="icon" type="image/png" href="assets/favicon.png"></head>
<body class="login-body">
<main class="login-card">
  <h1>Neues Passwort</h1>
  <?php if ($done): ?>
    <p>Passwort gespeichert. Du kannst dich jetzt anmelden.</p>
    <p class="login-aux"><a href="login.php">Zur Anmeldung</a></p>
  <?php elseif (!$row): ?>
    <p class="alert">Dieser Link ist ungültig oder abgelaufen.</p>
    <p class="login-aux"><a href="reset_request.php">Neuen Link anfordern</a></p>
  <?php else: ?>
    <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label>Neues Passwort (min. 10 Zeichen)
        <input type="password" name="password" required minlength="10" autofocus autocomplete="new-password">
      </label>
      <label>Wiederholen
        <input type="password" name="password2" required minlength="10" autocomplete="new-password">
      </label>
      <button type="submit" class="btn-primary">Passwort speichern</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
