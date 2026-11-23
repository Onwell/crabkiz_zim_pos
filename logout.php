<?php
// logout.php
declare(strict_types=1);
session_start();

// 0) Record the sign-out before the session is wiped
if (!empty($_SESSION['user'])) {
    try {
        require __DIR__ . '/db.php';
        require_once __DIR__ . '/log_activity.php';
        log_activity($pdo, $_SESSION['user'], 'Signed out', 'Signed out of the system');
    } catch (Throwable $e) {
        error_log('Sign-out log failed: ' . $e->getMessage());
    }
}

// 1) Wipe all session data
$_SESSION = [];

// 2) Delete the session cookie from the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

// 3) Destroy the session server-side
session_destroy();

// 4) Start a fresh session just to carry the flash message
session_start();
session_regenerate_id(true);
$_SESSION['flash'] = [
    'type' => 'success',
    'text' => 'You have been signed out.',
];

// 5) Back to the login page
header('Location: index.php');
exit;