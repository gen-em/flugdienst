<?php
declare(strict_types=1);
/**
 * Datenbank-Updates (Migrationen).
 * - Nur fuer eingeloggte Admins.
 * - NEUE MIGRATION? Die ID zusaetzlich am Ende von schema.sql eintragen,
 *   damit Neuinstallationen sie nicht unnoetig ausfuehren.
 * - Fuehrt Buch in der Tabelle schema_migrations: jede Migration laeuft genau
 *   einmal. Mehrfaches Aufrufen dieser Seite ist ungefaehrlich.
 * - Neue Migrationen werden unten in $MIGRATIONS ergaenzt.
 */
// Notausgang: Aufruf per Kommandozeile (SSH) laeuft ohne Web-Session —
// fuer den Fall, dass der Login selbst von einer Migration abhaengt.
//   php update.php
if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/db.php';              // liefert auch e()
} else {
    require_once __DIR__ . '/auth_guard.php';
    require_admin();
}

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
    [
        'id'    => '2026_07_19_phase10_entfernen',
        'label' => 'Phase 10 abgeschafft: alte Zeitstempel löschen, Einsatzende = Phase 9',
        'sql'   => [
            "UPDATE missions m
               JOIN (SELECT mission_id, MAX(occurred_at) AS t FROM mission_phases
                     WHERE phase = 9 GROUP BY mission_id) x ON x.mission_id = m.id
               SET m.ended_at = x.t",
            "DELETE FROM mission_phases WHERE phase = 10",
        ],
    ],
    [
        'id'    => '2026_07_19_profil_name',
        'label' => 'Profil: Anzeigename für NutzerInnen',
        'skip'  => function (PDO $pdo): bool {
            $q = $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE()
                                AND table_name = 'users' AND column_name = 'name'");
            return (int)$q->fetchColumn() > 0;
        },
        'sql'   => [
            "ALTER TABLE users ADD COLUMN name VARCHAR(120) NULL AFTER email",
        ],
    ],
    [
        'id'    => '2026_07_19_geraete_entkoppeln',
        'label' => 'Geräte löschbar ohne Datenverlust (Einsätze/Segmente bleiben, Verweis wird geleert)',
        'skip'  => function (PDO $pdo): bool {
            $q = $pdo->query("SELECT IS_NULLABLE FROM information_schema.columns
                              WHERE table_schema = DATABASE()
                                AND table_name = 'missions' AND column_name = 'device_id'");
            return $q->fetchColumn() === 'YES';
        },
        'run'   => function (PDO $pdo): void {
            foreach ([['missions', 'device_id'], ['rest_segments', 'device_id']] as $t) {
                [$tbl, $col] = $t;
                // Bestehende FK-Namen sind auto-generiert -> dynamisch ermitteln
                $q = $pdo->prepare("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                                      AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = 'devices'");
                $q->execute([$tbl, $col]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $fk) {
                    $pdo->exec("ALTER TABLE `$tbl` DROP FOREIGN KEY `$fk`");
                }
                $pdo->exec("ALTER TABLE `$tbl` MODIFY `$col` INT UNSIGNED NULL");
                $pdo->exec("ALTER TABLE `$tbl` ADD CONSTRAINT fk_{$tbl}_device
                            FOREIGN KEY (`$col`) REFERENCES devices(id) ON DELETE SET NULL");
            }
        },
    ],
    [
        'id'    => '2026_07_19_stammdaten',
        'label' => 'Stammdaten (Standorte, Hubschrauber mit Rollen, Besatzungs-Vorbelegungen, Bergwacht) + Flugtag-Dropdowns',
        'skip'  => function (PDO $pdo): bool {
            $q = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                              WHERE table_schema = DATABASE() AND table_name = 'bases'");
            return (int)$q->fetchColumn() > 0;
        },
        'sql'   => [
            "CREATE TABLE bases (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               user_id INT UNSIGNED NOT NULL,
               name VARCHAR(120) NOT NULL,
               UNIQUE KEY uq_user_name (user_id, name),
               FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE aircraft (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               user_id INT UNSIGNED NOT NULL,
               registration VARCHAR(64) NOT NULL,
               p1 TINYINT(1) NOT NULL DEFAULT 0, p2 TINYINT(1) NOT NULL DEFAULT 0,
               hems TINYINT(1) NOT NULL DEFAULT 0, fr TINYINT(1) NOT NULL DEFAULT 0,
               other TINYINT(1) NOT NULL DEFAULT 0,
               UNIQUE KEY uq_user_reg (user_id, registration),
               FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE crew_presets (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               user_id INT UNSIGNED NOT NULL,
               role ENUM('p1','p2','hems','fr','other') NOT NULL,
               name VARCHAR(120) NOT NULL,
               UNIQUE KEY uq_user_role_name (user_id, role, name),
               FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE bw_units (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               user_id INT UNSIGNED NOT NULL,
               name VARCHAR(120) NOT NULL,
               UNIQUE KEY uq_user_name (user_id, name),
               FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "ALTER TABLE days ADD COLUMN aircraft_id INT UNSIGNED NULL AFTER day",
            "ALTER TABLE days ADD COLUMN base_id INT UNSIGNED NULL AFTER aircraft_id",
            "ALTER TABLE days ADD COLUMN crew_p1 VARCHAR(120) NULL AFTER base_id",
            "ALTER TABLE days ADD COLUMN crew_p2 VARCHAR(120) NULL AFTER crew_p1",
            "ALTER TABLE days ADD COLUMN crew_hems VARCHAR(120) NULL AFTER crew_p2",
            "ALTER TABLE days ADD COLUMN crew_fr VARCHAR(120) NULL AFTER crew_hems",
            "ALTER TABLE days ADD COLUMN crew_other VARCHAR(120) NULL AFTER crew_fr",
            "ALTER TABLE days ADD CONSTRAINT fk_days_aircraft
               FOREIGN KEY (aircraft_id) REFERENCES aircraft(id) ON DELETE SET NULL",
            "ALTER TABLE days ADD CONSTRAINT fk_days_base
               FOREIGN KEY (base_id) REFERENCES bases(id) ON DELETE SET NULL",
        ],
    ],
    [
        'id'    => '2026_07_20_einsatzfelder_ort',
        'label' => 'Einsatzfelder-Ausbau (Winde, Bergwacht, Transportziel …), Einsatzort mit Koordinaten, Lösch-Sperrliste',
        'skip'  => function (PDO $pdo): bool {
            $q = $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE()
                                AND table_name = 'missions' AND column_name = 'winch'");
            return (int)$q->fetchColumn() > 0;
        },
        'sql'   => [
            "ALTER TABLE missions ADD COLUMN transport_dest VARCHAR(190) NULL AFTER mission_no",
            "ALTER TABLE missions ADD COLUMN site_desc VARCHAR(190) NULL AFTER transport_dest",
            "ALTER TABLE missions ADD COLUMN winch TINYINT(1) NOT NULL DEFAULT 0 AFTER site_desc",
            "ALTER TABLE missions ADD COLUMN winch_cycles TINYINT NULL AFTER winch",
            "ALTER TABLE missions ADD COLUMN winch_cycles_pat TINYINT NULL AFTER winch_cycles",
            "ALTER TABLE missions ADD COLUMN winch_airload TINYINT(1) NOT NULL DEFAULT 0 AFTER winch_cycles_pat",
            "ALTER TABLE missions ADD COLUMN bergwacht TINYINT(1) NOT NULL DEFAULT 0 AFTER winch_airload",
            "ALTER TABLE missions ADD COLUMN bw_unit VARCHAR(120) NULL AFTER bergwacht",
            "ALTER TABLE missions ADD COLUMN bw_info VARCHAR(190) NULL AFTER bw_unit",
            "ALTER TABLE missions ADD COLUMN other_ema VARCHAR(190) NULL AFTER bw_info",
            "ALTER TABLE missions ADD COLUMN other_resources VARCHAR(190) NULL AFTER other_ema",
            "ALTER TABLE missions ADD COLUMN loc_addr VARCHAR(255) NULL AFTER other_resources",
            "ALTER TABLE missions ADD COLUMN loc_lat DOUBLE NULL AFTER loc_addr",
            "ALTER TABLE missions ADD COLUMN loc_lon DOUBLE NULL AFTER loc_lat",
            "CREATE TABLE deleted_refs (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               device_id INT UNSIGNED NOT NULL,
               client_ref VARCHAR(64) NOT NULL,
               deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
               UNIQUE KEY uq_dev_ref (device_id, client_ref)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
    ],
    [
        'id'    => '2026_07_20_stammdaten_defaults',
        'label' => 'Standard-Maschine und Standard-Standort (Flugtag-Vorbelegung)',
        'skip'  => function (PDO $pdo): bool {
            $q = $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE()
                                AND table_name = 'aircraft' AND column_name = 'is_default'");
            return (int)$q->fetchColumn() > 0;
        },
        'sql'   => [
            "ALTER TABLE aircraft ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE bases ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0",
        ],
    ],
    [
        'id'    => '2026_07_20_kopplung',
        'label' => 'Geräte-Kopplung per Kurzcode (5 Zeichen, 60 Minuten gültig)',
        'skip'  => function (PDO $pdo): bool {
            $q = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                              WHERE table_schema = DATABASE() AND table_name = 'pair_codes'");
            return (int)$q->fetchColumn() > 0;
        },
        'sql'   => [
            "CREATE TABLE pair_codes (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               user_id INT UNSIGNED NOT NULL,
               code VARCHAR(8) NOT NULL UNIQUE,
               created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
               used_at TIMESTAMP NULL,
               FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
    ],
    [
        'id'    => '2026_07_20_patientinnendaten',
        'label' => 'PatientInnendaten-Modul: Ende-zu-Ende-Verschlüsselung (Schlüsselableitung, Modul-Einstellungen, Datenblob)',
        'skip'  => function (PDO $pdo): bool {
            $q = $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE()
                                AND table_name = 'users' AND column_name = 'kdf_salt'");
            return (int)$q->fetchColumn() > 0;
        },
        'sql'   => [
            "ALTER TABLE users ADD COLUMN kdf_salt VARCHAR(64) NULL",
            "ALTER TABLE users ADD COLUMN kdf_ver TINYINT NOT NULL DEFAULT 0",
            "ALTER TABLE users ADD COLUMN pat_enabled TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE users ADD COLUMN pat_fields VARCHAR(190) NULL",
            "ALTER TABLE users ADD COLUMN pat_wrap_pw TEXT NULL",
            "ALTER TABLE users ADD COLUMN pat_wrap_rc TEXT NULL",
            "ALTER TABLE missions ADD COLUMN pat_blob TEXT NULL",
        ],
    ],
    [
        'id'    => '2026_07_21_pflicht_e2e',
        'label' => 'Pflicht-Verschlüsselung: Einsatzort wandert in den verschlüsselten Block (Klartext-Altdaten entfallen), Felder Diagnose/Alter, Modul-Schalter entfallen',
        'skip'  => function (PDO $pdo): bool {
            $q = $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE()
                                AND table_name = 'missions' AND column_name = 'loc_addr'");
            return (int)$q->fetchColumn() === 0;
        },
        'sql'   => [
            "ALTER TABLE missions DROP COLUMN loc_addr",
            "ALTER TABLE missions DROP COLUMN loc_lat",
            "ALTER TABLE missions DROP COLUMN loc_lon",
            "ALTER TABLE users DROP COLUMN pat_enabled",
            "ALTER TABLE users DROP COLUMN pat_fields",
        ],
    ],
    [
        'id'    => '2026_07_22_tag_zuordnung',
        'label' => 'Tageszuordnung: Tag = lokales Datum des Einsatz-/Segmentbeginns (Wechsel 0:00); Bestand wird neu zugeordnet',
        'run'   => function (PDO $pdo): void {
            global $CFG;
            $tz  = new DateTimeZone($CFG['app']['timezone'] ?? 'Europe/Berlin');
            $utc = new DateTimeZone('UTC');
            foreach (['missions', 'rest_segments'] as $tab) {
                $rows = $pdo->query("SELECT id, day, started_at FROM `$tab`")->fetchAll(PDO::FETCH_ASSOC);
                $upd = $pdo->prepare("UPDATE `$tab` SET day = ? WHERE id = ?");
                foreach ($rows as $r) {
                    $d = new DateTime((string)$r['started_at'], $utc);
                    $d->setTimezone($tz);
                    $local = $d->format('Y-m-d');
                    if ($local !== (string)$r['day']) { $upd->execute([$local, (int)$r['id']]); }
                }
            }
        },
    ],
    [
        'id'    => '2026_07_22_papierkorb',
        'label' => 'Papierkorb: Einsätze, Ruhesegmente und Flugtage werden erst als gelöscht markiert',
        'sql'   => [
            "ALTER TABLE missions ADD COLUMN deleted_at DATETIME NULL",
            "ALTER TABLE missions ADD COLUMN deleted_with_day TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE missions ADD INDEX idx_missions_deleted (user_id, deleted_at)",
            "ALTER TABLE rest_segments ADD COLUMN deleted_at DATETIME NULL",
            "ALTER TABLE rest_segments ADD COLUMN deleted_with_day TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE days ADD COLUMN deleted_at DATETIME NULL",
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
        if (isset($m['run'])) { ($m['run'])($pdo); }
        foreach (($m['sql'] ?? []) as $stmt) {
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
<?php ui_topbar(''); ?>

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
<?php ui_footer(); ?>
</main>
</body>
</html>
