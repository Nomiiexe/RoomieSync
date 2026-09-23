<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function valid_csrf_token(mixed $submittedToken): bool
{
    return is_string($submittedToken)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}

function user_is_signed_in(): bool
{
    return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']);
}

function remember_cookie_parts(): ?array
{
    $value = $_COOKIE['roomiesync_remember'] ?? '';
    if (!is_string($value) || !preg_match('/\A([a-f0-9]{24}):([a-f0-9]{64})\z/i', $value, $matches)) {
        return null;
    }

    return [$matches[1], $matches[2]];
}

function write_remember_cookie(string $selector, string $validator, int $expiresAt): void
{
    setcookie('roomiesync_remember', $selector . ':' . $validator, [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function expire_remember_cookie(): void
{
    setcookie('roomiesync_remember', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['roomiesync_remember']);
}

function set_signed_in_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['full_name'] = (string) $user['full_name'];
    $_SESSION['username'] = (string) $user['username'];
}

function start_remembered_login(array $user): void
{
    $parts = remember_cookie_parts();
    if ($parts !== null) {
        $delete = database_connection()->prepare('DELETE FROM authTokensTb WHERE selector = ?');
        $delete->bind_param('s', $parts[0]);
        $delete->execute();
    }

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expiresAt = time() + (30 * 24 * 60 * 60);
    $expiresSql = date('Y-m-d H:i:s', $expiresAt);
    $tokenHash = hash('sha256', $validator);
    $userId = (int) $user['user_id'];

    $insert = database_connection()->prepare(
        'INSERT INTO authTokensTb (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)'
    );
    $insert->bind_param('isss', $userId, $selector, $tokenHash, $expiresSql);
    $insert->execute();
    write_remember_cookie($selector, $validator, $expiresAt);
}

function restore_remembered_login(): bool
{
    if (user_is_signed_in()) {
        return true;
    }

    $parts = remember_cookie_parts();
    if ($parts === null) {
        if (isset($_COOKIE['roomiesync_remember'])) {
            expire_remember_cookie();
        }
        return false;
    }

    [$selector, $validator] = $parts;
    $query = database_connection()->prepare(
        'SELECT t.token_hash, t.expires_at, u.user_id, u.full_name, u.username
         FROM authTokensTb AS t
         INNER JOIN userTb AS u ON u.user_id = t.user_id
         WHERE t.selector = ?
         LIMIT 1'
    );
    $query->bind_param('s', $selector);
    $query->execute();
    $remembered = $query->get_result()->fetch_assoc();

    if (
        !$remembered
        || strtotime((string) $remembered['expires_at']) <= time()
        || !hash_equals((string) $remembered['token_hash'], hash('sha256', $validator))
    ) {
        $delete = database_connection()->prepare('DELETE FROM authTokensTb WHERE selector = ?');
        $delete->bind_param('s', $selector);
        $delete->execute();
        expire_remember_cookie();
        return false;
    }

    set_signed_in_user($remembered);
    $newValidator = bin2hex(random_bytes(32));
    $newHash = hash('sha256', $newValidator);
    $newExpiry = time() + (30 * 24 * 60 * 60);
    $newExpirySql = date('Y-m-d H:i:s', $newExpiry);
    $rotate = database_connection()->prepare(
        'UPDATE authTokensTb SET token_hash = ?, expires_at = ? WHERE selector = ?'
    );
    $rotate->bind_param('sss', $newHash, $newExpirySql, $selector);
    $rotate->execute();
    write_remember_cookie($selector, $newValidator, $newExpiry);

    return true;
}

function consume_flash_message(): ?array
{
    if (!isset($_SESSION['flash_message']) || !is_array($_SESSION['flash_message'])) {
        return null;
    }

    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
    return $message;
}
