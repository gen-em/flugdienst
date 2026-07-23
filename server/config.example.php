<?php
// Nach config.php kopieren und ausfuellen. config.php NIE ins Git-Repo committen!
return [
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=hems;charset=utf8mb4',
        'user' => 'hems',
        'pass' => 'CHANGE_ME',
    ],
    'app' => [
        'base_url'  => 'https://einsatz.example.de',  // ohne Slash am Ende
        'timezone'  => 'Europe/Berlin',               // Anzeige; Speicherung ist UTC
        'logo_path' => 'assets/images/gen-em_logo_helicopter.svg',  // Logo auf Login- und Einrichtungsseite
        'max_body_bytes' => 524288,                   // 512 KB Ingest-Limit
    ],
    'smtp' => [                                       // z. B. eigener Stalwart-Server
        'host' => 'mail.example.de',
        'port' => 465,                                // implizites TLS (SMTPS)
        'user' => 'noreply@example.de',
        'pass' => 'CHANGE_ME',
        'from' => 'noreply@example.de',
        'from_name' => 'Einsatzdoku',
    ],
];
