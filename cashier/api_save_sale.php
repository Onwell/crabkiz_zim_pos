<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../log_activity.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'Cashier') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Generate Receipt No
    $last = $pdo->query("SELECT receipt_no FROM sales ORDER BY id DESC LIMIT 1")->fetchColumn();
    $nextNum = $last ? ((int)str_replace('R-', '', $last)) + 1 : 10497;
    $receiptNo = 'R-' . $nextNum;

    // Insert Sale
    $stmt = $pdo->prepare("INSERT INTO sales (receipt_no, cashier_name, customer, total_amount, cash_received, change_given, payment_method) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $receiptNo,
        $_SESSION['user']['full_name'],
        $input['customer'] ?: 'Walk-in customer',
        $input['total'],
        $input['cash'] ?? null,
        $input['change'] ?? null,
        $input['pay']
    ]);
    $saleId = $pdo->lastInsertId();

    // Insert Items & Update Stock
    $stmtItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, qty, price) VALUES (?, ?, ?, ?)");
    $stmtStock = $pdo->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?");

    foreach ($input['items'] as $item) {
        $stmtItem->execute([$saleId, $item['id'], $item['qty'], $item['price']]);
        $stmtStock->execute([$item['qty'], $item['id']]);
    }

    $pdo->commit();
    log_activity($pdo, $_SESSION['user'], 'Sale completed', 'Receipt ' . $receiptNo . ' · $' . number_format((float)$input['total'], 2) . ' · ' . count($input['items']) . ' line(s)');
    echo json_encode(['success' => true, 'receipt_no' => $receiptNo]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'Failed to save sale: ' . $e->getMessage()]);
}