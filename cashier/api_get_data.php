<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../db.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'Cashier') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get Stock
$stmt = $pdo->query("SELECT id, item_no, name, size, price, stock_qty, initial_qty FROM products ORDER BY id ASC");
$stock = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stock is determined by what the admin has uploaded: only show products whose item no. is in stock_items
try {
    $uploaded = array_map('strtolower', $pdo->query("SELECT sku FROM stock_items")->fetchAll(PDO::FETCH_COLUMN));
    $stock = array_values(array_filter($stock, function ($p) use ($uploaded) {
        return in_array(strtolower((string)$p['item_no']), $uploaded, true);
    }));
} catch (Throwable $e) {
    error_log('Cashier stock filter failed: ' . $e->getMessage());
}

// Get Sales + Items
$stmt = $pdo->query("SELECT * FROM sales ORDER BY id DESC");
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

$salesWithItems = [];
foreach ($sales as $sale) {
    $stmtItems = $pdo->prepare("SELECT si.qty, si.price, p.name, p.item_no, p.size FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?");
    $stmtItems->execute([$sale['id']]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    
    $sale['items'] = $items;
    $sale['no'] = $sale['receipt_no'];
    $sale['date'] = $sale['created_at'];
    $sale['pay'] = $sale['payment_method'];
    $sale['cashier'] = $sale['cashier_name'];
    
    $salesWithItems[] = $sale;
}

// --- NEW: Get real sales total for the past 6 days (excluding today) ---
$hist = [];
for ($i = 6; $i >= 1; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE DATE(created_at) = ?");
    $stmt->execute([$date]);
    $hist[] = (float)$stmt->fetchColumn();
}

// --- Unread "new stock uploaded" notifications for this cashier ---
$notifications = [];
try {
    $u = $_SESSION['user'];
    $uid = isset($u['id']) ? (int)$u['id'] : 0;
    if ($uid <= 0 && !empty($u['email'])) {
        $q = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $q->execute([(string)$u['email']]);
        $uid = (int)($q->fetchColumn() ?: 0);
    }
    if ($uid > 0) {
        $q = $pdo->prepare(
            "SELECT n.id, COALESCE(up.full_name, n.uploaded_by_name) AS uploaded_by_name, n.pairs, n.summary, n.created_at
               FROM stock_notifications n
               LEFT JOIN users up ON up.id = n.uploaded_by
               LEFT JOIN stock_notification_reads r ON r.notification_id = n.id AND r.user_id = ?
              WHERE r.notification_id IS NULL
              ORDER BY n.id DESC
              LIMIT 20"
        );
        $q->execute([$uid]);
        $notifications = $q->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('Cashier notifications failed: ' . $e->getMessage());
}

echo json_encode(['stock' => $stock, 'sales' => $salesWithItems, 'hist' => $hist, 'notifications' => $notifications]);