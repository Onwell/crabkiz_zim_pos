<?php
// log_activity.php — shared helper (project root, next to db.php and logout.php)
declare(strict_types=1);

/**
 * Save one activity entry to the `activity_log` table.
 * Never throws: a logging problem must not break the page or the action being logged.
 */
function log_activity(PDO $pdo, array $user, string $action, string $details = ''): void
{
    try {
        $uid = isset($user['id']) ? (int)$user['id'] : 0;
        if ($uid <= 0 && !empty($user['email'])) {
            $q = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $q->execute([(string)$user['email']]);
            $uid = (int)($q->fetchColumn() ?: 0);
        }

        $name = trim((string)($user['full_name'] ?? 'Unknown'));
        $role = (string)($user['role'] ?? '');
        $ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        $stmt = $pdo->prepare(
            'INSERT INTO activity_log (user_id, user_name, user_role, action, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $uid > 0 ? $uid : null,
            mb_substr($name, 0, 120),
            mb_substr($role, 0, 20),
            mb_substr($action, 0, 60),
            $details !== '' ? mb_substr($details, 0, 500) : null,
            $ip !== '' ? substr($ip, 0, 45) : null,
        ]);
    } catch (Throwable $e) {
        error_log('log_activity failed: ' . $e->getMessage());
    }
}
