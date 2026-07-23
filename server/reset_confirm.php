<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/**
 * Neues Passwort nach "Passwort vergessen".
 *
 * Die Daten sind Ende-zu-Ende-verschluesselt: Der Inhaltsschluessel liegt
 * zweifach verpackt vor — einmal mit dem aus dem Passwort abgeleiteten
 * Schluessel, einmal mit dem Wiederherstellungsschluessel. Ein neues Passwort
 * macht die erste Verpackung wertlos, deshalb ist der
 * Wiederherstellungsschluessel hier zwingend: Der Browser entpackt damit den
 * Inhaltsschluessel und verpackt ihn fuer das neue Passwort neu.
 *
 * Der Server bekommt nie das Passwort, nur das abgeleitete Token, das neue
 * Salz und die neue Verpackung — und speichert alles gemeinsam oder gar nicht.
 */

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$row = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $st = db()->prepare('SELECT r.id, r.user_id, u.kdf_salt, u.pat_wrap_rc
                         FROM password_resets r
                         JOIN users u ON u.id = r.user_id
                         WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()');
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch();
}

$error = null; $done = false;
if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $neuTok  = (string)($_POST['new_token'] ?? '');
    $neuSalt = (string)($_POST['new_salt'] ?? '');
    $wrapPw  = (string)($_POST['wrap_pw'] ?? '');

    if (!preg_match('/^[0-9a-f]{64}$/', $neuTok) || !preg_match('/^[0-9a-f]{32}$/', $neuSalt)) {
        // Kommt nur vor, wenn JavaScript fehlt oder abbricht.
        $error = 'Zurücksetzen unvollständig — bitte JavaScript aktivieren und erneut versuchen.';
    } elseif (!preg_match('#^[A-Za-z0-9+/=]{20,4000}$#', $wrapPw)) {
        // Der Browser konnte den Inhaltsschluessel nicht neu verpacken:
        // fast immer ein falscher Wiederherstellungsschluessel.
        $error = 'Der Wiederherstellungsschlüssel passt nicht. Es wurde nichts geändert.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Alles zusammen — sonst entstuende ein Konto, das sich zwar
            // anmelden laesst, dessen Daten aber unlesbar waeren.
            $pdo->prepare('UPDATE users SET password_hash = ?, kdf_salt = ?, kdf_ver = 1,
                                            pat_wrap_pw = ? WHERE id = ?')
                ->execute([password_hash($neuTok, PASSWORD_DEFAULT), $neuSalt,
                           $wrapPw, (int)$row['user_id']]);
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
                ->execute([(int)$row['id']]);
            $pdo->commit();
            $done = true;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $error = 'Zurücksetzen fehlgeschlagen. Bitte erneut versuchen.';
        }
    }
}
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Neues Passwort — Einsatzdoku</title>
<link rel="stylesheet" href="<?= asset('assets/style.css') ?>">
<?= favicon_tags() ?></head>
<body class="login-body">
<main class="login-card">
  <h1>Neues Passwort</h1>
  <?php if ($done): ?>
    <p>Passwort gespeichert und die verschlüsselten Angaben übernommen.
       Du kannst dich jetzt anmelden.</p>
    <p class="login-aux"><a href="login.php">Zur Anmeldung</a></p>
  <?php elseif (!$row): ?>
    <p class="alert">Dieser Link ist ungültig oder abgelaufen.</p>
    <p class="login-aux"><a href="reset_request.php">Neuen Link anfordern</a></p>
  <?php else: ?>
    <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
    <p class="muted">Deine Einsatzdaten sind mit deinem Passwort verschlüsselt.
       Damit sie lesbar bleiben, brauchst du hier den
       <strong>Wiederherstellungsschlüssel</strong> aus der Einrichtung.</p>
    <form method="post" id="resetform">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <input type="hidden" name="new_token" id="new_token">
      <input type="hidden" name="new_salt"  id="new_salt">
      <input type="hidden" name="wrap_pw"   id="wrap_pw">
      <label>Wiederherstellungsschlüssel
        <input type="text" id="rc" required autocomplete="off" autocapitalize="characters"
               placeholder="ABCD-EFGH-JKMN-PQRS-TVWX">
      </label>
      <label>Neues Passwort (min. 10 Zeichen)
        <input type="password" id="pw1" required minlength="10" autocomplete="new-password">
      </label>
      <label>Wiederholen
        <input type="password" id="pw2" required minlength="10" autocomplete="new-password">
      </label>
      <button type="submit" class="btn-primary">Passwort speichern</button>
      <p class="muted small" id="state" style="min-height:1.2em"></p>
    </form>
  <?php endif; ?>
</main>
<script src="<?= asset('assets/crypto.js') ?>"></script>
<script>
const WRAP_RC = <?= json_encode($row['pat_wrap_rc'] ?? null) ?>;
const state = document.getElementById('state');

document.getElementById('resetform').addEventListener('submit', async ev => {
  ev.preventDefault();                       // synchron, vor jedem await
  const pw1 = document.getElementById('pw1').value;
  const pw2 = document.getElementById('pw2').value;
  const rc  = document.getElementById('rc').value.trim();

  if (pw1.length < 10) { state.textContent = 'Mindestens 10 Zeichen.'; return; }
  if (pw1 !== pw2)     { state.textContent = 'Die Passwörter stimmen nicht überein.'; return; }
  if (!WRAP_RC)        { state.textContent = 'Für dieses Konto ist keine Wiederherstellung hinterlegt.'; return; }

  try {
    state.textContent = 'Wiederherstellungsschlüssel wird geprüft …';
    const rcKey = await EdCrypto.recoveryKeyHex(rc);
    let ck;
    try {
      ck = await EdCrypto.decrypt(rcKey, WRAP_RC);      // Inhaltsschlüssel entpacken
    } catch (e) {
      state.textContent = 'Der Wiederherstellungsschlüssel passt nicht.';
      return;                                           // nichts absenden
    }

    state.textContent = 'Neues Passwort wird eingerichtet …';
    const salt = EdCrypto.randomHex(16);
    const k    = await EdCrypto.deriveKeys(pw1, salt);
    const wrap = await EdCrypto.encrypt(k.dataKeyHex, ck);   // neu verpacken

    document.getElementById('new_salt').value  = salt;
    document.getElementById('new_token').value = k.authToken;
    document.getElementById('wrap_pw').value   = wrap;
    ev.target.submit();
  } catch (e) {
    state.textContent = 'Fehlgeschlagen: ' + e.message;
  }
});
</script>
</body>
</html>
