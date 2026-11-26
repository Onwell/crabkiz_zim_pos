<?php
// cashier/cashier_guard.php — shared protection for every cashier page and API file.
// Usage: require __DIR__ . '/cashier_guard.php';
//        (API files that must always answer in JSON: define('GUARD_JSON', true); before the require)
declare(strict_types=1);

const CASHIER_IDLE_SECONDS = 1800; // sign out after 30 minutes without real activity

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$asJson = $isPost || defined('GUARD_JSON');

function cashier_deny(int $code, string $message, string $code_name, string $redirect = ''): void
{
    global $asJson;
    if ($asJson) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message, 'code' => $code_name]);
    } elseif ($redirect !== '') {
        header('Location: ' . $redirect);
    } else {
        http_response_code($code);
        echo $message;
    }
    exit;
}

// 1) Must be signed in
if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'Please sign in first.'];
    cashier_deny(401, 'Please sign in again.', 'session_expired', '../index.php');
}

// 2) Idle timeout (background polling sends X-Background-Poll and does not keep the session alive)
$now  = time();
$last = (int)($_SESSION['last_activity'] ?? $now);
if ($now - $last > CASHIER_IDLE_SECONDS) {
    $_SESSION = [];
    session_destroy();
    session_start();
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'You were signed out because of inactivity.'];
    cashier_deny(401, 'Your session expired. Please sign in again.', 'session_expired', '../index.php');
}
if (($_SERVER['HTTP_X_BACKGROUND_POLL'] ?? '') !== '1') {
    $_SESSION['last_activity'] = $now;
}

// 3) Must be a Cashier (admins are sent to their own dashboard)
$role = (string)($_SESSION['user']['role'] ?? '');
if ($role !== 'Cashier') {
    cashier_deny(403, 'Forbidden.', 'forbidden', $role === 'Admin' ? '../admin/index.php' : '../index.php');
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
        cashier_deny(403, 'Security check failed. Refresh the page and try again.', 'csrf');
    }
}
