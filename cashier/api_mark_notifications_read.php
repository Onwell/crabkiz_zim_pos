<?php
declare(strict_types=1);
define('GUARD_JSON', true);
require __DIR__ . '/cashier_guard.php';   // sign-in, Cashier role, idle timeout, CSRF
require __DIR__ . '/../db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$input = json_decode((string)file_get_contents('php://input'), true);
$ids = (is_array($input) && is_array($input['ids'] ?? null)) ? $input['ids'] : [];
$ids = array_values(array_unique(array_filter(array_map('intval', array_slice($ids, 0, 100)), function ($i) { return $i > 0; })));

try {
    $u = $_SESSION['user'];
    $uid = isset($u['id']) ? (int)$u['id'] : 0;
    if ($uid <= 0 && !empty($u['email'])) {
        $q = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $q->execute([(string)$u['email']]);
        $uid = (int)($q->fetchColumn() ?: 0);
    }
    if ($uid <= 0) {
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    // Only marks notifications that really exist (no foreign-key errors from made-up ids)
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO stock_notification_reads (user_id, notification_id)
         SELECT ?, id FROM stock_notifications WHERE id = ?"
    );
    foreach ($ids as $id) {
        $stmt->execute([$uid, $id]);
    }
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('Mark notifications read failed: ' . $e->getMessage());
    echo json_encode(['error' => 'Could not update notifications']);
}
