<?php
declare(strict_types=1);
/**
 * Einrichtungs-Assistent.
 * - Läuft nur, solange weder config.php noch install.lock existieren.
 * - Testet die DB-Verbindung, spielt schema.sql ein, legt den Admin an,
 *   schreibt config.php und setzt danach install.lock (Wiederausführungssperre).
 * Diese Datei benötigt selbst KEINE config.php.
 */

$configPath = __DIR__ . '/config.php';
$lockPath   = __DIR__ . '/install.lock';
$schemaPath = __DIR__ . '/schema.sql';

session_start();
if (empty($_SESSION['inst_csrf'])) { $_SESSION['inst_csrf'] = bin2hex(random_bytes(32)); }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* ---- Wiederausführungssperre ------------------------------------------- */
if (file_exists($configPath) || file_exists($lockPath)) {
    http_response_code(403);
    render_page('Bereits eingerichtet',
        '<p class="alert alert-info">Die Anwendung ist bereits eingerichtet. '
        . 'Der Installer ist gesperrt.</p>'
        . '<p><a class="btn-link" href="index.php">Zur Anwendung</a></p>'
        . '<p class="muted small">Aus Sicherheitsgründen sollte <code>install.php</code> '
        . 'nach erfolgreicher Einrichtung vom Server gelöscht werden.</p>');
    exit;
}

$errors = [];
$done = false;

/* ---- Formular verarbeiten ---------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['inst_csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $errors[] = 'Ungültiges Formular-Token. Bitte Seite neu laden.';
    }

    $in = fn(string $k): string => trim((string)($_POST[$k] ?? ''));

    $dbHost = $in('db_host'); $dbName = $in('db_name');
    $dbUser = $in('db_user'); $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminEmail = $in('admin_email'); $adminPw = (string)($_POST['admin_pass'] ?? '');
    $baseUrl = rtrim($in('base_url'), '/');
    $timezone = $in('timezone') ?: 'Europe/Berlin';
    $logoPath = $in('logo_path') ?: 'assets/logo.svg';
    $dropExisting = !empty($_POST['drop_existing']);

    $smtp = [
        'host' => $in('smtp_host'), 'port' => (int)($in('smtp_port') ?: 465),
        'user' => $in('smtp_user'), 'pass' => (string)($_POST['smtp_pass'] ?? ''),
        'from' => $in('smtp_from'), 'from_name' => $in('smtp_from_name') ?: 'Einsatzdoku',
    ];

    // Validierung
    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $errors[] = 'Datenbank-Host, -Name und -Benutzer sind erforderlich.';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gültige Admin-E-Mail angeben.';
    }
    if (strlen($adminPw) < 10) {
        $errors[] = 'Das Admin-Passwort muss mindestens 10 Zeichen haben.';
    }
    if (!preg_match('#^https?://#', $baseUrl)) {
        $errors[] = 'Die Basis-URL muss mit http:// oder https:// beginnen.';
    }
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        $errors[] = 'Unbekannte Zeitzone.';
    }
    if (!is_writable(__DIR__)) {
        $errors[] = 'Das Verzeichnis ist nicht beschreibbar — config.php kann nicht '
                  . 'angelegt werden. Schreibrechte setzen und erneut versuchen.';
    }
    if (!is_readable($schemaPath)) {
        $errors[] = 'schema.sql wurde nicht gefunden (muss neben install.php liegen).';
    }

    // DB-Verbindung testen
    $pdo = null;
    if (!$errors) {
        $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (Throwable $ex) {
            $errors[] = 'Datenbank-Verbindung fehlgeschlagen: ' . h($ex->getMessage());
        }
    }

    // Schema einspielen + Admin anlegen
    if (!$errors && $pdo !== null) {
        try {
            if ($dropExisting) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach (['track_points','resus_events','resus_sessions','mission_phases',
                          'rest_segments','missions','devices','password_resets','users'] as $t) {
                    $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
            run_sql_file($pdo, $schemaPath);

            $pdo->prepare('INSERT INTO users (email, role, password_hash) VALUES (?, "admin", ?)')
                ->execute([$adminEmail, password_hash($adminPw, PASSWORD_DEFAULT)]);
        } catch (Throwable $ex) {
            $errors[] = 'Beim Anlegen der Tabellen/des Admins ist ein Fehler aufgetreten: '
                      . h($ex->getMessage())
                      . ' — Tipp: eine leere Datenbank verwenden oder oben '
                      . '„vorhandene Tabellen löschen" anhaken.';
        }
    }

    // config.php schreiben + Sperre setzen
    if (!$errors) {
        $config = [
            'db'  => ['dsn' => "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
                      'user' => $dbUser, 'pass' => $dbPass],
            'app' => ['base_url' => $baseUrl, 'timezone' => $timezone,
                      'logo_path' => $logoPath, 'max_body_bytes' => 524288],
            'smtp' => $smtp,
        ];
        $php = "<?php\n// Automatisch erzeugt vom Installer am " . date('c') . "\n"
             . "// Diese Datei enthält Zugangsdaten — niemals ins Git-Repo committen!\n"
             . 'return ' . var_export($config, true) . ";\n";

        if (file_put_contents($configPath, $php, LOCK_EX) === false) {
            $errors[] = 'config.php konnte nicht geschrieben werden.';
        } else {
            @chmod($configPath, 0640);
            file_put_contents($lockPath, 'installed ' . date('c') . "\n");
            $done = true;
        }
    }
}

/* ---- SQL-Datei ausführen (statementweise) ------------------------------ */
function run_sql_file(PDO $pdo, string $path): void {
    $sql = file_get_contents($path);
    // Kommentarzeilen entfernen, dann an ';' am Zeilenende trennen.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*[\r\n]+/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') { $pdo->exec($stmt); }
    }
}

