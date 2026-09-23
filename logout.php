<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$parts = remember_cookie_parts();
if ($parts !== null) {
    $delete = database_connection()->prepare('DELETE FROM authTokensTb WHERE selector = ?');
    $delete->bind_param('s', $parts[0]);
    $delete->execute();
}

expire_remember_cookie();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $cookie['path'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => 'Lax',
    ]);
}
session_destroy();
redirect_to('login.php');
