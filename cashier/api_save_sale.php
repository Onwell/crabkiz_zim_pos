<?php
declare(strict_types=1);
define('GUARD_JSON', true);
require __DIR__ . '/cashier_guard.php';   // sign-in, Cashier role, idle timeout, CSRF
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../log_activity.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function sale_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    sale_fail('Invalid payload');
}

/* ---------- Validate what the browser sent (nothing here is trusted for money) ---------- */
$rawItems = $input['items'] ?? null;
if (!is_array($rawItems) || !$rawItems || count($rawItems) > 100) {
    sale_fail('The sale has no valid items.');
}

$customer = isset($input['customer']) && is_string($input['customer']) ? trim($input['customer']) : '';
$customer = $customer === '' ? 'Walk-in customer' : mb_substr($customer, 0, 120);

$pay = isset($input['pay']) && is_string($input['pay']) ? $input['pay'] : 'Cash';
if (!in_array($pay, ['Cash', 'Card', 'Mobile money'], true)) {
    $pay = 'Cash';
}

if (!isset($input['total']) || !is_numeric($input['total'])) {
    sale_fail('Invalid payload');
}
$clientTotal = round((float)$input['total'], 2);

$cashRaw = $input['cash'] ?? null;
$cash    = is_numeric($cashRaw) ? round((float)$cashRaw, 2) : null;

$lines = [];
foreach ($rawItems as $it) {
    if (!is_array($it)) {
        sale_fail('The sale has an invalid item.');
    }
    $pid   = isset($it['id'])  && is_numeric($it['id'])  ? (int)$it['id']  : 0;
    $qty   = isset($it['qty']) && is_numeric($it['qty']) ? (int)$it['qty'] : 0;
    $color = isset($it['color']) && is_scalar($it['color']) ? trim((string)$it['color']) : '';
    $size  = isset($it['size'])  && is_scalar($it['size'])  ? trim((string)$it['size'])  : '';
    if ($pid < 1 || $qty < 1 || $qty > 100000 || $color === '' || $size === '') {
        sale_fail('The sale has an invalid item.');
    }
    $lines[] = ['pid' => $pid, 'qty' => $qty, 'color' => $color, 'size' => $size];
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    $abort = static function (string $msg) use ($pdo): void {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sale_fail($msg);
    };

    $selProd = $pdo->prepare('SELECT id, item_no, size, price FROM products WHERE id = ? LIMIT 1 FOR UPDATE');
    $selVar  = $pdo->prepare('SELECT price FROM stock_items WHERE LOWER(sku) = LOWER(?) AND LOWER(color) = LOWER(?) AND LOWER(size) = LOWER(?) LIMIT 1');

    /* ---------- Price every line from the database, never from the browser ---------- */
    $priced = [];
    $need   = [];
    $total  = 0.0;
    foreach ($lines as $ln) {
        $selProd->execute([$ln['pid']]);
        $p = $selProd->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            $abort('One of the items no longer exists. Refresh and try again.');
        }
        $selVar->execute([(string)$p['item_no'], $ln['color'], $ln['size']]);
        $vp    = $selVar->fetchColumn();
        $price = $vp !== false ? round((float)$vp, 2) : round((float)$p['price'], 2);

        $key = strtolower((string)$p['item_no'] . '|' . $ln['color'] . '|' . $ln['size']);
        $need[$key] = ($need[$key] ?? 0) + $ln['qty'];
        $total += round($ln['qty'] * $price, 2);
        $priced[] = ['pid' => $ln['pid'], 'qty' => $ln['qty'], 'price' => $price];
    }
    $total = round($total, 2);

    if (abs($total - $clientTotal) > 0.01) {
        $abort('Prices have changed since you added these items. Refresh the page and try again.');
    }

    /* ---------- The sale must match the stock this cashier just reserved ---------- */
    $pend = $_SESSION['pending_reserve'] ?? null;
    if (!is_array($pend) || !empty($pend['sold']) || empty($pend['items']) || !is_array($pend['items'])) {
        $abort('Stock was not reserved for this sale. Please try again.');
    }
    $have = [];
    foreach ($pend['items'] as $r) {
        $k = strtolower((string)$r['sku'] . '|' . (string)$r['color'] . '|' . (string)$r['size']);
        $have[$k] = ($have[$k] ?? 0) + (int)$r['qty'];
    }
    ksort($have);
    ksort($need);
    if ($have !== $need) {
        $abort('The sale does not match the reserved stock. Please try again.');
    }

    if ($cash !== null && ($cash < $total || $cash > 99999999.99)) {
        $abort('Cash received is less than the total.');
    }
    $change = $cash !== null ? round($cash - $total, 2) : null;

    /* ---------- Receipt number (row is locked so two sales can't get the same number) ---------- */
    $last = $pdo->query('SELECT receipt_no FROM sales ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
    $nextNum = ($last !== false && preg_match('/(\d+)$/', (string)$last, $m)) ? ((int)$m[1]) + 1 : 10497;
    $receiptNo = 'R-' . $nextNum;

    $stmt = $pdo->prepare('INSERT INTO sales (receipt_no, cashier_name, customer, total_amount, cash_received, change_given, payment_method) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $receiptNo,
        (string)($_SESSION['user']['full_name'] ?? ''),
        $customer,
        $total,
        $cash,
        $change,
        $pay,
    ]);
    $saleId = (int)$pdo->lastInsertId();

    $stmtItem  = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, qty, price) VALUES (?, ?, ?, ?)');
    $stmtStock = $pdo->prepare('UPDATE products SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ?');
    foreach ($priced as $ln) {
        $stmtItem->execute([$saleId, $ln['pid'], $ln['qty'], $ln['price']]);
        $stmtStock->execute([$ln['qty'], $ln['pid']]);
    }

    $pdo->commit();

    // The reservation is now used up; the next step ("attach") may add this receipt's colour/size lines once
    $_SESSION['pending_reserve']['sold'] = true;
    $_SESSION['pending_attach'] = $receiptNo;

    try {
        log_activity($pdo, $_SESSION['user'], 'Sale completed', 'Receipt ' . $receiptNo . ' · $' . number_format($total, 2) . ' · ' . count($priced) . ' line(s)');
    } catch (Throwable $e) {
        error_log('Sale logged failed: ' . $e->getMessage());
    }
    echo json_encode(['success' => true, 'receipt_no' => $receiptNo, 'total' => $total]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Save sale failed: ' . $e->getMessage());
    sale_fail('Could not save the sale. Please try again.', 500);
}
