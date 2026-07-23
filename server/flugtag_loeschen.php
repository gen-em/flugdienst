<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/trash_lib.php';

/** Zwischenseite fuer das Loeschen eines kompletten Flugtags. */

$day = (string)($_POST['day'] ?? $_GET['day'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    http_response_code(400); exit('Ungültiges Datum.');
}
$scope = trash_scope_day($userId, $day);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'ja') {
    csrf_check();
    trash_delete_day($userId, $day);
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/ui.php';   // auth_guard.php laedt sie bereits
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Flugtag löschen · Einsatzdoku</title>
  <link rel="stylesheet" href="<?= asset('assets/style.css') ?>">
  <?= favicon_tags() ?>
</head>
<body>
<?php ui_topbar('uebersicht'); ?>
<div class="layout">
  <?php ui_days_sidebar($day); ?>
  <main class="page">
    <h1>Flugtag <?= e(date('d.m.Y', strtotime($day))) ?> löschen?</h1>

    <div class="card">
      <p class="muted">Es wird <strong>der komplette Tag</strong> gelöscht — nicht nur
         die Angaben zu Maschine und Besatzung:</p>
      <ul>
        <li><?= (int)$scope['einsaetze'] ?> Einsätze mit allen Angaben</li>
        <li><?= (int)$scope['phasen'] ?> Phasen-Zeitstempel,
            <?= (int)$scope['reas'] ?> Reanimations-Protokolle</li>
        <li><?= (int)$scope['segmente'] ?> Ruhesegmente</li>
        <li><?= number_format((int)$scope['punkte'], 0, ',', '.') ?> GPS-Trackpunkte</li>
        <li><?= $scope['meta'] ? 'Flugtag-Angaben (Maschine, Besatzung, Notizen)'
                               : 'keine Flugtag-Angaben hinterlegt' ?></li>
      </ul>
      <p>Der Tag bleibt <strong><?= TRASH_DAYS ?> Tage</strong> im Papierkorb und kehrt
         beim Wiederherstellen <strong>mit allen Einsätzen</strong> zurück.</p>
    </div>

    <form method="post" action="flugtag_loeschen.php" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="day" value="<?= e($day) ?>">
      <input type="hidden" name="confirm" value="ja">
      <button class="btn-red">Ganzen Tag in den Papierkorb</button>
      <a class="btn-plain" href="index.php?day=<?= e($day) ?>">Abbrechen</a>
    </form>
    <?php ui_footer(); ?>
  </main>
</div>
</body>
</html>
