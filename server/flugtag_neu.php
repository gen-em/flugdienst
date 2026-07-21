<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/ui.php';   // auth_guard.php laedt sie bereits

/**
 * Flugtag von Hand anlegen — fuer Tage, an denen die Uhr nicht lief.
 * Legt lediglich die Traegerzeile in `days` an; Maschine, Besatzung und
 * nachgetragene Einsaetze werden anschliessend in der Uebersicht erfasst.
 */

$fehler = null;
$tag    = (string)($_POST['day'] ?? date('Y-m-d'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag) || !strtotime($tag)) {
        $fehler = 'Bitte ein gültiges Datum wählen.';
    } else {
        // Liegt der Tag schon vor (auch im Papierkorb)? Dann nicht doppelt anlegen.
        $vorhanden = db()->prepare('SELECT deleted_at FROM days WHERE user_id = ? AND day = ?');
        $vorhanden->execute([$userId, $tag]);
        $z = $vorhanden->fetch();
        if ($z && $z['deleted_at'] !== null) {
            $fehler = 'Dieser Flugtag liegt im Papierkorb. Dort lässt er sich wiederherstellen.';
        } else {
            db()->prepare('INSERT IGNORE INTO days (user_id, day) VALUES (?, ?)')
                ->execute([$userId, $tag]);
            header('Location: index.php?day=' . urlencode($tag));
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Flugtag anlegen · Einsatzdoku</title>
  <link rel="stylesheet" href="assets/style.css">
  <link rel="icon" type="image/png" href="assets/favicon.png">
</head>
<body>
<?php ui_topbar('uebersicht'); ?>
<div class="layout">
  <?php ui_days_sidebar(null); ?>
  <main class="page">
    <h1>Flugtag anlegen</h1>
    <?php if ($fehler): ?><p class="alert"><?= e($fehler) ?></p><?php endif; ?>

    <div class="card">
      <p class="muted">Für Tage, an denen die Uhr nicht mitgelaufen ist. Der Tag
         erscheint danach in der Liste links; Maschine, Besatzung und Einsätze
         trägst du dort nach.</p>
      <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <label>Datum
          <input type="date" name="day" required value="<?= e($tag) ?>"
                 max="<?= e(date('Y-m-d')) ?>"></label>
        <button class="btn-primary">Flugtag anlegen</button>
        <a class="btn-plain" href="index.php">Abbrechen</a>
      </form>
    </div>
  </main>
</div>
<?php ui_footer(); ?>
</body>
</html>
