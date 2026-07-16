<?php
declare(strict_types=1);
/**
 * Datenbank-Updates (Migrationen).
 * - Nur fuer eingeloggte Admins.
 * - Fuehrt Buch in der Tabelle schema_migrations: jede Migration laeuft genau
 *   einmal. Mehrfaches Aufrufen dieser Seite ist ungefaehrlich.
 * - Neue Migrationen werden unten in $MIGRATIONS ergaenzt.
 */
require_once __DIR__ . '/auth_guard.php';
require_admin();

/* ---- Migrationsliste ------------------------------------------------------
 * 'id'    : eindeutiger, aufsteigender Name (Datum_stichwort)
 * 'label' : Beschreibung fuer die Anzeige
 * 'skip'  : optionale Pruefung; liefert true, wenn die Aenderung in dieser
 *           Datenbank nicht noetig ist (z. B. frisch mit aktuellem Schema
 *           installiert) -> wird als "uebersprungen" verbucht
 * 'sql'   : Liste der auszufuehrenden Statements
 */
$MIGRATIONS = [
    [
        'id'    => '2026_07_16_mehrere_reanimationen',
        'label' => 'Mehrere Reanimationen pro Einsatz erlauben',
        'skip'  => function (PDO $pdo): bool {
            // Nur noetig, wenn der alte UNIQUE-Index uq_mission existiert
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics
                                WHERE table_schema = DATABASE()
                                  AND table_name = 'resus_sessions'
                                  AND index_name = 'uq_mission'");
            $q->execute();
            return (int)$q->fetchColumn() === 0;
        },
        'sql'   => [
            'ALTER TABLE resus_sessions DROP INDEX uq_mission',
            'ALTER TABLE resus_sessions ADD INDEX idx_mission (mission_id, started_at)',
        ],
    ],
    // Naechste Migration hier anhaengen.
];

/* ---- Runner --------------------------------------------------------------- */
$pdo = db();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
              id         VARCHAR(120) NOT NULL PRIMARY KEY,
              applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              status     VARCHAR(16) NOT NULL DEFAULT "applied"
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$applied = $pdo->query('SELECT id FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$results = [];   // [id, label, status, detail]
$ranSomething = false;

foreach ($MIGRATIONS as $m) {
    if (in_array($m['id'], $applied, true)) {
        $results[] = [$m['id'], $m['label'], 'ok', 'Bereits angewendet.'];
        continue;
    }

    if (isset($m['skip']) && ($m['skip'])($pdo)) {
        $pdo->prepare('INSERT INTO schema_migrations (id, status) VALUES (?, "skipped")')
            ->execute([$m['id']]);
        $results[] = [$m['id'], $m['label'], 'ok', 'Nicht nötig (Schema bereits aktuell) — als erledigt vermerkt.'];
        continue;
    }

    try {
        foreach ($m['sql'] as $stmt) {
            $pdo->exec($stmt);
        }
        $pdo->prepare('INSERT INTO schema_migrations (id, status) VALUES (?, "applied")')
            ->execute([$m['id']]);
        $results[] = [$m['id'], $m['label'], 'ok', 'Erfolgreich angewendet.'];
        $ranSomething = true;
    } catch (Throwable $ex) {
        // Nicht verbuchen -> naechster Aufruf versucht es erneut
        $results[] = [$m['id'], $m['label'], 'fail',
                      'Fehler: ' . $ex->getMessage() . ' — Migration wurde NICHT als erledigt vermerkt.'];
        break;   // Reihenfolge wahren: nachfolgende Migrationen nicht ausfuehren
    }
}
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Datenbank-Update — Einsatzdoku</title>
<link rel="stylesheet" href="assets/style.css"></head>
<body>
<header class="topbar">
  <span class="brand">Einsatzdoku</span>
  <nav><a href="index.php">Übersicht</a> <a href="admin.php">Verwaltung</a> <a href="logout.php">Abmelden</a></nav>
</header>
<main class="page">
  <h1>Datenbank-Update</h1>
  <?php if (!$results): ?>
    <p class="alert alert-info">Keine Migrationen definiert.</p>
  <?php else: ?>
    <?php if ($ranSomething): ?>
      <p class="alert alert-info">Updates wurden angewendet — Details unten.</p>
    <?php endif; ?>
    <table class="data">
      <thead><tr><th>Update</th><th>Status</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($results as [$id, $label, $status, $detail]): ?>
        <tr>
          <td><?= e($label) ?><br><span class="muted"><code><?= e($id) ?></code></span></td>
          <td><?= $status === 'ok' ? '✔' : '✖' ?></td>
          <td><?= e($detail) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted">Diese Seite kann gefahrlos mehrfach aufgerufen werden —
       bereits erledigte Updates werden übersprungen.</p>
  <?php endif; ?>
</main>
</body>
</html>
