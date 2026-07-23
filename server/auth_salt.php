<?php
declare(strict_types=1);
/**
 * Liefert das Schluesselableitungs-Salt zu einer E-Mail (fuer den Login).
 * POST JSON {"email": "..."} -> {"salt": hex}
 *
 * Unbekannte Adressen bekommen ein DETERMINISTISCHES Pseudo-Salt (HMAC mit
 * Server-Geheimnis) — Antworten sind damit nicht von echten unterscheidbar
 * und verraten nicht, welche Adressen existieren.
 */
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$b = json_decode(file_get_contents('php://input'), true);
$email = strtolower(trim((string)($b['email'] ?? '')));
if ($email === '' || strlen($email) > 190) {
    http_response_code(400); echo json_encode(['error' => 'email']); exit;
}

$pdo = db();
$st = $pdo->prepare('SELECT kdf_salt FROM users WHERE email = ?');
$st->execute([$email]);
$u = $st->fetch();

if ($u && $u['kdf_salt'] !== null) {
    echo json_encode(['salt' => $u['kdf_salt']]);
    exit;
}

// Server-Geheimnis fuer Pseudo-Salts (einmalig erzeugt, app_state)
$sec = $pdo->query("SELECT v FROM app_state WHERE k = 'salt_secret'")->fetchColumn();
if ($sec === false) {
    $sec = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT IGNORE INTO app_state (k, v) VALUES ('salt_secret', ?)")
        ->execute([$sec]);
}

// Unbekannte Adresse: Pseudo-Salt in derselben Form — die Antwort ist damit
// nicht von einer echten unterscheidbar. Die Anmeldung scheitert anschliessend
// am Token, ohne zu verraten, ob die Adresse existiert.
echo json_encode(['salt' => hash_hmac('sha256', $email, (string)$sec)]);