/* ---- Ausgabe ------------------------------------------------------------ */
if ($done) {
    render_page('Einrichtung abgeschlossen',
        '<p class="alert alert-ok">Einrichtung erfolgreich. Die Konfiguration wurde '
        . 'gespeichert und der Installer ist jetzt gesperrt.</p>'
        . '<p>Du kannst dich mit der Admin-E-Mail und dem gewählten Passwort anmelden.</p>'
        . '<p><a class="btn-link" href="index.php">Zur Anwendung</a></p>'
        . '<p class="muted small">Empfehlung: <code>install.php</code> jetzt vom Server '
        . 'löschen. Solange <code>install.lock</code> existiert, ist eine erneute '
        . 'Ausführung ohnehin blockiert.</p>');
    exit;
}

render_form($_POST ?? [], $errors);


/* ---- Templates ---------------------------------------------------------- */
function render_form(array $v, array $errors): void {
    $val = fn(string $k, string $d = ''): string => h((string)($v[$k] ?? $d));
    $guessUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'einsatz.example.de');
    ob_start(); ?>
    <h1>Einsatzdoku einrichten</h1>
    <p class="muted">Diese Angaben werden in <code>config.php</code> gespeichert und die
       Datenbank wird angelegt. Der Installer läuft nur dieses eine Mal.</p>

    <?php foreach ($errors as $e): ?><p class="alert"><?= $e ?></p><?php endforeach; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['inst_csrf']) ?>">

      <fieldset>
        <legend>Datenbank</legend>
        <label>Host <input name="db_host" value="<?= $val('db_host', 'localhost') ?>" required></label>
        <label>Datenbank-Name <input name="db_name" value="<?= $val('db_name') ?>" required></label>
        <label>Benutzer <input name="db_user" value="<?= $val('db_user') ?>" required></label>
        <label>Passwort <input type="password" name="db_pass"></label>
        <label class="check"><input type="checkbox" name="drop_existing" value="1">
          Vorhandene Tabellen vorher löschen (Achtung: löscht alle Daten dieser DB)</label>
      </fieldset>

      <fieldset>
        <legend>Administrator-Zugang</legend>
        <label>E-Mail (= Login) <input type="email" name="admin_email" value="<?= $val('admin_email') ?>" required></label>
        <label>Passwort (min. 10 Zeichen) <input type="password" name="admin_pass" minlength="10" required></label>
      </fieldset>

      <fieldset>
        <legend>Anwendung</legend>
        <label>Basis-URL (ohne Slash am Ende)
          <input name="base_url" value="<?= $val('base_url', $guessUrl) ?>" required></label>
        <label>Zeitzone (Anzeige)
          <input name="timezone" value="<?= $val('timezone', 'Europe/Berlin') ?>"></label>
        <label>Logo-Pfad
          <input name="logo_path" value="<?= $val('logo_path', 'assets/logo.svg') ?>"></label>
      </fieldset>

      <fieldset>
        <legend>SMTP (für Passwort-Reset-Mails, optional)</legend>
        <p class="muted small">Kann leer bleiben — der Admin-Zugang funktioniert auch ohne.
           Ohne SMTP können NutzerInnen ihr Passwort aber nicht per Mail zurücksetzen.</p>
        <label>Host <input name="smtp_host" value="<?= $val('smtp_host') ?>"></label>
        <label>Port <input name="smtp_port" value="<?= $val('smtp_port', '465') ?>"></label>
        <label>Benutzer <input name="smtp_user" value="<?= $val('smtp_user') ?>"></label>
        <label>Passwort <input type="password" name="smtp_pass"></label>
        <label>Absender-Adresse <input name="smtp_from" value="<?= $val('smtp_from') ?>"></label>
        <label>Absender-Name <input name="smtp_from_name" value="<?= $val('smtp_from_name', 'Einsatzdoku') ?>"></label>
      </fieldset>

      <button type="submit" class="btn-primary">Einrichten</button>
    </form>
    <?php
    render_page('Einrichten', ob_get_clean());
}

