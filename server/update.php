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
            // Reihenfolge wichtig: Der Fremdschluessel braucht durchgehend
            // einen Index auf mission_id. Erst den Ersatz anlegen (mission_id
            // ist dort die fuehrende Spalte), dann den UNIQUE entfernen —
            // sonst MySQL-Fehler 1553.
            'ALTER TABLE resus_sessions ADD INDEX idx_mission (mission_id, started_at)',
            'ALTER TABLE resus_sessions DROP INDEX uq_mission',
        ],
    ],
    [
        'id'    => '2026_07_17_flugtage',
        'label' => 'Flugtage mit editierbaren Feldern (Maschine, Basis, Besatzung, Notizen)',
        'skip'  => function (PDO $pdo): bool {
            $q = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                              WHERE table_schema = DATABASE() AND table_name = 'days'");
            return (int)$q->fetchColumn() > 0;
        },
        'sql'   => [
            'CREATE TABLE days (
               id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               user_id  INT UNSIGNED NOT NULL,
               day      DATE NOT NULL,
               aircraft VARCHAR(64) NULL,
               base     VARCHAR(64) NULL,
               crew     VARCHAR(190) NULL,
               notes    TEXT NULL,
               UNIQUE KEY uq_user_day (user_id, day),
               FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
    ],
    [
        'id'    => '2026_07_17_wartung',
        'label' => 'Zustandsspeicher für automatische Wartung (Aufräumjob)',
        'skip'  => function (PDO $pdo): bool {
            $q = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                              WHERE table_schema = DATABASE() AND table_name = 'app_state'");
            return (int)$q->fetchColumn() > 0;
        },
        'sql'   => [
            'CREATE TABLE app_state (
               k VARCHAR(64) NOT NULL PRIMARY KEY,
               v VARCHAR(190) NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ],
    ],
    [
        'id'    => '2026_07_18_geraete_status',
        'label' => 'Geräte deaktivieren statt löschen (active-Flag)',
        'skip'  => function (PDO $pdo): bool {
            $q = $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE()
                                AND table_name = 'devices' AND column_name = 'active'");
            return (int)$q->fetchColumn() > 0;
        },
        'sql'   => [
            "ALTER TABLE devices ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER label",
        ],
    ],
    [
        'id'    => '2026_07_18_manuelle_einsaetze',
        'label' => 'Manuelle Einsätze: Schutzmarker + Zusatzfelder (Einsatznummer, Notizen)',
        'skip'  => function (PDO $pdo): bool {
            $q = $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE()
                                AND table_name = 'missions' AND column_name = 'manual'");
            return (int)$q->fetchColumn() > 0;
        },
        'sql'   => [
            "ALTER TABLE missions ADD COLUMN manual TINYINT(1) NOT NULL DEFAULT 0 AFTER final",
            "ALTER TABLE missions ADD COLUMN mission_no VARCHAR(64) NULL AFTER manual",
            "ALTER TABLE missions ADD COLUMN notes TEXT NULL AFTER mission_no",
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
            try {
                $pdo->exec($stmt);
            } catch (PDOException $inner) {
                // Nach einem Teil-Lauf koennen einzelne Schritte schon
                // erledigt sein: 1060 Spalte existiert, 1061 Index existiert,
                // 1091 zu loeschendes Objekt fehlt, 1050 Tabelle existiert.
                // Diese Faelle sind harmlos -> weitermachen.
                $code = (int)($inner->errorInfo[1] ?? 0);
                if (!in_array($code, [1050, 1060, 1061, 1091], true)) { throw $inner; }
            }
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
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/favicon.png"></head>
<body>
<header class="topbar">
  <a class="brand" href="index.php"><img src="assets/logo-weiss.png" alt="GenEM Einsatzdoku"></a>
  <nav><a href="index.php">Übersicht</a> <a href="admin.php">Verwaltung</a> <a href="geraete.php">Geräte</a> <a href="logout.php">Abmelden</a></nav>
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
<footer class="sitefooter">© Gen-EM · <a href="https://github.com/gen-em/einsatzdoku-luftrettung/blob/main/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a></footer>
</body>
</html>
