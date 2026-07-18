<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);

$raw = file_get_contents('php://input');
if (strlen($raw) > $CFG['app']['max_body_bytes']) json_out(['error' => 'too_large'], 413);

// --- Geraet authentifizieren -------------------------------------------------
$deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
$apiKey   = $_SERVER['HTTP_X_API_KEY']   ?? '';
$st = db()->prepare('SELECT id, user_id, api_key_hash, active FROM devices WHERE device_id = ?');
$st->execute([$deviceId]);
$dev = $st->fetch();
if (!$dev || !password_verify($apiKey, $dev['api_key_hash'])) json_out(['error' => 'auth'], 401);
if (!(int)$dev['active']) json_out(['error' => 'device_disabled'], 403);

// --- Payload pruefen ----------------------------------------------------------
$b = json_decode($raw, true);
$kind = $b['kind'] ?? '';
$clientRef = (string)($b['client_ref'] ?? '');
$day = (string)($b['day'] ?? '');
$startedAt = iso_to_sql($b['started_at'] ?? null);
if (!is_array($b) || $clientRef === '' || $startedAt === null
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)
    || !in_array($kind, ['mission', 'rest_segment'], true)) {
    json_out(['error' => 'payload'], 400);
}
$endedAt = iso_to_sql($b['ended_at'] ?? null);
$final   = !empty($b['final']) ? 1 : 0;
$points  = $b['track']['points'] ?? [];
$seqFrom = (int)($b['track']['seq_from'] ?? 0);
if ($seqFrom < 0) json_out(['error' => 'payload'], 400);
if (!is_array($points) || count($points) > 2000) json_out(['error' => 'payload'], 400);

$pdo = db();
$pdo->beginTransaction();
try {
    if ($kind === 'mission') {
        // Manuell bearbeitete Einsaetze schuetzen: Uhr-Uploads duerfen
        // Metadaten/Phasen/Rea nicht mehr ueberschreiben; Trackpunkte werden
        // weiterhin ergaenzt (Append-only, unkritisch).
        $chk = $pdo->prepare('SELECT id, manual FROM missions WHERE device_id = ? AND client_ref = ?');
        $chk->execute([$dev['id'], $clientRef]);
        $existing = $chk->fetch();
        if ($existing && (int)$existing['manual'] === 1) {
            $ownerId = (int)$existing['id'];
            $ownerType = 'mission';
        } else {
        // Upsert des Einsatzes (idempotent ueber device_id+client_ref)
        $pdo->prepare('INSERT INTO missions (user_id, device_id, client_ref, day, started_at, ended_at, distance_m, ascent_m, final)
                       VALUES (?,?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE
                         ended_at = VALUES(ended_at), distance_m = VALUES(distance_m),
                         ascent_m = VALUES(ascent_m), final = GREATEST(final, VALUES(final)),
                         id = LAST_INSERT_ID(id)')
            ->execute([$dev['user_id'], $dev['id'], $clientRef, $day, $startedAt, $endedAt,
                       isset($b['distance_m']) ? (int)$b['distance_m'] : null,
                       isset($b['ascent_m'])   ? (int)$b['ascent_m']   : null, $final]);
        $ownerId = (int)$pdo->lastInsertId();
        $ownerType = 'mission';

        // Phasenliste vollstaendig ersetzen (Vertrag: kein Delta)
        if (isset($b['phases']) && is_array($b['phases'])) {
            $pdo->prepare('DELETE FROM mission_phases WHERE mission_id = ?')->execute([$ownerId]);
            $ins = $pdo->prepare('INSERT INTO mission_phases (mission_id, phase, occurred_at, lat, lon) VALUES (?,?,?,?,?)');
            foreach ($b['phases'] as $p) {
                $at = iso_to_sql($p['at'] ?? null);
                $ph = (int)($p['phase'] ?? 0);
                if ($at === null || $ph < 2 || $ph > 10) continue;
                $ins->execute([$ownerId, $ph, $at,
                    isset($p['lat']) ? (float)$p['lat'] : null,
                    isset($p['lon']) ? (float)$p['lon'] : null]);
            }
        }

        // Reanimationen vollstaendig ersetzen (mehrere Sitzungen moeglich).
        // "resus_sessions" (Liste) ist aktuell; ein altes "resus"-Objekt wird
        // als Liste mit einem Eintrag behandelt.
        $sessions = null;
        if (isset($b['resus_sessions']) && is_array($b['resus_sessions'])) {
            $sessions = $b['resus_sessions'];
        } elseif (!empty($b['resus']) && is_array($b['resus'])) {
            $sessions = [$b['resus']];
        }
        if ($sessions !== null) {
            $pdo->prepare('DELETE FROM resus_sessions WHERE mission_id = ?')->execute([$ownerId]);
            $insS = $pdo->prepare('INSERT INTO resus_sessions (mission_id, started_at) VALUES (?,?)');
            $insE = $pdo->prepare('INSERT INTO resus_events (session_id, type, occurred_at) VALUES (?,?,?)');
            foreach ($sessions as $sess) {
                $rStart = iso_to_sql($sess['started_at'] ?? null);
                if ($rStart === null) continue;
                $insS->execute([$ownerId, $rStart]);
                $sid = (int)$pdo->lastInsertId();
                foreach (($sess['events'] ?? []) as $ev) {
                    $at = iso_to_sql($ev['at'] ?? null);
                    $ty = (string)($ev['type'] ?? '');
                    if ($at !== null && isset(RESUS_LABELS[$ty]) && $ty !== 'beginn') {
                        $insE->execute([$sid, $ty, $at]);
                    }
                }
            }
        }
        }   // Ende: nicht-manueller Einsatz
    } else { // rest_segment
        $pdo->prepare('INSERT INTO rest_segments (user_id, device_id, client_ref, day, started_at, ended_at, final)
                       VALUES (?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE
                         ended_at = VALUES(ended_at), final = GREATEST(final, VALUES(final)),
                         id = LAST_INSERT_ID(id)')
            ->execute([$dev['user_id'], $dev['id'], $clientRef, $day, $startedAt, $endedAt, $final]);
        $ownerId = (int)$pdo->lastInsertId();
        $ownerType = 'rest';
    }

    // Trackpunkte anhaengen: bereits bekannte Sequenzen ueberspringen (idempotent)
    $stored = 0;
    if ($points) {
        $ins = $pdo->prepare('INSERT IGNORE INTO track_points (owner_type, owner_id, seq, lat, lon, ele, ts)
                              VALUES (?,?,?,?,?,?,?)');
        foreach ($points as $i => $pt) {
            if (!is_array($pt) || count($pt) < 4) continue;
            $ins->execute([$ownerType, $ownerId, $seqFrom + $i,
                (float)$pt[0], (float)$pt[1],
                $pt[2] === null ? null : (float)$pt[2], (int)$pt[3]]);
            $stored += $ins->rowCount();
        }
    }

    $q = $pdo->prepare('SELECT COALESCE(MAX(seq)+1, 0) AS next FROM track_points WHERE owner_type = ? AND owner_id = ?');
    $q->execute([$ownerType, $ownerId]);
    $nextSeq = (int)$q->fetchColumn();

    $pdo->prepare('UPDATE devices SET last_seen = NOW() WHERE id = ?')->execute([$dev['id']]);
    $pdo->commit();
    run_cleanup_if_due();   // taegliche Wartung, huckepack auf Uhr-Uploads
    json_out(['ok' => true, 'id' => $ownerId, 'stored_points' => $stored, 'next_seq' => $nextSeq]);
} catch (Throwable $ex) {
    $pdo->rollBack();
    json_out(['error' => 'server'], 500);
}
