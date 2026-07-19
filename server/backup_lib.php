<?php
declare(strict_types=1);
/**
 * Backup: Export/Import aller Einsatzdaten einer NutzerIn als verschluesselte
 * Datei (.edbak). Format-Doku: docs/Backup-Format.md
 *
 * Container v1 (binaer):
 *   Bytes 0-7   Magie "EDBAK1" + 0x00 + Version 0x01
 *   Bytes 8-23  Salt (16 Bytes, zufaellig)
 *   Bytes 24-35 IV (12 Bytes, zufaellig)
 *   Bytes 36-51 GCM-Auth-Tag (16 Bytes)
 *   ab Byte 52  AES-256-GCM-Chiffretext von gzip(JSON)
 * Schluessel: PBKDF2-SHA256(Passwort, Salt, 200 000 Runden, 32 Bytes)
 * Die Magie ist als "additional authenticated data" gebunden — jede
 * Manipulation an Kopf oder Inhalt laesst die Entschluesselung scheitern.
 */

const EDBAK_MAGIC = "EDBAK1\x00\x01";
const EDBAK_ITER  = 200000;

/* ======================= Container ======================= */

function edbak_seal(string $json, string $password): string {
    $salt = random_bytes(16);
    $iv   = random_bytes(12);
    $key  = hash_pbkdf2('sha256', $password, $salt, EDBAK_ITER, 32, true);
    $tag  = '';
    $ct = openssl_encrypt(gzencode($json, 6), 'aes-256-gcm', $key,
                          OPENSSL_RAW_DATA, $iv, $tag, EDBAK_MAGIC, 16);
    if ($ct === false) { throw new RuntimeException('Verschlüsselung fehlgeschlagen.'); }
    return EDBAK_MAGIC . $salt . $iv . $tag . $ct;
}

/** @return array dekodiertes JSON; wirft bei falschem Passwort/defekter Datei */
function edbak_open(string $blob, string $password): array {
    if (strlen($blob) < 53 || substr($blob, 0, 8) !== EDBAK_MAGIC) {
        throw new RuntimeException('Keine gültige Backup-Datei (.edbak).');
    }
    $salt = substr($blob, 8, 16);
    $iv   = substr($blob, 24, 12);
    $tag  = substr($blob, 36, 16);
    $key  = hash_pbkdf2('sha256', $password, $salt, EDBAK_ITER, 32, true);
    $gz = openssl_decrypt(substr($blob, 52), 'aes-256-gcm', $key,
                          OPENSSL_RAW_DATA, $iv, $tag, EDBAK_MAGIC);
    if ($gz === false) { throw new RuntimeException('Passwort falsch oder Datei beschädigt.'); }
    $json = gzdecode($gz);
    $data = $json !== false ? json_decode($json, true) : null;
    if (!is_array($data) || ($data['format'] ?? '') !== 'einsatzdoku-backup') {
        throw new RuntimeException('Backup-Inhalt unlesbar.');
    }
    return $data;
}

/* ======================= Export ======================= */

