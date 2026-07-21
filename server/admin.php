<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/smtp.php';
require_admin();

$notice = null;

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

}

$users   = db()->query('SELECT id, email, name, role, created_at FROM users ORDER BY email')->fetchAll();
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Administration — Einsatzdoku</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/favicon.png"></head>
<body>
<?php ui_topbar('einstellungen'); ?>

<div class="layout">
  <?php ui_settings_sidebar('admin'); ?>

<main class="page">
  <?php if ($notice): ?><p class="alert alert-info"><?= e($notice) ?></p><?php endif; ?>

  <section>
    <h2>NutzerInnen</h2>
    <table class="data">
      <thead><tr><th>E-Mail</th><th>Name</th><th>Rolle</th><th>Seit</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr class="rowlink" onclick="location.href='admin_user.php?id=<?= (int)$u['id'] ?>'">
          <td><a href="admin_user.php?id=<?= (int)$u['id'] ?>"><?= e($u['email']) ?></a></td>
          <td><?= e($u['name'] ?? '–') ?></td>
          <td><?= e($u['role']) ?></td>
          <td><?= e(fmt_local($u['created_at'], 'd.m.Y')) ?></td>
          <td>
            <?php if ((int)$u['id'] !== $userId): ?>
            <form method="post" onclick="event.stopPropagation()" data-confirm="Nutzer und alle zugehörigen Daten löschen?">
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
<?php ui_footer(); ?>
</main>
</div>
</body>
</html>
