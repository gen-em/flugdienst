<?php
declare(strict_types=1);
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: login.php');
