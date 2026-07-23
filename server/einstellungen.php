<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';

$tab = $_GET['t'] ?? 'profil';
if (!in_array($tab, ['profil', 'geraete', 'stammdaten', 'backup'], true)) { $tab = 'profil'; }
$notice = null; $error = null; $newKey = null; $pairCode = null;

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
        // Browser-Krypto: alt wird per Token (oder Alt-Passwort) belegt,
        // neu kommt als Token+Salt; bei aktivem Modul zusaetzlich der neu
        // verpackte Inhaltsschluessel (Server sieht weiterhin nichts).
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$userId]);
        $u = $st->fetch();
        $oldOk = password_verify((string)($_POST['old_token'] ?? ''),
                                 (string)$u['password_hash']);
        $newTok = (string)($_POST['new_token'] ?? '');
        $newSalt = (string)($_POST['new_salt'] ?? '');
        if (!$oldOk) {
            $error = 'Das aktuelle Passwort ist nicht korrekt.';
        } elseif (!preg_match('/^[0-9a-f]{64}$/', $newTok)
                  || !preg_match('/^[0-9a-f]{32}$/', $newSalt)) {
            $error = 'Passwortwechsel unvollständig (JavaScript nötig).';
        } else {
            db()->prepare('UPDATE users SET password_hash = ?, kdf_salt = ?, kdf_ver = 1 WHERE id = ?')
                ->execute([password_hash($newTok, PASSWORD_DEFAULT), $newSalt, $userId]);
            if (!empty($_POST['wrap_pw'])) {
                db()->prepare('UPDATE users SET pat_wrap_pw = ? WHERE id = ?')
                    ->execute([mb_substr((string)$_POST['wrap_pw'], 0, 4000), $userId]);
            }
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
    if ($action === 'pair_code') {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // ohne 0/O und 1/I
        for ($try = 0; $try < 5; $try++) {
            $code = '';
            for ($i = 0; $i < 5; $i++) { $code .= $chars[random_int(0, strlen($chars) - 1)]; }
            try {
                db()->prepare('INSERT INTO pair_codes (user_id, code) VALUES (?,?)')
                    ->execute([$userId, $code]);
                $pairCode = $code;
                break;
            } catch (PDOException $ex) { /* Kollision -> neuer Versuch */ }
        }
    }
    if ($action === 'rename') {
        $lbl = mb_substr(trim($_POST['label'] ?? ''), 0, 120);
        db()->prepare('UPDATE devices SET label = ? WHERE id = ? AND user_id = ?')
            ->execute([$lbl !== '' ? $lbl : null, (int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Bezeichnung gespeichert.';
    }
    if ($action === 'delete') {
        // FK setzt device_id in Einsaetzen/Segmenten auf NULL -> Daten bleiben
        db()->prepare('DELETE FROM devices WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Gerät gelöscht. Bereits hochgeladene Daten bleiben erhalten.';
    }

    /* ---- Stammdaten ----------------------------------------------------- */
    if ($action === 'base_save') {
        $n = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        $bid = (int)($_POST['id'] ?? 0);
        if ($n !== '') {
            if ($bid > 0) {
                db()->prepare('UPDATE bases SET name = ? WHERE id = ? AND user_id = ?')
                    ->execute([$n, $bid, $userId]);
            } else {
                db()->prepare('INSERT IGNORE INTO bases (user_id, name) VALUES (?,?)')
                    ->execute([$userId, $n]);
            }
            $notice = 'Standort gespeichert.';
        }
    }
    if ($action === 'base_default') {
        $bid = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE bases SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
        db()->prepare('UPDATE bases SET is_default = 1 WHERE id = ? AND user_id = ?')
            ->execute([$bid, $userId]);
        $notice = 'Standard-Standort gesetzt.';
    }
    if ($action === 'ac_default') {
        $aid = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE aircraft SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
        db()->prepare('UPDATE aircraft SET is_default = 1 WHERE id = ? AND user_id = ?')
            ->execute([$aid, $userId]);
        $notice = 'Standard-Maschine gesetzt.';
    }
    if ($action === 'base_del') {
        // Standortnamen in den Flugtagen sichern (siehe ac_del)
        db()->prepare('UPDATE days d
                       JOIN bases b ON b.id = d.base_id
                          SET d.base = b.name
                        WHERE d.user_id = ? AND d.base_id = ?')
            ->execute([$userId, (int)($_POST['id'] ?? 0)]);
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
        // Bevor die Maschine verschwindet: ihren Namen in den betroffenen
        // Flugtagen als Text sichern, sonst stuende dort nach dem Loeschen
        // nichts mehr (Fremdschluessel wird auf NULL gesetzt).
        db()->prepare('UPDATE days d
                       JOIN aircraft a ON a.id = d.aircraft_id
                          SET d.aircraft = a.registration
                        WHERE d.user_id = ? AND d.aircraft_id = ?')
            ->execute([$userId, (int)($_POST['id'] ?? 0)]);
        db()->prepare('DELETE FROM aircraft WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Hubschrauber gelöscht.';
    }
    if ($action === 'crew_save') {
        $role = $_POST['role'] ?? '';
        $n = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        $cid = (int)($_POST['id'] ?? 0);
        if ($n !== '' && in_array($role, ['p1','p2','hems','fr','other'], true)) {
            if ($cid > 0) {
                db()->prepare('UPDATE crew_presets SET name = ? WHERE id = ? AND user_id = ?')
                    ->execute([$n, $cid, $userId]);
            } else {
                db()->prepare('INSERT IGNORE INTO crew_presets (user_id, role, name) VALUES (?,?,?)')
                    ->execute([$userId, $role, $n]);
            }
            $notice = 'Eintrag gespeichert.';
        }
    }
    if ($action === 'crew_del') {
        db()->prepare('DELETE FROM crew_presets WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Eintrag gelöscht.';
    }
    if ($action === 'res_save') {
        $n = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        $wid = (int)($_POST['id'] ?? 0);
        if ($n !== '') {
            if ($wid > 0) {
                db()->prepare('UPDATE resources SET name = ? WHERE id = ? AND user_id = ?')
                    ->execute([$n, $wid, $userId]);
            } else {
                db()->prepare('INSERT IGNORE INTO resources (user_id, name) VALUES (?,?)')
                    ->execute([$userId, $n]);
            }
            $notice = 'Rettungsmittel gespeichert.';
        }
    }
    if ($action === 'res_del') {
        // Bereits dokumentierte Einsaetze behalten ihren Eintrag: Die
        // Zuordnung steht als eigener Datensatz und haengt nicht an dieser Liste.
        db()->prepare('DELETE FROM resources WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Rettungsmittel geloescht.';
    }
    if ($action === 'bw_save') {
        $n = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        $wid = (int)($_POST['id'] ?? 0);
        if ($n !== '') {
            if ($wid > 0) {
                db()->prepare('UPDATE bw_units SET name = ? WHERE id = ? AND user_id = ?')
                    ->execute([$n, $wid, $userId]);
            } else {
                db()->prepare('INSERT IGNORE INTO bw_units (user_id, name) VALUES (?,?)')
                    ->execute([$userId, $n]);
            }
            $notice = 'Bereitschaft gespeichert.';
        }
    }
    if ($action === 'bw_del') {
        db()->prepare('DELETE FROM bw_units WHERE id = ? AND user_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $userId]);
        $notice = 'Bereitschaft gelöscht.';
    }

    // Nach dem Speichern zurueck zum passenden Abschnitt umleiten. Das oeffnet
    // ihn dank des Ankers automatisch wieder und verhindert nebenbei das
    // erneute Absenden beim Neuladen der Seite.
    $abschnitt = [
        'base_save' => 'standorte',   'base_del' => 'standorte',
        'ac_save'   => 'hubschrauber','ac_del'   => 'hubschrauber',
        'crew_save' => 'besatzung',   'crew_del' => 'besatzung',
        'res_save'  => 'rettungsmittel', 'res_del' => 'rettungsmittel',
        'bw_save'   => 'bergwacht',   'bw_del'   => 'bergwacht',
    ][$action] ?? null;
    if ($abschnitt !== null && $notice !== null) {
        $_SESSION['flash_notice'] = $notice;
        header('Location: einstellungen.php?t=stammdaten#' . $abschnitt);
        exit;
    }
}

// Meldung aus der Umleitung uebernehmen
if (!empty($_SESSION['flash_notice'])) {
    $notice = $_SESSION['flash_notice'];
    unset($_SESSION['flash_notice']);
}

$ROLE_LABELS = ['p1' => 'Pilot 1', 'p2' => 'Pilot 2', 'hems' => 'HEMS',
                'fr' => 'Flugretter', 'other' => 'Sonstige'];

$devices = []; $editDev = null;
if ($tab === 'geraete') {
    $st = db()->prepare('SELECT id, device_id, label, active, last_seen FROM devices
                         WHERE user_id = ? AND device_id NOT LIKE \'manual-%\' ORDER BY created_at');
    $st->execute([$userId]);
    $devices = $st->fetchAll();
    foreach ($devices as $d) {
        if ((int)$d['id'] === (int)($_GET['ed'] ?? 0)) { $editDev = $d; }
    }
}
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Einstellungen — Einsatzdoku</title>
<link rel="stylesheet" href="<?= asset('assets/style.css') ?>">
<link rel="icon" type="image/png" href="assets/favicon.png"></head>
<body>
<?php ui_topbar('einstellungen'); ?>

<div class="layout">
  <?php ui_settings_sidebar($tab); ?>

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
    <form method="post" class="settings-form" id="pwform">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <input type="hidden" name="old_token" id="pw_oldtok">
      <input type="hidden" name="new_token" id="pw_newtok">
      <input type="hidden" name="new_salt" id="pw_newsalt">
      <input type="hidden" name="wrap_pw" id="pw_wrap">
      <label>Aktuelles Passwort <input type="password" name="old" id="pw_old" required autocomplete="current-password"></label>
      <label>Neues Passwort (mind. 10 Zeichen) <input type="password" name="new1" id="pw_new1" required minlength="10" autocomplete="new-password"></label>
      <label>Neues Passwort wiederholen <input type="password" name="new2" id="pw_new2" required autocomplete="new-password"></label>
      <button class="btn-primary">Passwort ändern</button>
      <span class="muted" id="pwstate"></span>
    </form>
    <script src="<?= asset('assets/crypto.js') ?>"></script>
    <script>
    const KDF_SALT = <?= json_encode($kdfSalt) ?>;
    const WRAP_PW = <?= json_encode($patWrapPw) ?>;
    document.getElementById('pwform').addEventListener('submit', async ev => {
      const f = ev.target;
      if (f.dataset.ready === '1') return;
      ev.preventDefault();
      const st = document.getElementById('pwstate');
      const oldPw = f.elements['old'].value, n1 = f.elements['new1'].value;
      if (n1 !== f.elements['new2'].value) { st.textContent = 'Neue Passwörter ungleich.'; return; }
      st.textContent = 'Schlüssel werden neu abgeleitet…';
      try {
        let oldDataKey = null;
        // Aktuelles Passwort pruefen: der Server bekommt nur das Token
        const ok = await EdCrypto.deriveKeys(oldPw, KDF_SALT);
        document.getElementById('pw_oldtok').value = ok.authToken;
        oldDataKey = ok.dataKeyHex;
        const salt = EdCrypto.randomHex(16);
        const nk = await EdCrypto.deriveKeys(n1, salt);
        document.getElementById('pw_newtok').value = nk.authToken;
        document.getElementById('pw_newsalt').value = salt;
        // Inhaltsschluessel des Moduls in die neue Passwort-Huelle umpacken
        if (WRAP_PW && oldDataKey) {
          try {
            const ck = await EdCrypto.decrypt(oldDataKey, WRAP_PW);
            document.getElementById('pw_wrap').value = await EdCrypto.encrypt(nk.dataKeyHex, ck);
          } catch (e) { /* Wrap passt nicht (Reset-Fall) — unveraendert lassen */ }
        }
        EdCrypto.setDataKey(nk.dataKeyHex);
        f.dataset.ready = '1';
        f.submit();
      } catch (e) { st.textContent = 'Fehler bei der Schlüsselableitung.'; }
    });
    </script>

  <?php elseif ($tab === 'stammdaten'): ?>
    <?php
      $bases = db()->prepare('SELECT id, name, is_default FROM bases WHERE user_id = ? ORDER BY name');
      $bases->execute([$userId]); $bases = $bases->fetchAll();
      $acs = db()->prepare('SELECT * FROM aircraft WHERE user_id = ? ORDER BY registration');
      $acs->execute([$userId]); $acs = $acs->fetchAll();
      $crew = db()->prepare('SELECT id, role, name FROM crew_presets WHERE user_id = ? ORDER BY name');
      $crew->execute([$userId]); $crew = $crew->fetchAll();
      $bw = db()->prepare('SELECT id, name FROM bw_units WHERE user_id = ? ORDER BY name');
      $bw->execute([$userId]); $bw = $bw->fetchAll();
      $res = db()->prepare('SELECT id, name FROM resources WHERE user_id = ? ORDER BY name');
      $res->execute([$userId]); $res = $res->fetchAll();
      $pick = function (array $rows, string $param) {
          foreach ($rows as $r) { if ((int)$r['id'] === (int)($_GET[$param] ?? 0)) { return $r; } }
          return null;
      };
      $editAc = $pick($acs, 'ac');    $editBase = $pick($bases, 'eb');
      $editBw = $pick($bw, 'ew');
      $editRes = $pick($res, 'er');
      $editCrew = null;
      foreach ($crew as $c) { if ((int)$c['id'] === (int)($_GET['ec'] ?? 0)) { $editCrew = $c; } }
    ?>
    <h1>Standortdaten</h1>
    <p class="muted">Vorbelegungen für die Flugtag- und Einsatzdokumentation, alphabetisch
       sortiert. Löschen entfernt nur den Listeneintrag — gespeicherte Flugtage bleiben
       unverändert. ★ markiert die Vorbelegung neuer Flugtage.</p>

      <details class="stammblock" id="standorte">
    <summary>Standorte</summary>

    <table class="data">
      <thead><tr><th>Name</th><th>Standard</th><th class="th-act">Aktionen</th></tr></thead>
      <tbody>
      <?php if (!$bases): ?><tr><td colspan="3" class="muted">Noch keine Standorte.</td></tr><?php endif; ?>
      <?php foreach ($bases as $b): ?>
        <tr>
          <td><?= e($b['name']) ?></td>
          <td class="checkcol"><?= (int)$b['is_default'] ? '★' : '' ?></td>
          <td><div class="rowactions">
            <?php if (!(int)$b['is_default']): ?>
              <form method="post" action="einstellungen.php?t=stammdaten#standorte">
                <?= csrf_field() ?><input type="hidden" name="action" value="base_default">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="btn-plain">Als Standard</button>
              </form>
            <?php endif; ?>
            <a class="btn-yellow" href="einstellungen.php?t=stammdaten&amp;eb=<?= (int)$b['id'] ?>#standorte">Bearbeiten</a>
            <form method="post" action="einstellungen.php?t=stammdaten#standorte"
                  data-confirm="Standort löschen?">
              <?= csrf_field() ?><input type="hidden" name="action" value="base_del">
              <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
              <button class="btn-red">Löschen</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" action="einstellungen.php?t=stammdaten#standorte" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="base_save">
      <input type="hidden" name="id" value="<?= $editBase ? (int)$editBase['id'] : 0 ?>">
      <input type="text" name="name" maxlength="120" required
             placeholder="z. B. Kempten" value="<?= e($editBase['name'] ?? '') ?>">
      <button class="btn-primary"><?= $editBase ? 'Änderung speichern' : 'Standort hinzufügen' ?></button>
      <?php if ($editBase): ?><a class="btn-red" href="einstellungen.php?t=stammdaten">Abbrechen</a><?php endif; ?>
    </form>

    <hr class="sep">
      </details>

  <details class="stammblock" id="hubschrauber">
    <summary>Hubschrauber</summary>

    <p class="muted">Die angehakten Rollen bestimmen, welche Besatzungsfelder am Flugtag erscheinen.</p>
    <table class="data">
      <thead><tr><th>Kennung</th><th>Rollen</th><th>Standard</th><th class="th-act">Aktionen</th></tr></thead>
      <tbody>
      <?php if (!$acs): ?><tr><td colspan="4" class="muted">Noch keine Hubschrauber.</td></tr><?php endif; ?>
      <?php foreach ($acs as $a): ?>
        <tr>
          <td><?= e($a['registration']) ?></td>
          <td><?php $r = [];
            foreach ($ROLE_LABELS as $k => $lbl) { if ((int)$a[$k]) { $r[] = $lbl; } }
            echo e($r ? implode(' · ', $r) : '–'); ?></td>
          <td class="checkcol"><?= (int)$a['is_default'] ? '★' : '' ?></td>
          <td><div class="rowactions">
            <?php if (!(int)$a['is_default']): ?>
              <form method="post" action="einstellungen.php?t=stammdaten#hubschrauber">
                <?= csrf_field() ?><input type="hidden" name="action" value="ac_default">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn-plain">Als Standard</button>
              </form>
            <?php endif; ?>
            <a class="btn-yellow" href="einstellungen.php?t=stammdaten&amp;ac=<?= (int)$a['id'] ?>#hubschrauber">Bearbeiten</a>
            <form method="post" action="einstellungen.php?t=stammdaten#hubschrauber"
                  data-confirm="Hubschrauber löschen?">
              <?= csrf_field() ?><input type="hidden" name="action" value="ac_del">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button class="btn-red">Löschen</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" action="einstellungen.php?t=stammdaten#hubschrauber" class="ac-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="ac_save">
      <input type="hidden" name="id" value="<?= $editAc ? (int)$editAc['id'] : 0 ?>">
      <div class="inline-form">
        <input type="text" name="registration" maxlength="64" required
               placeholder="Kennung, z. B. Christoph 17"
               value="<?= e($editAc['registration'] ?? '') ?>">
        <button class="btn-primary"><?= $editAc ? 'Änderungen speichern' : 'Hubschrauber anlegen' ?></button>
        <?php if ($editAc): ?><a class="btn-red" href="einstellungen.php?t=stammdaten">Abbrechen</a><?php endif; ?>
      </div>
      <div class="rolechecks">
        <span class="rolechecks-hint">Rollen auf dem Hubschrauber:</span>
        <?php foreach ($ROLE_LABELS as $k => $lbl): ?>
          <label><input type="checkbox" name="<?= $k ?>"
            <?= ($editAc && (int)$editAc[$k]) ? 'checked' : '' ?>> <?= e($lbl) ?></label>
        <?php endforeach; ?>
      </div>
    </form>

    <hr class="sep">
      </details>

  <details class="stammblock" id="besatzung">
    <summary>Besatzung — Vorbelegungen</summary>

    <p class="muted">Diese Namen erscheinen am Flugtag als Auswahl im jeweiligen Rollen-Dropdown.</p>
    <?php foreach ($ROLE_LABELS as $rk => $lbl): ?>
      <h3 class="rolehead"><?= e($lbl) ?></h3>
      <table class="data">
        <tbody>
        <?php $any = false; foreach ($crew as $c): if ($c['role'] !== $rk) continue; $any = true; ?>
          <tr>
            <td><?= e($c['name']) ?></td>
            <td class="th-act"><div class="rowactions">
              <a class="btn-yellow" href="einstellungen.php?t=stammdaten&amp;ec=<?= (int)$c['id'] ?>#besatzung">Bearbeiten</a>
              <form method="post" action="einstellungen.php?t=stammdaten#besatzung"
                    data-confirm="Eintrag löschen?">
                <?= csrf_field() ?><input type="hidden" name="action" value="crew_del">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn-red">Löschen</button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$any): ?><tr><td class="muted">Noch keine Einträge.</td><td></td></tr><?php endif; ?>
        </tbody>
      </table>
      <form method="post" action="einstellungen.php?t=stammdaten#besatzung" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="crew_save">
        <input type="hidden" name="role" value="<?= $rk ?>">
        <input type="hidden" name="id"
               value="<?= ($editCrew && $editCrew['role'] === $rk) ? (int)$editCrew['id'] : 0 ?>">
        <input type="text" name="name" placeholder="Name" maxlength="120" required
               value="<?= ($editCrew && $editCrew['role'] === $rk) ? e($editCrew['name']) : '' ?>">
        <button class="btn-primary"><?= ($editCrew && $editCrew['role'] === $rk) ? 'Änderung speichern' : 'Hinzufügen' ?></button>
        <?php if ($editCrew && $editCrew['role'] === $rk): ?>
          <a class="btn-red" href="einstellungen.php?t=stammdaten">Abbrechen</a><?php endif; ?>
      </form>
    <?php endforeach; ?>

    <hr class="sep">
      </details>

  <details class="stammblock" id="rettungsmittel">
    <summary>Andere Rettungsmittel</summary>

    <p class="muted">Vorbelegung f&uuml;r das Feld &bdquo;Weitere Rettungsmittel&ldquo; im Einsatz.
       Dort gen&uuml;gen zwei Zeichen, dann erscheinen die passenden Eintr&auml;ge zum Anklicken.</p>
    <table class="data">
      <tbody>
      <?php if (!$res): ?><tr><td class="muted">Noch keine Rettungsmittel.</td><td></td></tr><?php endif; ?>
      <?php foreach ($res as $r): ?>
        <tr>
          <td><?= e($r['name']) ?></td>
          <td class="th-act"><div class="rowactions">
            <a class="btn-yellow" href="einstellungen.php?t=stammdaten&amp;er=<?= (int)$r['id'] ?>#rettungsmittel">Bearbeiten</a>
            <form method="post" action="einstellungen.php?t=stammdaten#rettungsmittel"
                  data-confirm="Rettungsmittel aus der Vorbelegung l&ouml;schen? Bereits dokumentierte Eins&auml;tze behalten ihren Eintrag.">
              <?= csrf_field() ?><input type="hidden" name="action" value="res_del">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn-red">L&ouml;schen</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" action="einstellungen.php?t=stammdaten#rettungsmittel" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="res_save">
      <input type="hidden" name="id" value="<?= $editRes ? (int)$editRes['id'] : 0 ?>">
      <input type="text" name="name" maxlength="120" required
             placeholder="z. B. RTW Kempten 21/83" value="<?= e($editRes['name'] ?? '') ?>">
      <button class="btn-primary"><?= $editRes ? '&Auml;nderung speichern' : 'Rettungsmittel hinzuf&uuml;gen' ?></button>
      <?php if ($editRes): ?><a class="btn-red" href="einstellungen.php?t=stammdaten">Abbrechen</a><?php endif; ?>
    </form>

      </details>

  <details class="stammblock" id="bergwacht">
    <summary>Bergwacht-Bereitschaften</summary>

    <table class="data">
      <tbody>
      <?php if (!$bw): ?><tr><td class="muted">Noch keine Bereitschaften.</td><td></td></tr><?php endif; ?>
      <?php foreach ($bw as $b): ?>
        <tr>
          <td><?= e($b['name']) ?></td>
          <td class="th-act"><div class="rowactions">
            <a class="btn-yellow" href="einstellungen.php?t=stammdaten&amp;ew=<?= (int)$b['id'] ?>#bergwacht">Bearbeiten</a>
            <form method="post" action="einstellungen.php?t=stammdaten#bergwacht"
                  data-confirm="Bereitschaft löschen?">
              <?= csrf_field() ?><input type="hidden" name="action" value="bw_del">
              <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
              <button class="btn-red">Löschen</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" action="einstellungen.php?t=stammdaten#bergwacht" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="bw_save">
      <input type="hidden" name="id" value="<?= $editBw ? (int)$editBw['id'] : 0 ?>">
      <input type="text" name="name" maxlength="120" required
             placeholder="z. B. Bereitschaft Oberstdorf" value="<?= e($editBw['name'] ?? '') ?>">
      <button class="btn-primary"><?= $editBw ? 'Änderung speichern' : 'Bereitschaft hinzufügen' ?></button>
      <?php if ($editBw): ?><a class="btn-red" href="einstellungen.php?t=stammdaten">Abbrechen</a><?php endif; ?>
    </form>
  </details>


  <?php elseif ($tab === 'backup'): ?>
    <h1>Backup</h1>
    <p class="muted">Sichert <strong>alle</strong> deine Daten (Einsätze mit Phasen,
       Reanimationen und Tracks, Ruhesegmente, Flugtage, Stammdaten und die
       geschützten Angaben) in eine einzelne Datei (<code>.edbak</code>), verschlüsselt
       mit einem Passwort deiner Wahl (AES-256-GCM). Ver- und Entschlüsselung passieren
       vollständig <strong>in deinem Browser</strong> — der Server sieht die Inhalte nie.
       Dadurch lässt sich ein Backup auch in ein <strong>anderes Konto</strong>
       einspielen. Format-Beschreibung: <code>docs/Backup-Format.md</code>.</p>

    <div id="lockwarn" class="alert" hidden>Die geschützten Angaben lassen sich gerade
      nicht entschlüsseln — bitte ab- und neu anmelden bzw.
      <a href="einrichtung.php">mit Wiederherstellungsschlüssel entsperren</a>.</div>

    <h2>Exportieren</h2>
    <div class="settings-form">
      <label>Backup-Passwort (mind. 8 Zeichen)
        <input type="password" id="bpw1" minlength="8" autocomplete="new-password"></label>
      <label>Passwort wiederholen
        <input type="password" id="bpw2" autocomplete="new-password"></label>
      <p class="muted">Ohne dieses Passwort ist die Datei wertlos — es wird nirgends
         gespeichert. Es darf, muss aber nicht dein Login-Passwort sein.</p>
      <button class="btn-primary" id="expbtn">Backup erstellen</button>
      <p class="muted" id="expstate" style="min-height:1.3em"></p>
    </div>

    <h2>Importieren</h2>
    <p class="muted">Spielt ein Backup in <strong>dieses</strong> Konto zurück. Bereits
       vorhandene Einsätze, Tage und Stammdaten bleiben unangetastet (Erkennung über
       interne Referenzen) — der Import ergänzt nur Fehlendes und ist gefahrlos
       wiederholbar.</p>
    <div class="settings-form" id="impform">
      <label>Backup-Datei (.edbak)
        <input type="file" name="bfile" id="bfile" accept=".edbak" required></label>
      <label>Backup-Passwort
        <input type="password" id="ipw" autocomplete="off"></label>
      <button class="btn-primary" id="impbtn">Backup importieren</button>
      <p class="muted" id="impstate" style="min-height:1.3em"></p>
    </div>

    <script src="<?= asset('assets/crypto.js') ?>"></script>
    <script>
    const PAT_WRAP = <?= json_encode($patWrapPw) ?>;
    const CSRF = <?= json_encode($_SESSION['csrf'] ?? '') ?>;
    const expState = document.getElementById('expstate');
    const impState = document.getElementById('impstate');

    async function ck() {
      const k = await EdCrypto.getContentKey(PAT_WRAP);
      document.getElementById('lockwarn').hidden = !!k;
      return k;
    }
    ck();

    // ---- Export: Daten holen, entschlüsseln, versiegeln, herunterladen ----
    document.getElementById('expbtn').addEventListener('click', async () => {
      const pw = document.getElementById('bpw1').value;
      if (pw.length < 8 || pw !== document.getElementById('bpw2').value) {
        expState.textContent = 'Passwörter ungleich oder kürzer als 8 Zeichen.';
        return;
      }
      const key = await ck();
      if (!key) { expState.textContent = 'Entschlüsselung gesperrt — siehe Hinweis oben.'; return; }
      try {
        expState.textContent = 'Daten werden geladen…';
        const data = await (await fetch('api/backup_data.php')).json();

        expState.textContent = 'Geschützte Angaben werden entschlüsselt…';
        let n = 0;
        for (const m of (data.missions || [])) {
          if (!m.pat_blob) continue;
          try {
            m.pat = JSON.parse(await EdCrypto.decrypt(key, m.pat_blob));
            n++;
          } catch (e) { m.pat_unreadable = true; }
          delete m.pat_blob;
        }

        expState.textContent = 'Datei wird verschlüsselt…';
        const bytes = await EdCrypto.sealBackup(pw, JSON.stringify(data));
        const url = URL.createObjectURL(new Blob([bytes], { type: 'application/octet-stream' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = 'einsatzdoku-backup-' + new Date().toISOString().slice(0, 10) + '.edbak';
        a.click();
        URL.revokeObjectURL(url);
        expState.textContent = `Fertig: ${(data.missions || []).length} Einsätze `
          + `(davon ${n} mit geschützten Angaben), `
          + `${(data.rest_segments || []).length} Ruhesegmente, `
          + `${(data.days || []).length} Flugtage.`;
      } catch (e) {
        expState.textContent = 'Export fehlgeschlagen: ' + e.message;
      }
    });

    // ---- Import: läuft vollständig im Browser ----
    document.getElementById('impbtn').addEventListener('click', async () => {
      const f = document.getElementById('bfile').files[0];
      if (!f) { impState.textContent = 'Bitte eine Backup-Datei auswählen.'; return; }
      const pw = document.getElementById('ipw').value;
      if (!pw) { impState.textContent = 'Bitte das Backup-Passwort eingeben.'; return; }

      const key = await ck();
      if (!key) { impState.textContent = 'Entschlüsselung gesperrt — siehe Hinweis oben.'; return; }
      try {
        impState.textContent = 'Datei wird gelesen…';
        const bytes = new Uint8Array(await f.arrayBuffer());
        if (!EdCrypto.isBackupFile(bytes)) {
          impState.textContent = 'Das ist keine Backup-Datei dieses Programms.';
          return;
        }
        impState.textContent = 'Datei wird geöffnet…';
        const data = await EdCrypto.openBackup(pw, bytes);

        impState.textContent = 'Angaben werden für dieses Konto verschlüsselt…';
        for (const m of (data.missions || [])) {
          if (m.pat && Object.keys(m.pat).length) {
            m.pat_blob = await EdCrypto.encrypt(key, JSON.stringify(m.pat));
          }
          delete m.pat;
        }

        impState.textContent = 'Daten werden übertragen…';
        const res = await fetch('api/backup_restore.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
          body: JSON.stringify(data)
        });
        const out = await res.json();
        if (!out.ok) { throw new Error(out.meldung || out.hinweis || out.error || 'unbekannt'); }
        const s = out.stats;
        impState.textContent = `Import fertig: ${s.missions} Einsätze übernommen `
          + `(${s.missions_skipped} bereits vorhanden), ${s.rests} Ruhesegmente, `
          + `${s.days} Flugtage, ${s.stammdaten} Standortdaten-Einträge.`;
      } catch (e) {
        impState.textContent = 'Import fehlgeschlagen: ' + e.message;
      }
    });
    
/* Standortdaten: Abschnitt oeffnen, wenn er angesprungen oder bearbeitet wird */
(function(){
  function oeffne(id){
    const d = document.getElementById(id);
    if (d && d.tagName === 'DETAILS') {
      d.open = true;
      d.scrollIntoView({ block: 'start' });
    }
  }
  if (location.hash.length > 1) { oeffne(location.hash.slice(1)); }
  window.addEventListener('hashchange', () => {
    if (location.hash.length > 1) { oeffne(location.hash.slice(1)); }
  });
})();
</script>

  <?php else: ?>
    <h1>Geräte</h1>
    <p class="muted">Jedes Gerät (Uhr) bekommt eigene Zugangsdaten für den Upload.
       Deaktivieren sperrt den Schlüssel — bereits hochgeladene Daten bleiben erhalten.</p>

    <h2>Uhr koppeln (empfohlen)</h2>
    <p class="muted">Erzeuge einen Code und gib ihn auf der Uhr ein
       (Sync-Seite der Uhr → <strong>START gedrückt halten</strong> → Code eintippen;
       die Sync-Seite erreichst du vom Startbildschirm mit DOWN).
       Die Uhr holt sich ihre Zugangsdaten dann selbst — kein Abtippen langer
       Schlüssel. Der Code ist <strong>60 Minuten</strong> gültig und
       <strong>einmal</strong> verwendbar.</p>
    <?php if ($pairCode): ?>
      <div class="keybox paircode">
        <strong>Kopplungscode</strong>
        <p class="codebig"><?= e($pairCode) ?></p>
        <p class="muted">Gültig bis <?= e(fmt_local(gmdate('Y-m-d H:i:s', time() + 3600), 'H:i')) ?> Uhr.
           Das Gerät erscheint nach der Kopplung unten in der Liste.</p>
      </div>
    <?php endif; ?>
    <form method="post" action="einstellungen.php?t=geraete">
      <?= csrf_field() ?><input type="hidden" name="action" value="pair_code">
      <button class="btn-primary" style="width:auto">Kopplungscode erzeugen</button>
    </form>

    <?php if ($editDev): ?>
      <form method="post" action="einstellungen.php?t=geraete" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="rename">
        <input type="hidden" name="id" value="<?= (int)$editDev['id'] ?>">
        <input type="text" name="label" maxlength="120" placeholder="Bezeichnung"
               value="<?= e($editDev['label'] ?? '') ?>">
        <button class="btn-primary">Bezeichnung speichern</button>
        <a class="btn-red" href="einstellungen.php?t=geraete">Abbrechen</a>
      </form>
    <?php endif; ?>

    <h2>Manuell anlegen (Alternative)</h2>

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
            <a class="btn-yellow" href="einstellungen.php?t=geraete&amp;ed=<?= (int)$d['id'] ?>">Bearbeiten</a>
            <form method="post" action="einstellungen.php?t=geraete">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn-danger"><?= (int)$d['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
            </form>
            <form method="post" action="einstellungen.php?t=geraete"
                  data-confirm="Gerät wirklich löschen? Bereits hochgeladene Daten bleiben erhalten.">
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
