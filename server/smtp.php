<?php
declare(strict_types=1);

/**
 * Minimaler SMTPS-Versand (implizites TLS, z. B. Port 465 bei Stalwart).
 * Bewusst ohne Composer-Abhaengigkeit, damit es auf jedem Webspace laeuft.
 * Rueckgabe: true bei Erfolg, sonst false (Details im error_log).
 */
function smtp_send(string $toEmail, string $subject, string $textBody): bool {
    $cfg = (require __DIR__ . '/config.php')['smtp'];

    $fp = @stream_socket_client('ssl://' . $cfg['host'] . ':' . $cfg['port'],
        $errno, $errstr, 15, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => true]]));
    if (!$fp) { error_log("SMTP connect: $errstr"); return false; }
    stream_set_timeout($fp, 15);

    $expect = function (string $code) use ($fp): bool {
        do { $line = fgets($fp, 1024); if ($line === false) return false; }
        while (isset($line[3]) && $line[3] === '-');           // Multiline-Antworten
        return strncmp($line, $code, 3) === 0;
    };
    $send = function (string $cmd) use ($fp): void { fwrite($fp, $cmd . "\r\n"); };

    $ok = $expect('220');
    $send('EHLO ' . parse_url((require __DIR__ . '/config.php')['app']['base_url'], PHP_URL_HOST));
    $ok = $ok && $expect('250');
    $send('AUTH LOGIN');                        $ok = $ok && $expect('334');
    $send(base64_encode($cfg['user']));         $ok = $ok && $expect('334');
    $send(base64_encode($cfg['pass']));         $ok = $ok && $expect('235');
    $send('MAIL FROM:<' . $cfg['from'] . '>');  $ok = $ok && $expect('250');
    $send('RCPT TO:<' . $toEmail . '>');        $ok = $ok && $expect('250');
    $send('DATA');                              $ok = $ok && $expect('354');

    $headers = 'From: ' . mb_encode_mimeheader($cfg['from_name']) . ' <' . $cfg['from'] . ">\r\n"
             . 'To: <' . $toEmail . ">\r\n"
             . 'Subject: ' . mb_encode_mimeheader($subject) . "\r\n"
             . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n"
             . 'Date: ' . date(DATE_RFC2822) . "\r\n";
    $body = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $textBody)); // Dot-Stuffing
    $send($headers . "\r\n" . $body . "\r\n.");
    $ok = $ok && $expect('250');
    $send('QUIT');
    fclose($fp);
    if (!$ok) error_log('SMTP: Versand an ' . $toEmail . ' fehlgeschlagen');
    return $ok;
}