function render_page(string $title, string $body): void {
    ?><!doctype html>
<html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> — Einsatzdoku</title>
<style>
  :root{--navy:#1A2E4D;--paper:#F7F8F9;--accent:#FF8F1F;--line:#D5DAE0;--ink:#1B2733;--muted:#66707B}
  *{box-sizing:border-box}
  body{margin:0;background:var(--navy);color:var(--ink);
    font:15px/1.55 system-ui,'Segoe UI',Roboto,sans-serif;display:grid;place-items:start center;min-height:100vh;padding:2rem 1rem}
  main{background:#fff;border-radius:10px;padding:1.6rem 1.8rem;width:min(96vw,560px)}
  h1{font-size:1.5rem;margin:.2rem 0 .6rem;letter-spacing:.02em}
  fieldset{border:1px solid var(--line);border-radius:8px;margin:1rem 0;padding:.8rem 1rem}
  legend{font-weight:600;padding:0 .4rem;color:var(--navy)}
  label{display:block;margin:.55rem 0 .1rem;font-size:.92rem}
  label.check{display:flex;gap:.5rem;align-items:flex-start;font-size:.88rem;color:var(--muted)}
  label.check input{width:auto;margin-top:.2rem}
  input{width:100%;padding:.5rem .6rem;border:1px solid var(--line);border-radius:5px;font:inherit}
  input:focus{outline:2px solid var(--accent);outline-offset:1px}
  .btn-primary{background:var(--accent);color:#fff;font-weight:600;border:0;border-radius:6px;
    padding:.6rem 1rem;width:100%;cursor:pointer;font-size:1rem;margin-top:.4rem}
  .btn-primary:hover{background:#E67C0E}
  .btn-link{display:inline-block;margin-top:.4rem;color:var(--accent);font-weight:600;text-decoration:none}
  .alert{background:#FFF0F0;border:1px solid #E8B4B4;color:#B02525;padding:.55rem .8rem;border-radius:5px;margin:.5rem 0}
  .alert-info{background:#EDF5FF;border-color:#B6D4F2;color:#1B5E9E}
  .alert-ok{background:#EBFBEE;border-color:#A3E5B5;color:#1B7A34}
  .muted{color:var(--muted)} .small{font-size:.85rem}
  code{background:#F1F3F5;padding:.1em .35em;border-radius:3px;font-family:ui-monospace,Consolas,monospace}
</style></head>
<body><main><?= $body ?></main></body></html>
<?php
}
