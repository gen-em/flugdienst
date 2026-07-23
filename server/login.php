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
        // Der Browser sendet nie das Passwort, sondern das daraus
        // abgeleitete Token (siehe assets/crypto.js).
        $st = db()->prepare('SELECT id, password_hash, role FROM users WHERE email = ?');
        $st->execute([trim($_POST['email'] ?? '')]);
        $u = $st->fetch();

        $ok = false;
        if ($u && $u['password_hash'] !== null) {
            $token = (string)($_POST['token'] ?? '');
            $ok = $token !== '' && password_verify($token, $u['password_hash']);
        }
        if ($ok) {
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
<link rel="stylesheet" href="<?= asset('assets/style.css') ?>">
<link rel="icon" type="image/png" href="<?= asset('assets/images/favicon.png') ?>">
</head>
<body class="login-body">
<main class="login-card">
  <img src="<?= e(asset((string)($CFG['app']['logo_path'] ?? 'assets/images/gen-em_logo_helicopter.svg'))) ?>"
       alt="GenEM" class="login-logo" onerror="this.style.display='none'">
  <h1>Einsatzdoku</h1>
  <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" autocomplete="on" id="loginform">
    <input type="hidden" name="token" id="tok">
    <label>E-Mail
      <input type="email" name="email" required autofocus autocomplete="username">
    </label>
    <label>Passwort
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit" class="btn-primary">Anmelden</button>
  </form>
  <p class="login-aux"><a href="reset_request.php">Passwort vergessen oder erstmalig setzen</a></p>
  <p class="muted" id="loginstate" style="min-height:1.2em"></p>
</main>
<script src="<?= asset('assets/crypto.js') ?>"></script>
<script>
// Der Browser leitet aus dem Passwort zwei Schluessel ab: das Auth-Token
// (geht zum Server) und den Daten-Schluessel (bleibt hier, entsperrt das
// PatientInnendaten-Modul). Das Passwort selbst verlaesst den Browser nur
// noch ein einziges Mal bei Alt-Konten (Umstellung).
document.getElementById('loginform').addEventListener('submit', async ev => {
  const f = ev.target;
  if (f.dataset.ready === '1') return;               // zweiter Durchlauf: senden
  ev.preventDefault();
  const state = document.getElementById('loginstate');
  try {
    state.textContent = 'Schlüssel wird abgeleitet…';
    const email = f.elements['email'].value.trim().toLowerCase();
    const pw = f.elements['password'].value;
    const r = await fetch('auth_salt.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email })
    });
    const d = await r.json();
    const k = await EdCrypto.deriveKeys(pw, d.salt);
    document.getElementById('tok').value = k.authToken;
    EdCrypto.setDataKey(k.dataKeyHex);               // fuer diese Sitzung
    f.elements['password'].value = '';               // verlaesst den Browser nie
    f.dataset.ready = '1';
    state.textContent = '';
    f.submit();
  } catch (e2) {
    // Ohne Web-Krypto ist keine Anmeldung moeglich: Das Passwort duerfte den
    // Browser nicht verlassen, und ohne abgeleitetes Token gibt es keinen Weg.
    state.textContent = 'Dieser Browser unterstützt die nötige Verschlüsselung nicht.';
  }
});
</script>
<?php /* Footer im Fluss unter der Karte */ ?>
<footer class="sitefooter">© Gen-EM – OpenSource Software – <a href="https://github.com/gen-em/einsatzdoku-luftrettung/blob/main/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a></footer>
</div>
</body>
</html>
