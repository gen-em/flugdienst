<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/smtp.php';

$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $st = db()->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if ($u) {
        $token = bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at)
                       VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
            ->execute([(int)$u['id'], hash('sha256', $token)]);
        $link = $CFG['app']['base_url'] . '/reset_confirm.php?token=' . $token;
        smtp_send($email, 'Passwort setzen — Einsatzdoku',
            "Hallo,\n\nüber diesen Link kannst du dein Passwort setzen (gültig 1 Stunde):\n\n"
            . $link . "\n\nFalls du das nicht angefordert hast, ignoriere diese E-Mail.\n");
    }
    // Immer gleiche Antwort — verraet nicht, ob die Adresse existiert.
    $done = true;
}
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Passwort zurücksetzen — Einsatzdoku</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/favicon.png"></head>
<body class="login-body">
<main class="login-card">
  <h1>Passwort setzen</h1>
  <?php if ($done): ?>
    <p>Wenn die Adresse registriert ist, wurde eine E-Mail mit einem Link verschickt. Der Link ist eine Stunde gültig.</p>
    <p class="login-aux"><a href="login.php">Zur Anmeldung</a></p>
  <?php else: ?>
    <p>E-Mail-Adresse eingeben — du bekommst einen Link, um ein neues Passwort zu setzen.</p>
    <form method="post">
      <label>E-Mail
        <input type="email" name="email" required autofocus>
      </label>
      <button type="submit" class="btn-primary">Link anfordern</button>
    </form>
    <p class="login-aux"><a href="login.php">Zurück zur Anmeldung</a></p>
  <?php endif; ?>
</main>
</body>
</html>
