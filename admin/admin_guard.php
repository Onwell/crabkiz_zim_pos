<?php
// admin/admin_guard.php — shared protection for every admin page.
// Usage (first line after <?php and declare(strict_types=1);):  require __DIR__ . '/admin_guard.php';
// Provides: $_SESSION['user'], csrf_token(), and automatic CSRF + role + idle-timeout checks.
declare(strict_types=1);

const ADMIN_IDLE_SECONDS = 1800; // sign out after 30 minutes without real activity

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,      // cookie only over HTTPS when the site is on HTTPS
        'httponly' => true,        // JavaScript cannot read the session cookie
        'samesite' => 'Strict',    // not sent on cross-site requests
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

function admin_deny(int $code, string $message, string $redirect = ''): void
{
    global $isPost;
    if ($isPost) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $code === 401 ? 'session_expired' : 'forbidden', 'message' => $message]);
    } else {
        if ($redirect !== '') {
            header('Location: ' . $redirect);
        } else {
            http_response_code($code);
            echo $message;
        }
    }
    exit;
}

// 1) Must be signed in
if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'Please sign in first.'];
    admin_deny(401, 'Please sign in again.', '../index.php');
}

// 2) Idle timeout (background polling sends X-Background-Poll and does not keep the session alive)
$now  = time();
$last = (int)($_SESSION['last_activity'] ?? $now);
if ($now - $last > ADMIN_IDLE_SECONDS) {
    $_SESSION = [];
    session_destroy();
    session_start();
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'You were signed out because of inactivity.'];
    admin_deny(401, 'Your session expired. Please sign in again.', '../index.php');
}
if (($_SERVER['HTTP_X_BACKGROUND_POLL'] ?? '') !== '1') {
    $_SESSION['last_activity'] = $now;
}

// 3) Must be an Admin (missing role is treated as not admin)
if (($_SESSION['user']['role'] ?? '') !== 'Admin') {
    admin_deny(403, 'Forbidden.', '../cashier/index.php');
}

// 4) CSRF token
function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
csrf_token();

if ($isPost) {
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf'], $sent)) {
        admin_deny(403, 'Security check failed. Refresh the page and try again.');
    }
}
