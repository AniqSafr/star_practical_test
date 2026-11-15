<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// An admin who does nothing for this long is logged out (seconds).
const SESSION_IDLE_LIMIT = 1800;   // 30 minutes

function is_logged_in(): bool
{
    if (empty($_SESSION['admin_id'])) {
        return false;
    }

    $last = (int) ($_SESSION['last_seen'] ?? 0);
    if ($last > 0 && time() - $last > SESSION_IDLE_LIMIT) {
        $_SESSION = [];     // idle for too long: forget the login (the next page asks for the password)
        return false;
    }

    $_SESSION['last_seen'] = time();

    return true;
}

// Put this at the top of every admin page that must be protected.
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_check(mixed $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

//  Login rate limit: after LOGIN_MAX_FAILS failed attempts from one IP address within LOGIN_WINDOW seconds, that IP is locked out until the oldest of those failures expires.
//  Only a hash of the IP is stored.
const LOGIN_MAX_FAILS = 5;
const LOGIN_WINDOW    = 900;   // 15 minutes

function login_client_key(): string
{
    //REMOTE_ADDR is used on purpose: headers such as X-Forwarded-For can be faked by the client (behind a trusted proxy, map the real IP here).
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')); 
}

// Seconds the visitor still has to wait (0 = not locked).
function login_locked_seconds(): int
{
    $now  = time();
    $stmt = db()->prepare(
        'SELECT attempted_at FROM login_attempts
         WHERE ip_hash = ? AND attempted_at > ? ORDER BY attempted_at DESC LIMIT ' . LOGIN_MAX_FAILS
    );
    $stmt->execute([login_client_key(), date('Y-m-d H:i:s', $now - LOGIN_WINDOW)]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($rows) < LOGIN_MAX_FAILS) {
        return 0;
    }

    // the lock ends when the oldest of the last LOGIN_MAX_FAILS failures leaves the window
    return max(1, (int) strtotime((string) end($rows)) + LOGIN_WINDOW - $now);
}

function login_record_failure(): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO login_attempts (ip_hash, attempted_at) VALUES (?, ?)')
        ->execute([login_client_key(), date('Y-m-d H:i:s')]);

    // housekeeping: forget attempts older than one day
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
        ->execute([date('Y-m-d H:i:s', time() - 86400)]);
}

function login_clear_failures(): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([login_client_key()]);
}