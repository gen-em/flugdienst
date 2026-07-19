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
        // Migrationstolerant: Vor dem Lauf von update.php fehlen die
        // kdf-Spalten noch — dann klassisch per Passwort anmelden.
        try {
            $st = db()->prepare('SELECT id, password_hash, role, kdf_ver FROM users WHERE email = ?');
            $st->execute([trim($_POST['email'] ?? '')]);
            $u = $st->fetch();
        } catch (PDOException $ex) {
            $st = db()->prepare('SELECT id, password_hash, role FROM users WHERE email = ?');
            $st->execute([trim($_POST['email'] ?? '')]);
            $u = $st->fetch();
            if ($u) { $u['kdf_ver'] = 0; }
        }
        $ok = false;
        if ($u && $u['password_hash'] !== null) {
            $token = (string)($_POST['token'] ?? '');
            if ((int)$u['kdf_ver'] === 1) {
                // Neuer Weg: Browser sendet abgeleitetes Token statt Passwort
                $ok = $token !== '' && password_verify($token, $u['password_hash']);
            } else {
                // Alt-Konto: einmal Passwort pruefen, dabei transparent auf
                // die Browser-Schluesselableitung umstellen
                $ok = password_verify($_POST['password'] ?? '', $u['password_hash']);
                if ($ok && $token !== ''
                    && preg_match('/^[0-9a-f]{64}$/', $token)
                    && preg_match('/^[0-9a-f]{32}$/', (string)($_POST['new_salt'] ?? ''))) {
                    try {
                        db()->prepare('UPDATE users SET password_hash = ?, kdf_salt = ?, kdf_ver = 1
                                       WHERE id = ?')
                            ->execute([password_hash($token, PASSWORD_DEFAULT),
                                       $_POST['new_salt'], (int)$u['id']]);
                    } catch (PDOException $ex) { /* Migration fehlt noch — Alt-Login bleibt */ }
                }
            }
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
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/favicon.png">
</head>
<body class="login-body">
<main class="login-card">
  <img src="assets/logo.png" alt="GenEM" class="login-logo"
       onerror="this.style.display='none'">
  <h1>Einsatzdoku</h1>
  <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" autocomplete="on" id="loginform">
    <input type="hidden" name="token" id="tok">
    <input type="hidden" name="new_salt" id="nsalt">
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
<script src="assets/crypto.js"></script>
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
    let salt = d.salt, v = d.v;
    if (v === 0) {
      // Alt-Konto (oder unbekannt): frisches Salt erzeugen; Passwort geht
      // dieses eine Mal mit, der Server stellt bei Erfolg um.
      salt = EdCrypto.randomHex(16);
      document.getElementById('nsalt').value = salt;
    }
    const k = await EdCrypto.deriveKeys(pw, salt);
    document.getElementById('tok').value = k.authToken;
    EdCrypto.setDataKey(k.dataKeyHex);               // fuer diese Sitzung
    if (v === 1) { f.elements['password'].value = ''; }  // Server braucht es nicht
    f.dataset.ready = '1';
    state.textContent = '';
    f.submit();
  } catch (e2) {
    // Ohne Krypto (sehr alte Browser): normal absenden — Alt-Weg
    f.dataset.ready = '1';
    f.submit();
  }
});
</script>
<?php /* Footer im Fluss unter der Karte */ ?>
<footer class="sitefooter">© Gen-EM – OpenSource Software – <a href="https://github.com/gen-em/einsatzdoku-luftrettung/blob/main/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a></footer>
</div>
</body>
</html>
