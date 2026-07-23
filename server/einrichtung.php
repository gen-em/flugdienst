<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';

/**
 * Pflicht-Verschlüsselung — zwei Betriebsarten:
 *  - Ersteinrichtung (noch kein Inhaltsschluessel): Browser erzeugt
 *    Inhalts- und Wiederherstellungsschluessel; der Wiederherstellungs-
 *    schluessel wird EINMALIG angezeigt und muss bestaetigt werden.
 *  - Entsperren (Schluessel vorhanden, aber Passwort-Huelle passt nach
 *    einem Passwort-Reset nicht mehr): Wiederherstellungsschluessel
 *    eingeben -> Huelle wird neu erzeugt.
 * Der Server sieht in beiden Faellen nur Chiffretext.
 */

$notice = null; $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $wp = (string)($_POST['wrap_pw'] ?? '');
    $okWrap = preg_match('/^[A-Za-z0-9+\\/=]{20,4000}$/', $wp) === 1;

    if ($action === 'setup' && !$patReady) {
        $wr = (string)($_POST['wrap_rc'] ?? '');
        if ($okWrap && preg_match('/^[A-Za-z0-9+\\/=]{20,4000}$/', $wr)) {
            db()->prepare('UPDATE users SET pat_wrap_pw = ?, pat_wrap_rc = ? WHERE id = ?')
                ->execute([$wp, $wr, $userId]);
            header('Location: index.php'); exit;
        }
        $error = 'Einrichtung unvollständig (JavaScript nötig).';
    }
    if ($action === 'rewrap' && $patReady) {
        if ($okWrap) {
            db()->prepare('UPDATE users SET pat_wrap_pw = ? WHERE id = ?')
                ->execute([$wp, $userId]);
            header('Location: index.php'); exit;
        }
        $error = 'Entsperren fehlgeschlagen.';
    }
}

$wrapRc = null;
if ($patReady) {
    $q = db()->prepare('SELECT pat_wrap_rc FROM users WHERE id = ?');
    $q->execute([$userId]);
    $wrapRc = $q->fetchColumn() ?: null;
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verschlüsselung einrichten — Einsatzdoku</title>
<link rel="stylesheet" href="<?= asset('assets/style.css') ?>">
<link rel="icon" type="image/png" href="<?= asset('assets/images/favicon.png') ?>">
</head>
<body class="login-body">
<div class="login-wrap">
<div class="login-card setup-card">
  <img src="<?= e(logo_src()) ?>"
       alt="Einsatzdoku" class="login-logo">
  <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>

  <?php if (!$patReady): ?>
    <h1>Verschlüsselung einrichten</h1>
    <p>Diagnose, Alter und Einsatzort werden <strong>Ende-zu-Ende-verschlüsselt</strong>
       gespeichert — der Schlüssel entsteht aus deinem Login-Passwort und verlässt
       den Browser nie. Diese Einrichtung ist einmalig und Pflicht.</p>
    <p><strong>Wichtig:</strong> Gleich wird dein persönlicher
       <strong>Wiederherstellungsschlüssel</strong> angezeigt — <strong>nur dieses eine
       Mal</strong>. Nach einem Passwort-Reset ist er der einzige Weg zu deinen Daten.
       Ausdrucken oder sicher ablegen.</p>
    <div id="rcbox" class="keybox" hidden>
      <strong>Dein Wiederherstellungsschlüssel</strong>
      <p class="codebig" id="rccode" style="font-size:1.25rem"></p>
      <label class="checklabel"><input type="checkbox" id="rcok">
        Ich habe den Schlüssel sicher notiert.</label>
    </div>
    <form method="post" id="setupform">
      <?= csrf_field() ?><input type="hidden" name="action" value="setup">
      <input type="hidden" name="wrap_pw" id="f_wp">
      <input type="hidden" name="wrap_rc" id="f_wr">
      <button class="btn-primary" id="gobtn">Schlüssel erzeugen</button>
      <p class="muted" id="state" style="min-height:1.2em"></p>
    </form>

  <?php else: ?>
    <h1>Daten entsperren</h1>
    <p>Nach einem Passwort-Reset passt die Passwort-Hülle deines
       Inhaltsschlüssels nicht mehr. Gib deinen
       <strong>Wiederherstellungsschlüssel</strong> ein, um den Zugriff mit dem
       neuen Passwort wiederherzustellen.</p>
    <form method="post" id="unlockform">
      <?= csrf_field() ?><input type="hidden" name="action" value="rewrap">
      <input type="hidden" name="wrap_pw" id="u_wp">
      <label>Wiederherstellungsschlüssel
        <input type="text" id="u_code" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX" autocomplete="off"></label>
      <button class="btn-primary">Entsperren</button>
      <p class="muted" id="state" style="min-height:1.2em"></p>
    </form>
    <p><a class="add-link" href="index.php">← zurück zur Übersicht</a></p>
  <?php endif; ?>
</div>
<footer class="sitefooter">© Gen-EM – OpenSource Software –
  <a href="https://github.com/gen-em/einsatzdoku-luftrettung/blob/main/LICENSE"
     target="_blank" rel="noopener">AGPL-3.0</a></footer>
</div>
<script src="<?= asset('assets/crypto.js') ?>"></script>
<script>
const WRAP_RC = <?= json_encode($wrapRc) ?>;
const SETUP = <?= $patReady ? 'false' : 'true' ?>;
const state = document.getElementById('state');

if (SETUP) {
  const form = document.getElementById('setupform');
  let generated = false;
  form.addEventListener('submit', async ev => {
    if (form.dataset.ready === '1') return;
    ev.preventDefault();
    const dk = EdCrypto.getDataKey();
    if (!dk) {
      state.textContent = 'Sitzungsschlüssel fehlt — bitte einmal ab- und neu anmelden.';
      return;
    }
    if (!generated) {
      const ck = EdCrypto.randomHex(32);
      const rc = EdCrypto.newRecoveryCode();
      const rk = await EdCrypto.recoveryKeyHex(rc);
      document.getElementById('f_wp').value = await EdCrypto.encrypt(dk, ck);
      document.getElementById('f_wr').value = await EdCrypto.encrypt(rk, ck);
      document.getElementById('rccode').textContent = rc;
      document.getElementById('rcbox').hidden = false;
      document.getElementById('gobtn').textContent = 'Einrichtung abschließen';
      state.textContent = 'Schlüssel sichern, Haken setzen, dann abschließen.';
      sessionStorage.setItem('pck', ck);
      generated = true;
      return;
    }
    if (!document.getElementById('rcok').checked) {
      state.textContent = 'Bitte bestätigen, dass der Schlüssel notiert ist.';
      return;
    }
    form.dataset.ready = '1';
    form.submit();
  });
} else {
  const form = document.getElementById('unlockform');
  form.addEventListener('submit', async ev => {
    if (form.dataset.ready === '1') return;
    ev.preventDefault();
    const dk = EdCrypto.getDataKey();
    if (!dk) {
      state.textContent = 'Sitzungsschlüssel fehlt — bitte einmal ab- und neu anmelden.';
      return;
    }
    try {
      const rk = await EdCrypto.recoveryKeyHex(document.getElementById('u_code').value);
      const ck = await EdCrypto.decrypt(rk, WRAP_RC);
      document.getElementById('u_wp').value = await EdCrypto.encrypt(dk, ck);
      sessionStorage.setItem('pck', ck);
      form.dataset.ready = '1';
      form.submit();
    } catch (e) {
      state.textContent = 'Schlüssel passt nicht.';
    }
  });
}
</script>
</body>
</html>
