<?php
declare(strict_types=1);
if (!file_exists(__DIR__ . '/config.php')) { header('Location: install.php'); exit; }
require_once __DIR__ . '/db.php';
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start();

if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // simple Bremse: nach 5 Fehlversuchen 30 s Pause
    $fails = (int)($_SESSION['login_fails'] ?? 0);
    if ($fails >= 5 && time() - (int)($_SESSION['login_last'] ?? 0) < 30) {
        $error = 'Zu viele Versuche — bitte kurz warten.';
    } else {
        $st = db()->prepare('SELECT id, password_hash, role FROM users WHERE email = ?');
        $st->execute([trim($_POST['email'] ?? '')]);
        $u = $st->fetch();
        if ($u && $u['password_hash'] !== null && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$u['id'];
            $_SESSION['role']    = $u['role'];
            unset($_SESSION['login_fails'], $_SESSION['login_last']);
            header('Location: index.php'); exit;
        }
        $_SESSION['login_fails'] = $fails + 1;
        $_SESSION['login_last']  = time();
        $error = 'Anmeldung fehlgeschlagen. E-Mail oder Passwort prüfen.';
    }
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Anmelden — Einsatzdoku</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/favicon.png">
</head>
<body class="login-body">
<main class="login-card">
  <img src="assets/logo.png" alt="GenEM" class="login-logo"
       onerror="this.style.display='none'">
  <h1>Einsatzdoku</h1>
  <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" autocomplete="on">
    <label>E-Mail
      <input type="email" name="email" required autofocus autocomplete="username">
    </label>
    <label>Passwort
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit" class="btn-primary">Anmelden</button>
  </form>
  <p class="login-aux"><a href="reset_request.php">Passwort vergessen oder erstmalig setzen</a></p>
</main>
<footer class="sitefooter">© Gen-EM · <a href="https://github.com/gen-em/einsatzdoku-luftrettung/blob/main/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a></footer>
</body>
</html>
