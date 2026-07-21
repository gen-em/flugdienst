<?php
declare(strict_types=1);

/**
 * Papierkorb (Soft-Delete) fuer Einsaetze und Flugtage.
 *
 * Geloeschtes wird zunaechst nur markiert (`deleted_at`) und bleibt
 * TRASH_DAYS Tage wiederherstellbar; der Aufraeumjob in db.php entfernt es
 * danach endgueltig. Erst beim endgueltigen Entfernen wandert die Referenz in
 * die Sperrliste `deleted_refs`, damit die Uhr den Einsatz nicht erneut
 * hochlaedt — solange etwas im Papierkorb liegt, quittiert der Server
 * Uploads zwar, verwirft sie aber (siehe ingest.php).
 *
 * Beim Loeschen eines ganzen Flugtags werden dessen Einsaetze und
 * Ruhesegmente mit `deleted_with_day = 1` markiert. Sie erscheinen dadurch
 * nicht einzeln im Papierkorb, sondern haengen am Flugtag und kehren mit ihm
 * gemeinsam zurueck.
 */

const TRASH_DAYS = 30;

/* ---- Umfang ermitteln (fuer die Sicherheitsabfragen) ------------------- */

function trash_scope_mission(int $userId, int $id): ?array {
    $st = db()->prepare('SELECT * FROM missions WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    $m = $st->fetch();
    if (!$m) { return null; }

    $one = function (string $sql, array $p): int {
        $s = db()->prepare($sql); $s->execute($p); return (int)$s->fetchColumn();
    };
    return [
        'mission'  => $m,
        'phasen'   => $one('SELECT COUNT(*) FROM mission_phases WHERE mission_id = ?', [$id]),
        'reas'     => $one('SELECT COUNT(*) FROM resus_sessions WHERE mission_id = ?', [$id]),
        'punkte'   => $one("SELECT COUNT(*) FROM track_points
                            WHERE owner_type = 'mission' AND owner_id = ?", [$id]),
    ];
}

function trash_scope_day(int $userId, string $day): array {
    $one = function (string $sql, array $p): int {
        $s = db()->prepare($sql); $s->execute($p); return (int)$s->fetchColumn();
    };
    $missions = db()->prepare('SELECT id FROM missions
                               WHERE user_id = ? AND day = ? AND deleted_at IS NULL');
    $missions->execute([$userId, $day]);
    $mids = $missions->fetchAll(PDO::FETCH_COLUMN);

    $segs = db()->prepare('SELECT id FROM rest_segments
                           WHERE user_id = ? AND day = ? AND deleted_at IS NULL');
    $segs->execute([$userId, $day]);
    $sids = $segs->fetchAll(PDO::FETCH_COLUMN);

    $punkte = 0; $phasen = 0; $reas = 0;
    foreach ($mids as $mid) {
        $punkte += $one("SELECT COUNT(*) FROM track_points
                         WHERE owner_type = 'mission' AND owner_id = ?", [(int)$mid]);
        $phasen += $one('SELECT COUNT(*) FROM mission_phases WHERE mission_id = ?', [(int)$mid]);
        $reas   += $one('SELECT COUNT(*) FROM resus_sessions WHERE mission_id = ?', [(int)$mid]);
    }
    foreach ($sids as $sid) {
        $punkte += $one("SELECT COUNT(*) FROM track_points
                         WHERE owner_type = 'rest' AND owner_id = ?", [(int)$sid]);
    }
    $meta = db()->prepare('SELECT * FROM days WHERE user_id = ? AND day = ? AND deleted_at IS NULL');
    $meta->execute([$userId, $day]);

    return [
        'day'       => $day,
        'einsaetze' => count($mids),
        'segmente'  => count($sids),
        'punkte'    => $punkte,
        'phasen'    => $phasen,
        'reas'      => $reas,
        'meta'      => $meta->fetch() ?: null,
    ];
}

/* ---- In den Papierkorb legen ------------------------------------------ */

function trash_delete_mission(int $userId, int $id): void {
    $st = db()->prepare('UPDATE missions SET deleted_at = UTC_TIMESTAMP(), deleted_with_day = 0
                         WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
    $st->execute([$id, $userId]);
}

function trash_delete_day(int $userId, string $day): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Traegerzeile sicherstellen, damit der Tag im Papierkorb erscheint,
        // auch wenn nie Metadaten (Maschine/Besatzung) erfasst wurden.
        $pdo->prepare('INSERT IGNORE INTO days (user_id, day) VALUES (?, ?)')
            ->execute([$userId, $day]);
        $pdo->prepare('UPDATE days SET deleted_at = UTC_TIMESTAMP()
                       WHERE user_id = ? AND day = ?')->execute([$userId, $day]);
        $pdo->prepare('UPDATE missions SET deleted_at = UTC_TIMESTAMP(), deleted_with_day = 1
                       WHERE user_id = ? AND day = ? AND deleted_at IS NULL')
            ->execute([$userId, $day]);
        $pdo->prepare('UPDATE rest_segments SET deleted_at = UTC_TIMESTAMP(), deleted_with_day = 1
                       WHERE user_id = ? AND day = ? AND deleted_at IS NULL')
            ->execute([$userId, $day]);
        $pdo->commit();
    } catch (Throwable $ex) { $pdo->rollBack(); throw $ex; }
}

/* ---- Wiederherstellen -------------------------------------------------- */

function trash_restore_mission(int $userId, int $id): void {
    db()->prepare('UPDATE missions SET deleted_at = NULL
                   WHERE id = ? AND user_id = ? AND deleted_with_day = 0')
        ->execute([$id, $userId]);
}

function trash_restore_day(int $userId, string $day): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE days SET deleted_at = NULL WHERE user_id = ? AND day = ?')
            ->execute([$userId, $day]);
        $pdo->prepare('UPDATE missions SET deleted_at = NULL, deleted_with_day = 0
                       WHERE user_id = ? AND day = ? AND deleted_with_day = 1')
            ->execute([$userId, $day]);
        $pdo->prepare('UPDATE rest_segments SET deleted_at = NULL, deleted_with_day = 0
                       WHERE user_id = ? AND day = ? AND deleted_with_day = 1')
            ->execute([$userId, $day]);
        $pdo->commit();
    } catch (Throwable $ex) { $pdo->rollBack(); throw $ex; }
}

/* ---- Endgueltig entfernen ---------------------------------------------- */

/** Sperrliste fuellen, damit die Uhr den Einsatz nicht neu anlegt. */
function trash_block_ref(PDO $pdo, array $m): void {
    if ($m['device_id'] !== null && strpos((string)$m['client_ref'], 'man-') !== 0) {
        $pdo->prepare('INSERT IGNORE INTO deleted_refs (device_id, client_ref) VALUES (?,?)')
            ->execute([(int)$m['device_id'], $m['client_ref']]);
    }
}

function trash_purge_mission(int $userId, int $id): void {
    $pdo = db();
    $st = $pdo->prepare('SELECT id, device_id, client_ref FROM missions
                         WHERE id = ? AND user_id = ? AND deleted_at IS NOT NULL');
    $st->execute([$id, $userId]);
    $m = $st->fetch();
    if (!$m) { return; }

    $pdo->beginTransaction();
    try {
        trash_block_ref($pdo, $m);
        $pdo->prepare("DELETE FROM track_points WHERE owner_type = 'mission' AND owner_id = ?")
            ->execute([$id]);
        $pdo->prepare('DELETE FROM missions WHERE id = ?')->execute([$id]);  // Rest kaskadiert
        $pdo->commit();
    } catch (Throwable $ex) { $pdo->rollBack(); throw $ex; }
}

function trash_purge_day(int $userId, string $day): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ms = $pdo->prepare('SELECT id, device_id, client_ref FROM missions
                             WHERE user_id = ? AND day = ? AND deleted_at IS NOT NULL');
        $ms->execute([$userId, $day]);
        foreach ($ms->fetchAll() as $m) {
            trash_block_ref($pdo, $m);
            $pdo->prepare("DELETE FROM track_points WHERE owner_type = 'mission' AND owner_id = ?")
                ->execute([(int)$m['id']]);
            $pdo->prepare('DELETE FROM missions WHERE id = ?')->execute([(int)$m['id']]);
        }
        $ss = $pdo->prepare('SELECT id FROM rest_segments
                             WHERE user_id = ? AND day = ? AND deleted_at IS NOT NULL');
        $ss->execute([$userId, $day]);
        foreach ($ss->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $pdo->prepare("DELETE FROM track_points WHERE owner_type = 'rest' AND owner_id = ?")
                ->execute([(int)$sid]);
            $pdo->prepare('DELETE FROM rest_segments WHERE id = ?')->execute([(int)$sid]);
        }
        $pdo->prepare('DELETE FROM days WHERE user_id = ? AND day = ? AND deleted_at IS NOT NULL')
            ->execute([$userId, $day]);
        $pdo->commit();
    } catch (Throwable $ex) { $pdo->rollBack(); throw $ex; }
}

/* ---- Aufraeumjob: abgelaufene Papierkorb-Eintraege --------------------- */

function trash_purge_expired(PDO $pdo): void {
    $grenze = TRASH_DAYS;

    // Tage zuerst (nimmt die daran haengenden Einsaetze/Segmente mit)
    $st = $pdo->query("SELECT user_id, day FROM days
                       WHERE deleted_at IS NOT NULL
                         AND deleted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$grenze} DAY)");
    foreach ($st->fetchAll() as $d) {
        trash_purge_day((int)$d['user_id'], (string)$d['day']);
    }
    // Einzeln geloeschte Einsaetze
    $st = $pdo->query("SELECT id, user_id FROM missions
                       WHERE deleted_at IS NOT NULL AND deleted_with_day = 0
                         AND deleted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$grenze} DAY)");
    foreach ($st->fetchAll() as $m) {
        trash_purge_mission((int)$m['user_id'], (int)$m['id']);
    }
}

/* ---- Papierkorb-Inhalt fuer die Anzeige -------------------------------- */

function trash_list_days(int $userId): array {
    $st = db()->prepare(
        'SELECT d.day, d.deleted_at,
                (SELECT COUNT(*) FROM missions m
                  WHERE m.user_id = d.user_id AND m.day = d.day
                    AND m.deleted_with_day = 1 AND m.deleted_at IS NOT NULL) AS einsaetze
           FROM days d
          WHERE d.user_id = ? AND d.deleted_at IS NOT NULL
          ORDER BY d.deleted_at DESC');
    $st->execute([$userId]);
    return $st->fetchAll();
}

function trash_list_missions(int $userId): array {
    $st = db()->prepare(
        'SELECT id, day, started_at, deleted_at
           FROM missions
          WHERE user_id = ? AND deleted_at IS NOT NULL AND deleted_with_day = 0
          ORDER BY deleted_at DESC');
    $st->execute([$userId]);
    return $st->fetchAll();
}