function edbak_build(int $userId): string {
    $pdo = db();
    $q = function (string $sql, array $p) use ($pdo): array {
        $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    $u = $q('SELECT email, name, pat_enabled, pat_fields, pat_wrap_pw, pat_wrap_rc
             FROM users WHERE id = ?', [$userId])[0];

    $tracks = function (string $type, int $id) use ($q): array {
        return array_map(
            fn($p) => [(int)$p['seq'], (float)$p['lat'], (float)$p['lon'],
                       $p['ele'] !== null ? (float)$p['ele'] : null, (int)$p['ts']],
            $q('SELECT seq, lat, lon, ele, ts FROM track_points
                WHERE owner_type = ? AND owner_id = ? ORDER BY seq', [$type, $id]));
    };

    $missions = [];
    foreach ($q('SELECT * FROM missions WHERE user_id = ? ORDER BY started_at', [$userId]) as $m) {
        $mid = (int)$m['id'];
        foreach (['id', 'user_id', 'device_id'] as $drop) { unset($m[$drop]); }
        $m['phases'] = array_map(
            fn($p) => ['phase' => (int)$p['phase'], 'occurred_at' => $p['occurred_at'],
                       'lat' => $p['lat'] !== null ? (float)$p['lat'] : null,
                       'lon' => $p['lon'] !== null ? (float)$p['lon'] : null],
            $q('SELECT phase, occurred_at, lat, lon FROM mission_phases
                WHERE mission_id = ? ORDER BY occurred_at', [$mid]));
        $m['resus'] = [];
        foreach ($q('SELECT id, started_at FROM resus_sessions
                     WHERE mission_id = ? ORDER BY started_at', [$mid]) as $s) {
            $m['resus'][] = [
                'started_at' => $s['started_at'],
                'events' => $q('SELECT type, occurred_at FROM resus_events
                                WHERE session_id = ? ORDER BY occurred_at', [(int)$s['id']]),
            ];
        }
        $m['track'] = $tracks('mission', $mid);
        $missions[] = $m;
    }

    $rests = [];
    foreach ($q('SELECT * FROM rest_segments WHERE user_id = ? ORDER BY started_at', [$userId]) as $r) {
        $rid = (int)$r['id'];
        foreach (['id', 'user_id', 'device_id'] as $drop) { unset($r[$drop]); }
        $r['track'] = $tracks('rest', $rid);
        $rests[] = $r;
    }

    // Flugtage: Verweise fuer Portabilitaet in Namen aufloesen
    $days = [];
    foreach ($q('SELECT d.*, a.registration AS aircraft_reg, b.name AS base_name
                 FROM days d
                 LEFT JOIN aircraft a ON a.id = d.aircraft_id
                 LEFT JOIN bases b ON b.id = d.base_id
                 WHERE d.user_id = ? ORDER BY d.day', [$userId]) as $d) {
        foreach (['id', 'user_id', 'aircraft_id', 'base_id'] as $drop) { unset($d[$drop]); }
        $days[] = $d;
    }

    $data = [
        'format' => 'einsatzdoku-backup',
        'version' => 1,
        'created_at' => gmdate('c'),
        'app' => 'einsatzdoku-luftrettung',
        'user' => ['email' => $u['email'], 'name' => $u['name']],
        'pat_module' => $u['pat_wrap_pw'] !== null ? [
            'enabled' => (int)$u['pat_enabled'],
            'fields'  => json_decode((string)$u['pat_fields'], true) ?: [],
            'wrap_pw' => $u['pat_wrap_pw'],
            'wrap_rc' => $u['pat_wrap_rc'],
        ] : null,
        'stammdaten' => [
            'bases'        => $q('SELECT name, is_default FROM bases WHERE user_id = ? ORDER BY name', [$userId]),
            'aircraft'     => $q('SELECT registration, p1, p2, hems, fr, other, is_default
                                  FROM aircraft WHERE user_id = ? ORDER BY registration', [$userId]),
            'crew_presets' => $q('SELECT role, name FROM crew_presets WHERE user_id = ? ORDER BY role, name', [$userId]),
            'bw_units'     => $q('SELECT name FROM bw_units WHERE user_id = ? ORDER BY name', [$userId]),
        ],
        'days' => $days,
        'missions' => $missions,
        'rest_segments' => $rests,
    ];
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ======================= Import ======================= */

/** @return array Zusammenfassung (Zaehler) */
function edbak_restore(int $userId, array $data): array {
    $pdo = db();
    $stats = ['missions' => 0, 'missions_skipped' => 0, 'rests' => 0, 'rests_skipped' => 0,
              'days' => 0, 'stammdaten' => 0, 'pat_module' => 'unverändert'];

    $pdo->beginTransaction();
    try {
        /* Stammdaten (INSERT IGNORE ueber die Unique-Schluessel) */
        $sd = $data['stammdaten'] ?? [];
        $hasDefBase = (bool)$pdo->query("SELECT COUNT(*) FROM bases WHERE user_id = $userId AND is_default = 1")->fetchColumn();
        foreach (($sd['bases'] ?? []) as $b) {
            $st = $pdo->prepare('INSERT IGNORE INTO bases (user_id, name, is_default) VALUES (?,?,?)');
            $st->execute([$userId, (string)$b['name'], $hasDefBase ? 0 : (int)($b['is_default'] ?? 0)]);
            $stats['stammdaten'] += $st->rowCount();
        }
        $hasDefAc = (bool)$pdo->query("SELECT COUNT(*) FROM aircraft WHERE user_id = $userId AND is_default = 1")->fetchColumn();
        foreach (($sd['aircraft'] ?? []) as $a) {
            $st = $pdo->prepare('INSERT IGNORE INTO aircraft
                (user_id, registration, p1, p2, hems, fr, other, is_default) VALUES (?,?,?,?,?,?,?,?)');
            $st->execute([$userId, (string)$a['registration'],
                (int)($a['p1'] ?? 0), (int)($a['p2'] ?? 0), (int)($a['hems'] ?? 0),
                (int)($a['fr'] ?? 0), (int)($a['other'] ?? 0),
                $hasDefAc ? 0 : (int)($a['is_default'] ?? 0)]);
            $stats['stammdaten'] += $st->rowCount();
        }
        foreach (($sd['crew_presets'] ?? []) as $c) {
            if (!in_array($c['role'] ?? '', ['p1','p2','hems','fr','other'], true)) { continue; }
            $st = $pdo->prepare('INSERT IGNORE INTO crew_presets (user_id, role, name) VALUES (?,?,?)');
            $st->execute([$userId, $c['role'], (string)$c['name']]);
            $stats['stammdaten'] += $st->rowCount();
        }
        foreach (($sd['bw_units'] ?? []) as $w) {
            $st = $pdo->prepare('INSERT IGNORE INTO bw_units (user_id, name) VALUES (?,?)');
            $st->execute([$userId, (string)$w['name']]);
            $stats['stammdaten'] += $st->rowCount();
        }

        /* Flugtage (bestehende Tage bleiben unangetastet) */
        foreach (($data['days'] ?? []) as $d) {
            $acId = null; $baseId = null;
            if (!empty($d['aircraft_reg'])) {
                $x = $pdo->prepare('SELECT id FROM aircraft WHERE user_id = ? AND registration = ?');
                $x->execute([$userId, $d['aircraft_reg']]);
                $acId = $x->fetchColumn() ?: null;
            }
            if (!empty($d['base_name'])) {
                $x = $pdo->prepare('SELECT id FROM bases WHERE user_id = ? AND name = ?');
                $x->execute([$userId, $d['base_name']]);
                $baseId = $x->fetchColumn() ?: null;
            }
            $st = $pdo->prepare('INSERT IGNORE INTO days
                (user_id, day, aircraft_id, base_id, crew_p1, crew_p2, crew_hems, crew_fr,
                 crew_other, aircraft, base, crew, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$userId, $d['day'], $acId, $baseId,
                $d['crew_p1'] ?? null, $d['crew_p2'] ?? null, $d['crew_hems'] ?? null,
                $d['crew_fr'] ?? null, $d['crew_other'] ?? null,
                $d['aircraft'] ?? null, $d['base'] ?? null, $d['crew'] ?? null,
                $d['notes'] ?? null]);
            $stats['days'] += $st->rowCount();
        }

        /* Einsaetze: Dublette = gleiche client_ref bei dieser NutzerIn */
        $exists = $pdo->prepare('SELECT id FROM missions WHERE user_id = ? AND client_ref = ?');
        $insPoint = $pdo->prepare('INSERT INTO track_points
            (owner_type, owner_id, seq, lat, lon, ele, ts) VALUES (?,?,?,?,?,?,?)');
        $FIELDS = require __DIR__ . '/mission_fields.php';
        $extraCols = [];
        $collectCols = function (array $fs) use (&$collectCols, &$extraCols) {
            foreach ($fs as $col => $f) {
                $extraCols[] = $col;
                if (!empty($f['children'])) { $collectCols($f['children']); }
            }
        };
        $collectCols($FIELDS);
        $extraCols = array_merge($extraCols, ['loc_addr', 'loc_lat', 'loc_lon', 'pat_blob']);

        foreach (($data['missions'] ?? []) as $m) {
            $exists->execute([$userId, (string)($m['client_ref'] ?? '')]);
            if ($exists->fetchColumn()) { $stats['missions_skipped']++; continue; }

            $cols = ['user_id', 'client_ref', 'day', 'started_at', 'ended_at',
                     'manual', 'final', 'distance_m', 'ascent_m'];
            $vals = [$userId, $m['client_ref'] ?? ('imp-' . bin2hex(random_bytes(6))),
                     $m['day'], $m['started_at'], $m['ended_at'] ?? null,
                     (int)($m['manual'] ?? 0), (int)($m['final'] ?? 1),
                     $m['distance_m'] ?? null, $m['ascent_m'] ?? null];
            foreach ($extraCols as $c) {
                if (array_key_exists($c, $m)) { $cols[] = $c; $vals[] = $m[$c]; }
            }
            $sql = 'INSERT INTO missions (' . implode(',', $cols) . ') VALUES ('
                 . implode(',', array_fill(0, count($cols), '?')) . ')';
            $pdo->prepare($sql)->execute($vals);
            $mid = (int)$pdo->lastInsertId();

            $insPh = $pdo->prepare('INSERT INTO mission_phases
                (mission_id, phase, occurred_at, lat, lon) VALUES (?,?,?,?,?)');
            foreach (($m['phases'] ?? []) as $p) {
                $insPh->execute([$mid, (int)$p['phase'], $p['occurred_at'],
                                 $p['lat'] ?? null, $p['lon'] ?? null]);
            }
            foreach (($m['resus'] ?? []) as $r) {
                $pdo->prepare('INSERT INTO resus_sessions (mission_id, started_at) VALUES (?,?)')
                    ->execute([$mid, $r['started_at']]);
                $sid = (int)$pdo->lastInsertId();
                $insEv = $pdo->prepare('INSERT INTO resus_events
                    (session_id, type, occurred_at) VALUES (?,?,?)');
                foreach (($r['events'] ?? []) as $e2) {
                    $insEv->execute([$sid, (string)$e2['type'], $e2['occurred_at']]);
                }
            }
            foreach (($m['track'] ?? []) as $p) {
                $insPoint->execute(['mission', $mid, (int)$p[0], (float)$p[1],
                                    (float)$p[2], $p[3], (int)$p[4]]);
            }
            $stats['missions']++;
        }

        /* Ruhesegmente */
        $rexists = $pdo->prepare('SELECT id FROM rest_segments WHERE user_id = ? AND client_ref = ?');
        foreach (($data['rest_segments'] ?? []) as $r) {
            $rexists->execute([$userId, (string)($r['client_ref'] ?? '')]);
            if ($rexists->fetchColumn()) { $stats['rests_skipped']++; continue; }
            $pdo->prepare('INSERT INTO rest_segments
                (user_id, client_ref, day, started_at, ended_at, final)
                VALUES (?,?,?,?,?,?)')
                ->execute([$userId, $r['client_ref'] ?? ('imp-' . bin2hex(random_bytes(6))),
                           $r['day'], $r['started_at'], $r['ended_at'] ?? null,
                           (int)($r['final'] ?? 1)]);
            $rid = (int)$pdo->lastInsertId();
            foreach (($r['track'] ?? []) as $p) {
                $insPoint->execute(['rest', $rid, (int)$p[0], (float)$p[1],
                                    (float)$p[2], $p[3], (int)$p[4]]);
            }
            $stats['rests']++;
        }

        /* PatientInnendaten-Modul: nur uebernehmen, wenn das Konto noch keins hat */
        $pm = $data['pat_module'] ?? null;
        if ($pm && !empty($pm['wrap_pw'])) {
            $cur = $pdo->prepare('SELECT pat_wrap_pw FROM users WHERE id = ?');
            $cur->execute([$userId]);
            if ($cur->fetchColumn() === null) {
                $pdo->prepare('UPDATE users SET pat_enabled = ?, pat_fields = ?,
                                 pat_wrap_pw = ?, pat_wrap_rc = ? WHERE id = ?')
                    ->execute([(int)$pm['enabled'], json_encode($pm['fields'] ?? []),
                               $pm['wrap_pw'], $pm['wrap_rc'] ?? null, $userId]);
                $stats['pat_module'] = 'übernommen';
            }
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $stats;
}
