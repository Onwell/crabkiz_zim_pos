<?php
// logout.php — sign out safely
declare(strict_types=1);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$cookie = [
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Strict',
];
session_set_cookie_params($cookie);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

// 1) Wipe all session data (user, CSRF token, any pending sale reservation)
$_SESSION = [];

// 2) Delete the session cookie from the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

// 3) Destroy the session server-side
session_destroy();

// 4) Start a fresh session just to carry the flash message
session_set_cookie_params($cookie);
session_start();
session_regenerate_id(true);
$_SESSION['flash'] = [
    'type' => 'success',
    'text' => 'You have been signed out.',
];

// 5) Back to the login page (and don't let the browser cache signed-in pages)
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: index.php');
exit;
