<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';

$tab = $_GET['t'] ?? 'profil';
if (!in_array($tab, ['profil', 'geraete', 'stammdaten'], true)) { $tab = 'profil'; }
$notice = null; $error = null; $newKey = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    /* ---- Profil: Name & E-Mail ---------------------------------------- */
    if ($action === 'profile') {
        $name  = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte eine gültige E-Mail-Adresse angeben.';
        } else {
            try {
                db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')
                    ->execute([$name !== '' ? $name : null, $email, $userId]);
                $userName = $name !== '' ? $name : null;
                $userEmail = $email;
                $notice = 'Profil gespeichert.';
            } catch (PDOException $ex) {
                $error = 'Diese E-Mail-Adresse wird bereits verwendet.';
            }
        }
    }

    /* ---- Profil: Passwort (nur mit korrektem alten Passwort) ----------- */
    if ($action === 'password') {
        $old = (string)($_POST['old'] ?? '');
        $new = (string)($_POST['new1'] ?? '');
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$userId]);
        $hash = (string)$st->fetchColumn();
        if (!password_verify($old, $hash)) {
            $error = 'Das aktuelle Passwort ist nicht korrekt.';
        } elseif (strlen($new) < 10) {
            $error = 'Das neue Passwort braucht mindestens 10 Zeichen.';
        } elseif ($new !== (string)($_POST['new2'] ?? '')) {
            $error = 'Die neuen Passwörter stimmen nicht überein.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            $notice = 'Passwort geändert.';
        }
    }

    /* ---- Geräte (Selbstverwaltung) ------------------------------------- */
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
        db()->prepare('UPDATE devices SET active = 1 - active WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Status geändert.';
    }
    if ($action === 'delete') {
        // FK setzt device_id in Einsaetzen/Segmenten auf NULL -> Daten bleiben
        db()->prepare('DELETE FROM devices WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Gerät gelöscht. Bereits hochgeladene Daten bleiben erhalten.';
    }

    /* ---- Stammdaten ----------------------------------------------------- */
    if ($action === 'base_add') {
        $n = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        if ($n !== '') {
            db()->prepare('INSERT IGNORE INTO bases (user_id, name) VALUES (?,?)')->execute([$userId, $n]);
            $notice = 'Standort gespeichert.';
        }
    }
    if ($action === 'base_del') {
        db()->prepare('DELETE FROM bases WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Standort gelöscht.';
    }
    if ($action === 'ac_save') {
        $reg = mb_substr(trim($_POST['registration'] ?? ''), 0, 64);
        $acId = (int)($_POST['id'] ?? 0);
        if ($reg !== '') {
            $flags = [];
            foreach (['p1','p2','hems','fr','other'] as $r) { $flags[] = isset($_POST[$r]) ? 1 : 0; }
            if ($acId > 0) {
                db()->prepare('UPDATE aircraft SET registration=?, p1=?, p2=?, hems=?, fr=?, other=?
                               WHERE id = ? AND user_id = ?')
                    ->execute(array_merge([$reg], $flags, [$acId, $userId]));
                $notice = 'Hubschrauber gespeichert.';
            } else {
                try {
                    db()->prepare('INSERT INTO aircraft (user_id, registration, p1, p2, hems, fr, other)
                                   VALUES (?,?,?,?,?,?,?)')
                        ->execute(array_merge([$userId, $reg], $flags));
                    $notice = 'Hubschrauber angelegt.';
                } catch (PDOException $ex) { $error = 'Diese Kennung existiert bereits.'; }
            }
        }
    }
    if ($action === 'ac_del') {
        db()->prepare('DELETE FROM aircraft WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Hubschrauber gelöscht.';
    }
    if ($action === 'crew_add') {
        $role = $_POST['role'] ?? '';
        $n = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        if ($n !== '' && in_array($role, ['p1','p2','hems','fr','other'], true)) {
            db()->prepare('INSERT IGNORE INTO crew_presets (user_id, role, name) VALUES (?,?,?)')
                ->execute([$userId, $role, $n]);
            $notice = 'Eintrag gespeichert.';
        }
    }
    if ($action === 'crew_del') {
        db()->prepare('DELETE FROM crew_presets WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Eintrag gelöscht.';
    }
    if ($action === 'bw_add') {
        $n = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        if ($n !== '') {
            db()->prepare('INSERT IGNORE INTO bw_units (user_id, name) VALUES (?,?)')->execute([$userId, $n]);
            $notice = 'Bereitschaft gespeichert.';
        }
    }
    if ($action === 'bw_del') {
        db()->prepare('DELETE FROM bw_units WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Bereitschaft gelöscht.';
    }
}

$ROLE_LABELS = ['p1' => 'Pilot 1', 'p2' => 'Pilot 2', 'hems' => 'HEMS',
                'fr' => 'Flugretter', 'other' => 'Sonstige'];

$devices = [];
if ($tab === 'geraete') {
    $st = db()->prepare('SELECT id, device_id, label, active, last_seen FROM devices
                         WHERE user_id = ? AND device_id NOT LIKE \'manual-%\' ORDER BY created_at');
    $st->execute([$userId]);
    $devices = $st->fetchAll();
}
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Einstellungen — Einsatzdoku</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/favicon.png"></head>
<body>
<?php ui_topbar('einstellungen'); ?>

<div class="layout">
  <aside class="daylist">
    <h2>Einstellungen</h2>
    <ul>
      <li><a href="einstellungen.php?t=profil" <?= $tab === 'profil' ? 'class="active"' : '' ?>>Profil</a></li>
      <li><a href="einstellungen.php?t=stammdaten" <?= $tab === 'stammdaten' ? 'class="active"' : '' ?>>Stammdaten</a></li>
      <li><a href="einstellungen.php?t=geraete" <?= $tab === 'geraete' ? 'class="active"' : '' ?>>Geräte</a></li>
      <li><a href="logout.php">Abmelden</a></li>
    </ul>
  </aside>

  <main class="page">
  <?php if ($notice): ?><p class="alert alert-info"><?= e($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>

  <?php if ($tab === 'profil'): ?>
    <h1>Profil</h1>

    <form method="post" class="settings-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <label>Name <input type="text" name="name" maxlength="120"
        value="<?= e($userName ?? '') ?>" placeholder="wird in der Kopfleiste angezeigt"></label>
      <label>E-Mail-Adresse (Login) <input type="email" name="email" required
        value="<?= e($userEmail) ?>"></label>
      <button class="btn-primary">Profil speichern</button>
    </form>

    <h2>Passwort ändern</h2>
    <form method="post" class="settings-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <label>Aktuelles Passwort <input type="password" name="old" required autocomplete="current-password"></label>
      <label>Neues Passwort (mind. 10 Zeichen) <input type="password" name="new1" required minlength="10" autocomplete="new-password"></label>
      <label>Neues Passwort wiederholen <input type="password" name="new2" required autocomplete="new-password"></label>
      <button class="btn-primary">Passwort ändern</button>
    </form>

  <?php elseif ($tab === 'stammdaten'): ?>
    <?php
      $bases = db()->prepare('SELECT id, name FROM bases WHERE user_id = ? ORDER BY name');
      $bases->execute([$userId]); $bases = $bases->fetchAll();
      $acs = db()->prepare('SELECT * FROM aircraft WHERE user_id = ? ORDER BY registration');
      $acs->execute([$userId]); $acs = $acs->fetchAll();
      $crew = db()->prepare('SELECT id, role, name FROM crew_presets WHERE user_id = ? ORDER BY role, name');
      $crew->execute([$userId]); $crew = $crew->fetchAll();
      $bw = db()->prepare('SELECT id, name FROM bw_units WHERE user_id = ? ORDER BY name');
      $bw->execute([$userId]); $bw = $bw->fetchAll();
      $editAc = null;
      if (isset($_GET['ac'])) {
          foreach ($acs as $a) { if ((int)$a['id'] === (int)$_GET['ac']) { $editAc = $a; } }
      }
    ?>
    <h1>Stammdaten</h1>
    <p class="muted">Vorbelegungen für die Flugtag- und Einsatzdokumentation.
       Löschen entfernt nur den Listeneintrag — bereits gespeicherte Flugtage bleiben unverändert.</p>

    <h2>Standorte</h2>
    <ul class="chips">
      <?php foreach ($bases as $b): ?>
        <li><?= e($b['name']) ?>
          <form method="post" action="einstellungen.php?t=stammdaten">
            <?= csrf_field() ?><input type="hidden" name="action" value="base_del">
            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <button class="chip-x" title="Löschen">✕</button>
          </form></li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="einstellungen.php?t=stammdaten" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="base_add">
      <input type="text" name="name" placeholder="z. B. Kempten" maxlength="120" required>
      <button class="btn-primary">Standort hinzufügen</button>
    </form>

    <h2>Hubschrauber</h2>
    <p class="muted">Die angehakten Rollen bestimmen, welche Besatzungsfelder am Flugtag erscheinen.</p>
    <table class="data">
      <thead><tr><th>Kennung</th><th>Rollen</th><th></th></tr></thead>
      <tbody>
      <?php if (!$acs): ?><tr><td colspan="3" class="muted">Noch keine Hubschrauber.</td></tr><?php endif; ?>
      <?php foreach ($acs as $a): ?>
        <tr>
          <td><?= e($a['registration']) ?></td>
          <td><?php $r = [];
            foreach ($ROLE_LABELS as $k => $lbl) { if ((int)$a[$k]) { $r[] = $lbl; } }
            echo e($r ? implode(' · ', $r) : '–'); ?></td>
          <td class="actions">
            <a class="btn-danger" href="einstellungen.php?t=stammdaten&amp;ac=<?= (int)$a['id'] ?>">Bearbeiten</a>
            <form method="post" action="einstellungen.php?t=stammdaten"
                  onsubmit="return confirm('Hubschrauber löschen?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="ac_del">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button class="btn-danger">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" action="einstellungen.php?t=stammdaten" class="ac-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="ac_save">
      <input type="hidden" name="id" value="<?= $editAc ? (int)$editAc['id'] : 0 ?>">
      <div class="inline-form">
        <input type="text" name="registration" maxlength="64" required
               placeholder="Kennung, z. B. Christoph 17"
               value="<?= e($editAc['registration'] ?? '') ?>">
        <button class="btn-primary"><?= $editAc ? 'Änderungen speichern' : 'Hubschrauber anlegen' ?></button>
        <?php if ($editAc): ?><a class="add-link" href="einstellungen.php?t=stammdaten">abbrechen</a><?php endif; ?>
      </div>
      <div class="rolechecks">
        <?php foreach ($ROLE_LABELS as $k => $lbl): ?>
          <label><input type="checkbox" name="<?= $k ?>"
            <?= ($editAc && (int)$editAc[$k]) ? 'checked' : '' ?>> <?= e($lbl) ?></label>
        <?php endforeach; ?>
      </div>
    </form>

    <h2>Besatzung — Vorbelegungen</h2>
    <p class="muted">Diese Namen erscheinen am Flugtag als Auswahl im jeweiligen Rollen-Dropdown.</p>
    <?php foreach ($ROLE_LABELS as $rk => $lbl): ?>
      <h3 class="rolehead"><?= e($lbl) ?></h3>
      <ul class="chips">
        <?php foreach ($crew as $c): if ($c['role'] !== $rk) continue; ?>
          <li><?= e($c['name']) ?>
            <form method="post" action="einstellungen.php?t=stammdaten">
              <?= csrf_field() ?><input type="hidden" name="action" value="crew_del">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="chip-x" title="Löschen">✕</button>
            </form></li>
        <?php endforeach; ?>
      </ul>
      <form method="post" action="einstellungen.php?t=stammdaten" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="crew_add">
        <input type="hidden" name="role" value="<?= $rk ?>">
        <input type="text" name="name" placeholder="Name" maxlength="120" required>
        <button class="btn-primary">Hinzufügen</button>
      </form>
    <?php endforeach; ?>

    <h2>Bergwacht-Bereitschaften</h2>
    <ul class="chips">
      <?php foreach ($bw as $b): ?>
        <li><?= e($b['name']) ?>
          <form method="post" action="einstellungen.php?t=stammdaten">
            <?= csrf_field() ?><input type="hidden" name="action" value="bw_del">
            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <button class="chip-x" title="Löschen">✕</button>
          </form></li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="einstellungen.php?t=stammdaten" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="bw_add">
      <input type="text" name="name" placeholder="z. B. Bereitschaft Oberstdorf" maxlength="120" required>
      <button class="btn-primary">Bereitschaft hinzufügen</button>
    </form>

  <?php else: ?>
    <h1>Geräte</h1>
    <p class="muted">Jedes Gerät (Uhr) bekommt eigene Zugangsdaten für den Upload.
       Deaktivieren sperrt den Schlüssel — bereits hochgeladene Daten bleiben erhalten.</p>

    <?php if ($newKey): ?>
      <div class="keybox">
        <strong>Neues Gerät</strong>
        <p>Geräte-ID: <code><?= e($newKey['device_id']) ?></code><br>
           API-Schlüssel: <code><?= e($newKey['api_key']) ?></code></p>
        <p>Beide Werte in den Connect-IQ-Einstellungen der Uhr-App eintragen
           (als Server genügt die Domain, z. B. <code>luftrettung.net</code>).</p>
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
          <td class="actions">
            <form method="post" action="einstellungen.php?t=geraete">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn-danger"><?= (int)$d['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
            </form>
            <form method="post" action="einstellungen.php?t=geraete"
                  onsubmit="return confirm('Gerät wirklich löschen? Bereits hochgeladene Daten bleiben erhalten.')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn-danger">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <form method="post" action="einstellungen.php?t=geraete" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <input type="text" name="label" placeholder="Bezeichnung, z. B. Fenix 6 Pro">
      <button class="btn-primary">Gerät anlegen</button>
    </form>
  <?php endif; ?>

  <?php ui_footer(); ?>
  </main>
</div>
</body>
</html>
