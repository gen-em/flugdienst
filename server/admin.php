<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/smtp.php';
require_admin();

$notice = null; $newKey = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'user_add') {
        $email = trim($_POST['email'] ?? '');
        $role  = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            db()->prepare('INSERT INTO users (email, role) VALUES (?, ?)')->execute([$email, $role]);
            $uid = (int)db()->lastInsertId();
            $token = bin2hex(random_bytes(32));
            db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at)
                           VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
                ->execute([$uid, hash('sha256', $token)]);
            smtp_send($email, 'Zugang zur Einsatzdoku',
                "Hallo,\n\nfür dich wurde ein Zugang angelegt. Setze hier dein Passwort (Link 24 h gültig):\n\n"
                . $CFG['app']['base_url'] . '/reset_confirm.php?token=' . $token . "\n");
            $notice = 'Nutzer angelegt — Setz-Link per E-Mail verschickt.';
        } else { $notice = 'Ungültige E-Mail-Adresse.'; }
    }

    if ($action === 'user_del') {
        $uid = (int)($_POST['id'] ?? 0);
        if ($uid !== $userId) { // sich selbst nicht loeschen
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            $notice = 'Nutzer gelöscht (inkl. Geräte und Daten).';
        } else { $notice = 'Du kannst dich nicht selbst löschen.'; }
    }

    if ($action === 'device_add') {
        $forUser = (int)($_POST['user_id'] ?? 0);
        $label   = trim($_POST['label'] ?? '');
        $devId   = 'dev-' . bin2hex(random_bytes(4));
        $key     = bin2hex(random_bytes(24));           // 48 Hex-Zeichen
        db()->prepare('INSERT INTO devices (user_id, device_id, api_key_hash, label) VALUES (?,?,?,?)')
            ->execute([$forUser, $devId, password_hash($key, PASSWORD_DEFAULT), $label ?: null]);
        $newKey = ['device_id' => $devId, 'api_key' => $key];
        $notice = 'Gerät angelegt. Schlüssel unten JETZT notieren — er wird nur einmal angezeigt.';
    }

    if ($action === 'device_del') {
        db()->prepare('DELETE FROM devices WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        $notice = 'Gerät gelöscht.';
    }
}

$users   = db()->query('SELECT id, email, role, created_at FROM users ORDER BY email')->fetchAll();
$devices = db()->query('SELECT d.id, d.device_id, d.label, d.last_seen, u.email
                        FROM devices d JOIN users u ON u.id = d.user_id ORDER BY u.email')->fetchAll();
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verwaltung — Einsatzdoku</title>
<link rel="stylesheet" href="assets/style.css"></head>
<body>
<header class="topbar">
  <span class="brand">Einsatzdoku</span>
  <nav><a href="index.php">Übersicht</a> <a class="active" href="admin.php">Verwaltung</a> <a href="logout.php">Abmelden</a></nav>
</header>
<main class="page">
  <?php if ($notice): ?><p class="alert alert-info"><?= e($notice) ?></p><?php endif; ?>
  <?php if ($newKey): ?>
    <div class="keybox">
      <strong>Neues Gerät</strong>
      <p>Geräte-ID: <code><?= e($newKey['device_id']) ?></code><br>
         API-Schlüssel: <code><?= e($newKey['api_key']) ?></code></p>
      <p>Beide Werte in die Uhr-App eintragen. Der Schlüssel wird serverseitig nur als Hash gespeichert.</p>
    </div>
  <?php endif; ?>

  <section>
    <h2>NutzerInnen</h2>
    <table class="data">
      <thead><tr><th>E-Mail</th><th>Rolle</th><th>Seit</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['email']) ?></td>
          <td><?= e($u['role']) ?></td>
          <td><?= e(fmt_local($u['created_at'], 'd.m.Y')) ?></td>
          <td>
            <?php if ((int)$u['id'] !== $userId): ?>
            <form method="post" onsubmit="return confirm('Nutzer und alle zugehörigen Daten löschen?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="user_del">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="btn-danger">Löschen</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="user_add">
      <input type="email" name="email" placeholder="neue@adresse.de" required>
      <select name="role"><option value="user">user</option><option value="admin">admin</option></select>
      <button class="btn-primary">Nutzer anlegen</button>
    </form>
  </section>

  <section>
    <h2>Geräte (Uhren)</h2>
    <table class="data">
      <thead><tr><th>Geräte-ID</th><th>Bezeichnung</th><th>NutzerIn</th><th>Zuletzt gesehen</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($devices as $d): ?>
        <tr>
          <td><code><?= e($d['device_id']) ?></code></td>
          <td><?= e($d['label'] ?? '–') ?></td>
          <td><?= e($d['email']) ?></td>
          <td><?= e($d['last_seen'] ? fmt_local($d['last_seen'], 'd.m.Y H:i') : 'nie') ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Gerät löschen? Bereits hochgeladene Daten bleiben nicht erhalten.')">
              <?= csrf_field() ?><input type="hidden" name="action" value="device_del">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn-danger">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="device_add">
      <select name="user_id" required>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= e($u['email']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="label" placeholder="z. B. Fenix 6 Pro Philipp">
      <button class="btn-primary">Gerät anlegen</button>
    </form>
  </section>
</main>
</body>
</html>
