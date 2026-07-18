<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';

$notice = null; $newKey = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $label = trim($_POST['label'] ?? '');
        $devId = 'dev-' . bin2hex(random_bytes(4));
        $key   = bin2hex(random_bytes(24));
        db()->prepare('INSERT INTO devices (user_id, device_id, api_key_hash, label) VALUES (?,?,?,?)')
            ->execute([$userId, $devId, password_hash($key, PASSWORD_DEFAULT), $label ?: null]);
        $newKey = ['device_id' => $devId, 'api_key' => $key];
        $notice = 'Gerät angelegt. Schlüssel unten JETZT notieren — er wird nur einmal angezeigt.';
    }

    if ($action === 'toggle') {
        // nur eigene Geraete schaltbar
        db()->prepare('UPDATE devices SET active = 1 - active WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Status geändert.';
    }
}

$st = db()->prepare('SELECT id, device_id, label, active, last_seen FROM devices
                     WHERE user_id = ? AND device_id NOT LIKE \'manual-%\' ORDER BY created_at');
$st->execute([$userId]);
$devices = $st->fetchAll();
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Geräte — Einsatzdoku</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/favicon.png"></head>
<body>
<header class="topbar">
  <a class="brand" href="index.php"><img src="assets/logo-weiss.png" alt="GenEM Einsatzdoku"></a>
  <nav>
    <a href="index.php">Übersicht</a>
    <?php if ($userRole === 'admin'): ?><a href="admin.php">Verwaltung</a><?php endif; ?>
    <a class="active" href="geraete.php">Geräte</a>
    <a href="logout.php">Abmelden</a>
  </nav>
</header>
<main class="page">
  <h1>Meine Geräte</h1>
  <p class="muted">Jedes Gerät (Uhr) bekommt eigene Zugangsdaten für den Upload.
     Deaktivieren sperrt den Schlüssel — bereits hochgeladene Daten bleiben erhalten.</p>

  <?php if ($notice): ?><p class="alert alert-info"><?= e($notice) ?></p><?php endif; ?>
  <?php if ($newKey): ?>
    <div class="keybox">
      <strong>Neues Gerät</strong>
      <p>Geräte-ID: <code><?= e($newKey['device_id']) ?></code><br>
         API-Schlüssel: <code><?= e($newKey['api_key']) ?></code></p>
      <p>Beide Werte in den Connect-IQ-Einstellungen der Uhr-App eintragen
         (Server-URL: <code><?= e($CFG['app']['base_url']) ?>/ingest.php</code>).</p>
    </div>
  <?php endif; ?>

  <table class="data">
    <thead><tr><th>Geräte-ID</th><th>Bezeichnung</th><th>Status</th><th>Zuletzt gesehen</th><th></th></tr></thead>
    <tbody>
    <?php if (!$devices): ?>
      <tr><td colspan="5" class="muted">Noch keine Geräte angelegt.</td></tr>
    <?php endif; ?>
    <?php foreach ($devices as $d): ?>
      <tr>
        <td><code><?= e($d['device_id']) ?></code></td>
        <td><?= e($d['label'] ?? '–') ?></td>
        <td><?= (int)$d['active'] ? 'aktiv' : '<span class="muted">deaktiviert</span>' ?></td>
        <td><?= e($d['last_seen'] ? fmt_local($d['last_seen'], 'd.m.Y H:i') : 'nie') ?></td>
        <td>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="btn-danger"><?= (int)$d['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <form method="post" class="inline-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <input type="text" name="label" placeholder="Bezeichnung, z. B. Fenix 6 Pro">
    <button class="btn-primary">Gerät anlegen</button>
  </form>
</main>
<footer class="sitefooter">© Gen-EM · <a href="https://github.com/gen-em/einsatzdoku-luftrettung/blob/main/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a></footer>
</body>
</html>
