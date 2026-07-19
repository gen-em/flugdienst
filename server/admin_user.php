<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
if ($userRole !== 'admin') { http_response_code(403); exit('Nur für Admins.'); }

$uid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$notice = null; $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'role') {
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
        if ($uid === $userId && $role !== 'admin') {
            $error = 'Du kannst dir nicht selbst die Admin-Rolle entziehen.';
        } else {
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $uid]);
            $notice = 'Rolle geändert.';
        }
    }
    if ($action === 'email') {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte eine gültige E-Mail-Adresse angeben.';
        } else {
            try {
                db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $uid]);
                $notice = 'E-Mail-Adresse geändert.';
            } catch (PDOException $ex) { $error = 'Diese E-Mail-Adresse wird bereits verwendet.'; }
        }
    }
    if ($action === 'password') {
        $new = (string)($_POST['new1'] ?? '');
        if (strlen($new) < 10) { $error = 'Das neue Passwort braucht mindestens 10 Zeichen.'; }
        else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            $notice = 'Neues Passwort gesetzt. Bitte der Person sicher mitteilen.';
        }
    }
    if ($action === 'device_toggle') {
        db()->prepare('UPDATE devices SET active = 1 - active WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['dev'] ?? 0), $uid]);
        $notice = 'Gerätestatus geändert.';
    }
    if ($action === 'device_delete') {
        // Daten bleiben erhalten: FK setzt device_id in Einsaetzen/Segmenten auf NULL
        db()->prepare('DELETE FROM devices WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['dev'] ?? 0), $uid]);
        $notice = 'Gerät gelöscht. Hochgeladene Daten bleiben erhalten.';
    }
}

$st = db()->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$uid]);
$u = $st->fetch();
if (!$u) { http_response_code(404); exit('NutzerIn nicht gefunden.'); }

$dv = db()->prepare('SELECT id, device_id, label, active, last_seen FROM devices
                     WHERE user_id = ? AND device_id NOT LIKE \'manual-%\' ORDER BY created_at');
$dv->execute([$uid]);
$devices = $dv->fetchAll();
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>NutzerIn bearbeiten — Einsatzdoku</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/favicon.png"></head>
<body>
<?php ui_topbar('admin'); ?>

<div class="layout">
  <aside class="daylist">
    <h2>Administration</h2>
    <ul><li><a href="admin.php" class="active">Nutzerverwaltung</a></li></ul>
  </aside>

  <main class="page">
  <p><a href="admin.php" class="add-link">← zurück zur Nutzerverwaltung</a></p>
  <h1><?= e($u['name'] ?: $u['email']) ?></h1>
  <?php if ($notice): ?><p class="alert alert-info"><?= e($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>

  <h2>Rolle</h2>
  <form method="post" class="inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="role">
    <input type="hidden" name="id" value="<?= $uid ?>">
    <select name="role">
      <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>user</option>
      <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
    </select>
    <button class="btn-primary">Rolle speichern</button>
  </form>

  <h2>E-Mail-Adresse (Login)</h2>
  <form method="post" class="inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="email">
    <input type="hidden" name="id" value="<?= $uid ?>">
    <input type="email" name="email" required value="<?= e($u['email']) ?>">
    <button class="btn-primary">E-Mail speichern</button>
  </form>

  <h2>Neues Passwort vergeben</h2>
  <form method="post" class="inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <input type="hidden" name="id" value="<?= $uid ?>">
    <input type="password" name="new1" required minlength="10" placeholder="mind. 10 Zeichen" autocomplete="new-password">
    <button class="btn-primary">Passwort setzen</button>
  </form>

  <h2>Verbundene Geräte</h2>
  <table class="data">
    <thead><tr><th>Geräte-ID</th><th>Bezeichnung</th><th>Status</th><th>Zuletzt gesehen</th><th></th></tr></thead>
    <tbody>
    <?php if (!$devices): ?><tr><td colspan="5" class="muted">Keine Geräte.</td></tr><?php endif; ?>
    <?php foreach ($devices as $d): ?>
      <tr>
        <td><code><?= e($d['device_id']) ?></code></td>
        <td><?= e($d['label'] ?? '–') ?></td>
        <td><?= (int)$d['active'] ? 'aktiv' : '<span class="muted">deaktiviert</span>' ?></td>
        <td><?= e($d['last_seen'] ? fmt_local($d['last_seen'], 'd.m.Y H:i') : 'nie') ?></td>
        <td class="actions">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="device_toggle">
            <input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="dev" value="<?= (int)$d['id'] ?>">
            <button class="btn-danger"><?= (int)$d['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
          </form>
          <form method="post" onsubmit="return confirm('Gerät wirklich löschen? Hochgeladene Daten bleiben erhalten.')">
            <?= csrf_field() ?><input type="hidden" name="action" value="device_delete">
            <input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="dev" value="<?= (int)$d['id'] ?>">
            <button class="btn-danger">Löschen</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php ui_footer(); ?>
  </main>
</div>
</body>
</html>
