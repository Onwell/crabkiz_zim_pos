<?php
// admin/index.php — Admin dashboard
declare(strict_types=1);
session_start();

// 1) Must be signed in
if (empty($_SESSION['user'])) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'Please sign in first.'];
    header('Location: ../index.php');
    exit;
}

// 2) Must be an Admin
if ($_SESSION['user']['role'] !== 'Admin') {
    header('Location: ../cashier/index.php');
    exit;
}

// Pull signed-in user details for display
$user      = $_SESSION['user'];
$userName  = $user['full_name'] ?? 'Admin';
$firstName = preg_split('/\s+/', trim($userName))[0] ?? 'Admin';
$initials  = strtoupper(
    substr($userName, 0, 1) .
    (strpos(trim($userName), ' ') !== false
        ? substr(strrchr(trim($userName), ' '), 1, 1)
        : '')
);

// One-time login banner from the login flow
$loginMsg = $_SESSION['login_ok'] ?? '';
unset($_SESSION['login_ok']);

/* ---------- Database connection (shared with the cashier: ../db.php provides $pdo) ---------- */
function crabkidz_db(): PDO
{
    static $conn = null;
    if ($conn instanceof PDO) {
        return $conn;
    }
    require __DIR__ . '/../db.php';
    $conn = $pdo;
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $conn;
}

function crabkidz_variant_key(string $sku, string $color, string $size): string
{
    return mb_strtolower(trim($sku)) . '|' . mb_strtolower(trim($color)) . '|' . mb_strtolower(trim($size));
}

function crabkidz_ensure_products_index(PDO $pdo): void
{
    // One item no. must be able to hold many sizes: unique on (item_no, size), not item_no alone.
    try {
        $idx = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'item_no'")->fetchAll();
        if ($idx) {
            $pdo->exec('ALTER TABLE products DROP INDEX item_no');
            $has = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'item_size'")->fetchAll();
            if (!$has) {
                $pdo->exec('ALTER TABLE products ADD UNIQUE KEY item_size (item_no, size)');
            }
        }
    } catch (Throwable $e) {
        error_log('Crabkidz products index fix failed: ' . $e->getMessage());
    }
}

function crabkidz_ensure_notification_tables(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS restock_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_no VARCHAR(40) NOT NULL,
        name VARCHAR(120) NOT NULL,
        color VARCHAR(40) NOT NULL,
        size VARCHAR(20) NOT NULL,
        current_qty INT NOT NULL DEFAULT 0,
        requested_by_id INT NULL,
        requested_by_name VARCHAR(120) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT \'pending\',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_notification_reads (
        user_id INT NOT NULL,
        request_id INT NOT NULL,
        read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, request_id),
        KEY idx_req (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/* ---------- Live data: stock + sales made by cashiers + restock notifications ---------- */
function crabkidz_live_data(PDO $pdo, int $adminId = 0): array
{
    $out = [
        'stock'         => [],
        'sales'         => [],
        'hist'          => [0, 0, 0, 0, 0, 0],
        'months'        => [],
        'notifications' => [],
        'serverTime'    => date('H:i:s'),
    ];

    // Stock saved in stock_items
    try {
        $rows = $pdo->query(
            'SELECT s.id, s.sku, s.name, s.gender, s.color, s.size, s.qty, s.cost, s.price,
                    COALESCE(u.full_name, s.uploaded_by_name) AS uploaded_by
               FROM stock_items s
               LEFT JOIN users u ON u.id = s.uploaded_by
              ORDER BY s.updated_at DESC, s.id DESC'
        )->fetchAll();

        foreach ($rows as $r) {
            $out['stock'][] = [
                'id'     => (int)$r['id'],
                'sku'    => (string)$r['sku'],
                'name'   => (string)$r['name'],
                'gender' => (string)$r['gender'],
                'color'  => (string)$r['color'],
                'size'   => (string)$r['size'],
                'qty'    => max(0, (int)$r['qty']),
                'cost'   => (float)$r['cost'],
                'price'  => (float)$r['price'],
                'by'     => (string)($r['uploaded_by'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        error_log('Crabkidz stock read failed: ' . $e->getMessage());
        $out['stockError'] = 'Could not load saved stock from the database.';
    }

    // Sales rung up by cashiers
    try {
        $sales = $pdo->query(
            'SELECT id, receipt_no, cashier_name, customer, total_amount, payment_method, created_at
               FROM sales ORDER BY id DESC LIMIT 500'
        )->fetchAll();

        $byId = [];
        $byReceiptVariants = [];
        if ($sales) {
            $ids = array_map(static function ($r) { return (int)$r['id']; }, $sales);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $q = $pdo->prepare(
                "SELECT si.sale_id, si.qty, si.price, p.name, p.item_no, p.size
                   FROM sale_items si JOIN products p ON si.product_id = p.id
                  WHERE si.sale_id IN ($in)"
            );
            $q->execute($ids);
            foreach ($q->fetchAll() as $it) {
                $byId[(int)$it['sale_id']][] = [
                    'name'    => (string)$it['name'],
                    'item_no' => (string)$it['item_no'],
                    'color'   => '',
                    'size'    => (string)$it['size'],
                    'qty'     => (int)$it['qty'],
                    'price'   => (float)$it['price'],
                ];
            }

            try {
                $vRows = $pdo->query(
                    'SELECT receipt_no, item_no, name, color, size, qty, price FROM sale_variants ORDER BY id ASC'
                )->fetchAll();
                foreach ($vRows as $vr) {
                    $byReceiptVariants[(string)$vr['receipt_no']][] = [
                        'name'    => (string)$vr['name'],
                        'item_no' => (string)$vr['item_no'],
                        'color'   => (string)$vr['color'],
                        'size'    => (string)$vr['size'],
                        'qty'     => (int)$vr['qty'],
                        'price'   => (float)$vr['price'],
                    ];
                }
            } catch (Throwable $e) {
                error_log('Crabkidz sale_variants read failed: ' . $e->getMessage());
            }
        }

        $todayStr = date('Y-m-d');
        foreach ($sales as $r) {
            $recNo   = (string)$r['receipt_no'];
            $ts      = strtotime((string)$r['created_at']);
            $isToday = substr((string)$r['created_at'], 0, 10) === $todayStr;
            $items   = !empty($byReceiptVariants[$recNo]) ? $byReceiptVariants[$recNo] : ($byId[(int)$r['id']] ?? []);
            $out['sales'][] = [
                'id'       => $recNo,
                'time'     => $isToday ? date('H:i', $ts) : date('d M H:i', $ts),
                'today'    => $isToday,
                'customer' => (string)($r['customer'] ?? ''),
                'cashier'  => (string)($r['cashier_name'] ?? ''),
                'pay'      => (string)($r['payment_method'] ?? ''),
                'total'    => (float)$r['total_amount'],
                'items'    => $items,
            ];
        }

        // Totals for the 6 days before today
        $q = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE DATE(created_at) = ?');
        for ($i = 6; $i >= 1; $i--) {
            $q->execute([date('Y-m-d', strtotime("-$i days"))]);
            $out['hist'][6 - $i] = (float)$q->fetchColumn();
        }

        // Last 3 months (oldest first)
        $q = $pdo->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE created_at >= ? AND created_at < ?');
        for ($m = 2; $m >= 0; $m--) {
            $start = new DateTime('first day of this month 00:00:00');
            $start->modify("-$m month");
            $end = (clone $start)->modify('+1 month');
            $q->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
            $out['months'][] = ['label' => $start->format('M'), 'total' => (float)$q->fetchColumn()];
        }
    } catch (Throwable $e) {
        error_log('Crabkidz sales read failed: ' . $e->getMessage());
        $out['salesError'] = 'Could not load sales from the database.';
    }

    // Restock request notifications from cashiers
    try {
        crabkidz_ensure_notification_tables($pdo);
        if ($adminId > 0) {
            $stmt = $pdo->prepare(
                'SELECT r.id, r.item_no, r.name, r.color, r.size, r.current_qty,
                        r.requested_by_name, r.status, r.created_at,
                        CASE WHEN ar.request_id IS NOT NULL THEN 1 ELSE 0 END AS is_read
                   FROM restock_requests r
                   LEFT JOIN admin_notification_reads ar
                          ON ar.request_id = r.id AND ar.user_id = ?
                  ORDER BY r.id DESC'
            );
            $stmt->execute([$adminId]);
        } else {
            $stmt = $pdo->query(
                'SELECT id, item_no, name, color, size, current_qty,
                        requested_by_name, status, created_at, 0 AS is_read
                   FROM restock_requests
                  ORDER BY id DESC'
            );
        }

        foreach ($stmt->fetchAll() as $nr) {
            $out['notifications'][] = [
                'id'                => (int)$nr['id'],
                'item_no'           => (string)$nr['item_no'],
                'name'              => (string)$nr['name'],
                'color'             => (string)$nr['color'],
                'size'              => (string)$nr['size'],
                'current_qty'       => (int)$nr['current_qty'],
                'requested_by_name' => (string)$nr['requested_by_name'],
                'status'            => (string)$nr['status'],
                'created_at'        => (string)$nr['created_at'],
                'is_read'           => (int)$nr['is_read'] === 1,
            ];
        }
    } catch (Throwable $e) {
        error_log('Crabkidz notifications read failed: ' . $e->getMessage());
    }

    return $out;
}

require_once __DIR__ . '/../log_activity.php';

function crabkidz_current_admin_id(PDO $pdo, array $user): int
{
    $uid = isset($user['id']) && is_numeric($user['id']) ? (int)$user['id'] : 0;
    if ($uid <= 0 && !empty($user['email'])) {
        $q = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $q->execute([(string)$user['email']]);
        $uid = (int)($q->fetchColumn() ?: 0);
    }
    return $uid;
}

/* ---------- Small JSON helper ---------- */
function crabkidz_json(array $payload, int $code = 200): void
{
    if ($code !== 200) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}


/* ---------- AJAX POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $in = json_decode((string)file_get_contents('php://input'), true);

    // Live refresh: stock + sales + notifications
    if (is_array($in) && ($in['action'] ?? '') === 'get_data') {
        try {
            $pdo = crabkidz_db();
            $uid = crabkidz_current_admin_id($pdo, $user);
            echo json_encode(crabkidz_live_data($pdo, $uid));
        } catch (Throwable $e) {
            error_log('Crabkidz get_data failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not load data.']);
        }
        exit;
    }

    // Mark notification as read or unread in DB
    if (is_array($in) && ($in['action'] ?? '') === 'set_notification_read') {
        $reqId  = isset($in['id']) && is_numeric($in['id']) ? (int)$in['id'] : 0;
        $isRead = !empty($in['is_read']);
        if ($reqId < 1) {
            crabkidz_json(['ok' => false, 'message' => 'Invalid notification id.'], 400);
        }
        try {
            $pdo = crabkidz_db();
            crabkidz_ensure_notification_tables($pdo);
            $uid = crabkidz_current_admin_id($pdo, $user);
            if ($uid <= 0) {
                crabkidz_json(['ok' => false, 'message' => 'Could not identify admin user.'], 400);
            }
            if ($isRead) {
                $pdo->prepare(
                    'INSERT IGNORE INTO admin_notification_reads (user_id, request_id) VALUES (?, ?)'
                )->execute([$uid, $reqId]);
            } else {
                $pdo->prepare(
                    'DELETE FROM admin_notification_reads WHERE user_id = ? AND request_id = ?'
                )->execute([$uid, $reqId]);
            }
            crabkidz_json(['ok' => true, 'id' => $reqId, 'is_read' => $isRead]);
        } catch (Throwable $e) {
            error_log('Crabkidz set_notification_read failed: ' . $e->getMessage());
            crabkidz_json(['ok' => false, 'message' => 'Could not update notification status.'], 500);
        }
    }

    // Mark all notifications as read in DB
    if (is_array($in) && ($in['action'] ?? '') === 'mark_all_notifications_read') {
        try {
            $pdo = crabkidz_db();
            crabkidz_ensure_notification_tables($pdo);
            $uid = crabkidz_current_admin_id($pdo, $user);
            if ($uid <= 0) {
                crabkidz_json(['ok' => false, 'message' => 'Could not identify admin user.'], 400);
            }
            $pdo->prepare(
                'INSERT IGNORE INTO admin_notification_reads (user_id, request_id)
                 SELECT ?, id FROM restock_requests'
            )->execute([$uid]);
            crabkidz_json(['ok' => true]);
        } catch (Throwable $e) {
            error_log('Crabkidz mark_all_notifications_read failed: ' . $e->getMessage());
            crabkidz_json(['ok' => false, 'message' => 'Could not mark notifications as read.'], 500);
        }
    }

    // Save an applied stock take so the cashiers see the corrected counts too
    if (is_array($in) && ($in['action'] ?? '') === 'apply_stocktake') {
        $items   = is_array($in['items'] ?? null) ? $in['items'] : [];
        $details = is_string($in['details'] ?? null) ? mb_substr(trim($in['details']), 0, 300) : '';
        try {
            $pdo = crabkidz_db();
            $pdo->beginTransaction();

            $uItemById      = $pdo->prepare('UPDATE stock_items SET qty = ? WHERE id = ?');
            $uItemByVariant = $pdo->prepare('UPDATE stock_items SET qty = ? WHERE LOWER(sku) = LOWER(?) AND LOWER(color) = LOWER(?) AND LOWER(size) = LOWER(?)');
            $uProdByVariant = $pdo->prepare('UPDATE products SET stock_qty = ? WHERE LOWER(item_no) = LOWER(?) AND LOWER(size) = LOWER(?)');
            $uProdBySku     = $pdo->prepare('UPDATE products SET stock_qty = ? WHERE LOWER(item_no) = LOWER(?)');

            $n = 0;
            foreach ($items as $it) {
                if (!is_array($it) || !isset($it['counted']) || !is_numeric($it['counted'])) {
                    continue;
                }
                $counted = (int)$it['counted'];
                if ($counted < 0) {
                    continue;
                }

                $id    = isset($it['id']) && is_numeric($it['id']) ? (int)$it['id'] : 0;
                $sku   = isset($it['sku']) && is_string($it['sku']) ? trim($it['sku']) : '';
                $color = isset($it['color']) && is_string($it['color']) ? trim($it['color']) : '';
                $size  = isset($it['size']) && is_string($it['size']) ? trim($it['size']) : '';

                if ($id > 0) {
                    $uItemById->execute([$counted, $id]);
                } elseif ($sku !== '' && $color !== '' && $size !== '') {
                    $uItemByVariant->execute([$counted, $sku, $color, $size]);
                } elseif ($sku !== '') {
                    $uProdBySku->execute([$counted, $sku]);
                }

                if ($sku !== '' && $size !== '') {
                    $uProdByVariant->execute([$counted, $sku, $size]);
                } elseif ($sku !== '') {
                    $uProdBySku->execute([$counted, $sku]);
                }

                $n++;
            }
            if ($n === 0) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'message' => 'No valid counts to save.']);
                exit;
            }
            $pdo->commit();
            log_activity($pdo, $user, 'Stock take applied', $details !== '' ? $details : ($n . ' lines counted'));
            echo json_encode(['ok' => true, 'saved' => $n]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Crabkidz apply_stocktake failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Could not save the stock take to the database.']);
        }
        exit;
    }

    // Stock take events recorded from the browser (only these fixed events are accepted)
    if (is_array($in) && ($in['action'] ?? '') === 'log_activity') {
        $event   = is_string($in['event'] ?? null) ? $in['event'] : '';
        $details = is_string($in['details'] ?? null) ? mb_substr(trim($in['details']), 0, 300) : '';
        if (!in_array($event, ['Weekly stock take run'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false]);
            exit;
        }
        try {
            log_activity(crabkidz_db(), $user, $event, $details);
        } catch (Throwable $e) {
            error_log('Crabkidz log_activity failed: ' . $e->getMessage());
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    /* ----- Edit one stock line (keeps the cashier's products table in step) ----- */
    if (is_array($in) && ($in['action'] ?? '') === 'edit_stock') {
        $get = static function (string $k) use ($in): string {
            return (isset($in[$k]) && is_scalar($in[$k])) ? trim((string)$in[$k]) : '';
        };
        $id     = isset($in['id']) && is_numeric($in['id']) ? (int)$in['id'] : 0;
        $name   = $get('name');
        $gender = $get('gender') !== '' ? $get('gender') : 'Unisex';
        $color  = $get('color')  !== '' ? $get('color')  : 'Black';
        $size   = $get('size');
        $cost   = $in['cost']  ?? null;
        $price  = $in['price'] ?? null;
        $qtyRaw = $in['qty']   ?? null;

        if ($id < 1 || $name === '' || $size === ''
            || !is_numeric($qtyRaw) || (int)$qtyRaw < 0 || (int)$qtyRaw > 100000
            || !is_numeric($cost) || !is_numeric($price)
            || (float)$cost < 0 || (float)$price < 0
            || (float)$cost > 99999999.99 || (float)$price > 99999999.99
            || mb_strlen($name) > 120 || mb_strlen($size) > 25
            || mb_strlen($gender) > 20 || mb_strlen($color) > 40) {
            crabkidz_json(['ok' => false, 'message' => 'Check the details and try again. Name, size, stock, cost and price are required.']);
        }
        $qty   = (int)$qtyRaw;
        $cost  = round((float)$cost, 2);
        $price = round((float)$price, 2);

        try {
            $pdo = crabkidz_db();
            crabkidz_ensure_products_index($pdo);
            $pdo->beginTransaction();

            $sel = $pdo->prepare('SELECT id, sku, name, color, size FROM stock_items WHERE id = ? LIMIT 1');
            $sel->execute([$id]);
            $old = $sel->fetch();
            if (!$old) {
                $pdo->rollBack();
                crabkidz_json(['ok' => false, 'message' => 'That stock line no longer exists. Refresh the page and try again.']);
            }
            $sku = (string)$old['sku'];

            $dup = $pdo->prepare('SELECT id FROM stock_items WHERE LOWER(sku) = LOWER(?) AND LOWER(color) = LOWER(?) AND LOWER(size) = LOWER(?) AND id <> ? LIMIT 1');
            $dup->execute([$sku, $color, $size, $id]);
            if ($dup->fetchColumn() !== false) {
                $pdo->rollBack();
                crabkidz_json(['ok' => false, 'message' => 'Item no. ' . $sku . ' already has a ' . $color . ' line in size ' . $size . '.']);
            }

            $pdo->prepare('UPDATE stock_items SET name = ?, gender = ?, color = ?, size = ?, qty = ?, cost = ?, price = ? WHERE id = ?')
                ->execute([$name, $gender, $color, $size, $qty, $cost, $price, $id]);

            $renamed = mb_strtolower((string)$old['name']) !== mb_strtolower($name);
            if ($renamed) {
                $pdo->prepare('UPDATE stock_items SET name = ? WHERE LOWER(sku) = LOWER(?)')->execute([$name, $sku]);
                $pdo->prepare('UPDATE products SET name = ? WHERE LOWER(item_no) = LOWER(?)')->execute([$name, $sku]);
            }

            $oldSize     = (string)$old['size'];
            $sizeChanged = mb_strtolower($oldSize) !== mb_strtolower($size);

            $pFind = $pdo->prepare('SELECT id FROM products WHERE LOWER(item_no) = LOWER(?) AND LOWER(size) = LOWER(?) LIMIT 1');
            $pFind->execute([$sku, $oldSize]);
            $pOld = $pFind->fetchColumn();
            $pNew = $pOld;
            if ($sizeChanged) {
                $pFind->execute([$sku, $size]);
                $pNew = $pFind->fetchColumn();
            }

            $others = $pdo->prepare('SELECT COUNT(*) FROM stock_items WHERE LOWER(sku) = LOWER(?) AND LOWER(size) = LOWER(?) AND id <> ?');
            $others->execute([$sku, $oldSize, $id]);
            $othersUseOldSize = (int)$others->fetchColumn() > 0;

            $target = $pOld;
            if ($sizeChanged) {
                if ($pNew === false && $pOld !== false && !$othersUseOldSize) {
                    $target = $pOld;
                } else {
                    $target = $pNew;
                    if ($pOld !== false && $pNew !== false && !$othersUseOldSize) {
                        $pdo->prepare('UPDATE products SET stock_qty = 0 WHERE id = ?')->execute([(int)$pOld]);
                    }
                }
            }

            if ($target !== false) {
                $pdo->prepare('UPDATE products SET name = ?, size = ?, price = ?, stock_qty = ? WHERE id = ?')
                    ->execute([$name, $size, $price, $qty, (int)$target]);
            } else {
                $pdo->prepare('INSERT INTO products (item_no, name, size, price, stock_qty, initial_qty) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$sku, $name, $size, $price, $qty, $qty]);
            }

            $pdo->commit();
            log_activity($pdo, $user, 'Stock edited', $qty . ' × ' . $name . ' (' . $color . ', size ' . $size . ', $' . number_format($price, 2) . ') [item ' . $sku . ']');
            crabkidz_json(['ok' => true, 'id' => $id, 'sku' => $sku, 'renamed' => $renamed]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Crabkidz edit_stock failed: ' . $e->getMessage());
            crabkidz_json(['ok' => false, 'message' => 'Could not save the changes to the database.'], 500);
        }
    }

    /* ----- Delete one stock line ----- */
    if (is_array($in) && ($in['action'] ?? '') === 'delete_stock') {
        $id = isset($in['id']) && is_numeric($in['id']) ? (int)$in['id'] : 0;
        if ($id < 1) {
            crabkidz_json(['ok' => false, 'message' => 'Invalid request.'], 400);
        }
        try {
            $pdo = crabkidz_db();
            $pdo->beginTransaction();

            $sel = $pdo->prepare('SELECT id, sku, name, color, size FROM stock_items WHERE id = ? LIMIT 1');
            $sel->execute([$id]);
            $old = $sel->fetch();
            if (!$old) {
                $pdo->rollBack();
                crabkidz_json(['ok' => false, 'message' => 'That stock line no longer exists. Refresh the page.']);
            }

            $pdo->prepare('DELETE FROM stock_items WHERE id = ?')->execute([$id]);

            $others = $pdo->prepare('SELECT COUNT(*) FROM stock_items WHERE LOWER(sku) = LOWER(?) AND LOWER(size) = LOWER(?)');
            $others->execute([(string)$old['sku'], (string)$old['size']]);
            if ((int)$others->fetchColumn() === 0) {
                $pFind = $pdo->prepare('SELECT id FROM products WHERE LOWER(item_no) = LOWER(?) AND LOWER(size) = LOWER(?) LIMIT 1');
                $pFind->execute([(string)$old['sku'], (string)$old['size']]);
                $pid = $pFind->fetchColumn();
                if ($pid !== false) {
                    $used = $pdo->prepare('SELECT COUNT(*) FROM sale_items WHERE product_id = ?');
                    $used->execute([(int)$pid]);
                    if ((int)$used->fetchColumn() === 0) {
                        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([(int)$pid]);
                    } else {
                        $pdo->prepare('UPDATE products SET stock_qty = 0 WHERE id = ?')->execute([(int)$pid]);
                    }
                }
            }

            $pdo->commit();
            log_activity($pdo, $user, 'Stock deleted', $old['name'] . ' (' . $old['color'] . ', size ' . $old['size'] . ') [item ' . $old['sku'] . ']');
            crabkidz_json(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Crabkidz delete_stock failed: ' . $e->getMessage());
            crabkidz_json(['ok' => false, 'message' => 'Could not delete the stock line from the database.'], 500);
        }
    }

    if (!is_array($in) || ($in['action'] ?? '') !== 'add_stock' || !is_array($in['items'] ?? null) || count($in['items']) > 500) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $uploaderName = trim((string)($user['full_name'] ?? 'Admin'));
    $uploaderId   = isset($user['id']) ? (int)$user['id'] : 0;

    $clean   = [];
    $skipped = 0;
    foreach ($in['items'] as $it) {
        if (!is_array($it)) { $skipped++; continue; }
        $get = static function (string $k) use ($it): string {
            return (isset($it[$k]) && is_scalar($it[$k])) ? trim((string)$it[$k]) : '';
        };
        $sku    = $get('sku');
        $name   = $get('name');
        $size   = $get('size');
        $gender = $get('gender') !== '' ? $get('gender') : 'Unisex';
        $color  = $get('color')  !== '' ? $get('color')  : 'Black';
        $cost   = $it['cost']  ?? null;
        $price  = $it['price'] ?? null;
        $qtyRaw = $it['qty']   ?? 1;
        $qty    = is_numeric($qtyRaw) ? (int)$qtyRaw : 0;

        if ($sku === '' || $name === '' || $size === ''
            || $qty < 1 || $qty > 100000
            || !is_numeric($cost) || !is_numeric($price)
            || (float)$cost < 0 || (float)$price < 0
            || (float)$cost > 99999999.99 || (float)$price > 99999999.99
            || mb_strlen($sku) > 40 || mb_strlen($name) > 120 || mb_strlen($size) > 25
            || mb_strlen($gender) > 20 || mb_strlen($color) > 40) {
            $skipped++;
            continue;
        }
        $clean[] = [
            'sku'    => $sku,
            'name'   => $name,
            'size'   => $size,
            'gender' => $gender,
            'color'  => $color,
            'qty'    => $qty,
            'cost'   => round((float)$cost, 2),
            'price'  => round((float)$price, 2),
        ];
    }

    if (!$clean) {
        echo json_encode(['ok' => false, 'message' => 'Nothing was saved. Check the item details and try again.']);
        exit;
    }

    try {
        $pdo = crabkidz_db();

        if ($uploaderId <= 0 && !empty($user['email'])) {
            $q = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $q->execute([(string)$user['email']]);
            $uploaderId = (int)($q->fetchColumn() ?: 0);
        }
        $uploaderIdOrNull = $uploaderId > 0 ? $uploaderId : null;

        crabkidz_ensure_products_index($pdo);

        $pdo->beginTransaction();

        $skuNameLookup = $pdo->prepare('SELECT name FROM stock_items WHERE LOWER(sku) = LOWER(?) LIMIT 1');
        $prodSkuNameLookup = $pdo->prepare('SELECT name FROM products WHERE LOWER(item_no) = LOWER(?) LIMIT 1');

        $selVariant = $pdo->prepare('SELECT id, name FROM stock_items WHERE LOWER(sku) = LOWER(?) AND LOWER(color) = LOWER(?) AND LOWER(size) = LOWER(?) LIMIT 1');
        $insVariant = $pdo->prepare(
            'INSERT INTO stock_items (sku, name, gender, color, size, qty, cost, price, uploaded_by, uploaded_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $updVariant = $pdo->prepare(
            'UPDATE stock_items
                SET qty = qty + ?,
                    name = ?,
                    gender = ?,
                    cost = ?,
                    price = ?,
                    uploaded_by = ?,
                    uploaded_by_name = ?
              WHERE id = ?'
        );

        $pSelVariant = $pdo->prepare('SELECT id FROM products WHERE LOWER(item_no) = LOWER(?) AND LOWER(size) = LOWER(?) LIMIT 1');
        $pUpdVariant = $pdo->prepare(
            'UPDATE products
                SET name = ?,
                    price = ?,
                    stock_qty = stock_qty + ?,
                    initial_qty = initial_qty + ?
              WHERE id = ?'
        );
        $pInsVariant = $pdo->prepare(
            'INSERT INTO products (item_no, name, size, price, stock_qty, initial_qty)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $batchSkuNames = [];
        $saved = [];

        foreach ($clean as $r) {
            $skuKey = mb_strtolower($r['sku']);

            $expectedName = $batchSkuNames[$skuKey] ?? null;
            if ($expectedName === null) {
                $skuNameLookup->execute([$r['sku']]);
                $dbName = $skuNameLookup->fetchColumn();
                if ($dbName !== false && (string)$dbName !== '') {
                    $expectedName = (string)$dbName;
                } else {
                    $prodSkuNameLookup->execute([$r['sku']]);
                    $pName = $prodSkuNameLookup->fetchColumn();
                    if ($pName !== false && (string)$pName !== '') {
                        $expectedName = (string)$pName;
                    }
                }
            }

            if ($expectedName !== null && mb_strtolower($expectedName) !== mb_strtolower($r['name'])) {
                $skipped++;
                continue;
            }

            $canonicalName = $expectedName ?? $r['name'];
            $r['name'] = $canonicalName;
            $batchSkuNames[$skuKey] = $canonicalName;

            $selVariant->execute([$r['sku'], $r['color'], $r['size']]);
            $existingVar = $selVariant->fetch();

            if ($existingVar) {
                $varId = (int)$existingVar['id'];
                $updVariant->execute([
                    $r['qty'], $canonicalName, $r['gender'],
                    $r['cost'], $r['price'], $uploaderIdOrNull, $uploaderName, $varId
                ]);
                $r['id'] = $varId;
            } else {
                $insVariant->execute([
                    $r['sku'], $canonicalName, $r['gender'], $r['color'], $r['size'],
                    $r['qty'], $r['cost'], $r['price'], $uploaderIdOrNull, $uploaderName,
                ]);
                $r['id'] = (int)$pdo->lastInsertId();
            }

            $pSelVariant->execute([$r['sku'], $r['size']]);
            $pid = $pSelVariant->fetchColumn();
            if ($pid !== false) {
                $pUpdVariant->execute([$canonicalName, $r['price'], $r['qty'], $r['qty'], (int)$pid]);
            } else {
                $pInsVariant->execute([$r['sku'], $canonicalName, $r['size'], $r['price'], $r['qty'], $r['qty']]);
            }

            $r['by'] = $uploaderName;
            $saved[] = $r;
        }

        if (!$saved) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'message' => 'Nothing was saved. Item no. already exists with a different shoe name.']);
            exit;
        }

        $lines = [];
        foreach (array_slice($saved, 0, 10) as $r) {
            $lines[] = $r['qty'] . ' × ' . $r['name'] . ' (' . $r['color'] . ', size ' . $r['size'] . ', $' . number_format((float)$r['price'], 2) . ') [item ' . $r['sku'] . ']';
        }
        if (count($saved) > 10) {
            $lines[] = '…and ' . (count($saved) - 10) . ' more';
        }
        $pdo->prepare(
            'INSERT INTO stock_notifications (uploaded_by, uploaded_by_name, pairs, summary)
             VALUES (?, ?, ?, ?)'
        )->execute([$uploaderIdOrNull, $uploaderName, array_sum(array_column($saved, 'qty')), implode("\n", $lines)]);

        $pdo->commit();
        log_activity($pdo, $user, 'Stock upload', implode('; ', $lines));
        echo json_encode(['ok' => true, 'saved' => $saved, 'skipped' => $skipped, 'uploadedBy' => $uploaderName]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Crabkidz add_stock failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Could not save to the database. Please try again.']);
    }
    exit;
}

/* ---------- Record sign-in once per session ---------- */
if (empty($_SESSION['activity_login_logged'])) {
    try {
        log_activity(crabkidz_db(), $user, 'Signed in', 'Opened the admin dashboard');
        $_SESSION['activity_login_logged'] = true;
    } catch (Throwable $e) {
        error_log('Crabkidz sign-in log failed: ' . $e->getMessage());
    }
}

/* ---------- Load stock + sales + notifications for the first paint ---------- */
$live         = null;
$dbStockError = '';
try {
    $pdo = crabkidz_db();
    $live = crabkidz_live_data($pdo, crabkidz_current_admin_id($pdo, $user));
    $dbStockError = (string)($live['stockError'] ?? $live['salesError'] ?? '');
} catch (Throwable $e) {
    error_log('Crabkidz initial load failed: ' . $e->getMessage());
    $dbStockError = 'Could not connect to the database.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Crabkidz Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@500;700;800&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--orange:#F26B0F;--strong:#C74A00;--tint:#FFE8D4;--bg:#F6F3F0;--surface:#fff;--ink:#241A13;--muted:#6F6259;--line:#E8E1DB;--good:#1F7A4D;--goodbg:#DDF3E6;--bad:#B3261E;--badbg:#FBE0DD;
--display:'Bricolage Grotesque','Trebuchet MS',system-ui,sans-serif;--body:'Figtree',system-ui,-apple-system,'Segoe UI',sans-serif;
box-sizing:border-box;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}
*,*::before,*::after{box-sizing:inherit}
html{scroll-padding-top:env(safe-area-inset-top,0px)}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 var(--body)}
h1,h2,h3{font-family:var(--display);margin:0;letter-spacing:-.01em}
button,input,select{font:inherit;color:inherit}button{cursor:pointer}
:focus-visible{outline:3px solid var(--orange);outline-offset:2px}
.app{display:grid;grid-template-columns:232px 1fr;min-height:100vh}
.side{background:var(--orange);color:#1E0F03;padding:22px 16px;display:flex;flex-direction:column;gap:26px;position:sticky;top:0;height:100vh}
.brand{display:flex;align-items:center;gap:10px;font:800 24px var(--display)}
.brand svg{width:34px;height:34px}
nav{display:flex;flex-direction:column;gap:4px}
nav a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:#1E0F03;text-decoration:none;font-weight:600}
nav a:hover{background:rgba(255,255,255,.25)}
nav a[aria-current=page]{background:#1E0F03;color:#FFE8D4}
nav svg{width:20px;height:20px;flex:none}
.nav-badge{margin-left:auto;background:#1E0F03;color:#FFE8D4;border-radius:999px;padding:1px 8px;font-size:12px;font-weight:700}
nav a[aria-current=page] .nav-badge{background:var(--orange);color:#1E0F03}
.who{margin-top:auto;font-size:14px;display:flex;gap:10px;align-items:center}
.avatar{width:38px;height:38px;border-radius:50%;background:#1E0F03;color:#FFE8D4;display:grid;place-items:center;font-weight:700}
.signout{margin-top:10px;display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:10px;font:600 13.5px/1 var(--body);color:#FFE8D4;background:rgba(30,15,3,.85);border:1.5px solid rgba(30,15,3,.85);cursor:pointer;transition:background .15s ease,color .15s ease,transform .05s ease;}
.signout:hover{background:#1E0F03;border-color:#1E0F03;color:#FFF2E3}
.signout:active{transform:translateY(1px)}
.signout svg{flex:none}
main{padding:28px clamp(16px,3vw,40px) 48px;min-width:0}
.view{display:none}.view.on{display:block}
.top{display:flex;flex-wrap:wrap;gap:14px;justify-content:space-between;align-items:flex-end;margin-bottom:22px}
.top h1{font-size:clamp(26px,4vw,36px);font-weight:800}
.top p{margin:4px 0 0;color:var(--muted)}
.btn{border:1px solid var(--line);background:var(--surface);padding:10px 16px;border-radius:10px;font-weight:600}
.btn.primary{background:var(--strong);border-color:var(--strong);color:#fff}
.btn.primary:hover{filter:brightness(1.1)}
.btn.ghost{background:transparent;border-color:var(--orange);color:var(--strong)}
.btn:disabled{opacity:.45;cursor:not-allowed}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
.kpi{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:16px 18px}
.kpi.hero{background:var(--orange);border-color:var(--orange)}
.kpi small{color:var(--muted);font-weight:500}.kpi.hero small{color:#4A2208}
.kpi b{display:block;font:800 30px/1.15 var(--display);margin-top:6px}
.grid{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:18px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:20px;min-width:0;margin-bottom:18px}
.grid .card{margin-bottom:0}
.card h2{font-size:19px;font-weight:700}
.sub{color:var(--muted);font-size:14px;margin:2px 0 16px}
.chart{display:flex;align-items:flex-end;gap:8px;height:200px;border-bottom:1px solid var(--line)}
.col{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%;gap:6px}
.bar{width:100%;max-width:44px;background:var(--tint);border-radius:8px 8px 0 0;transition:background .2s}
.col.peak .bar{background:var(--orange)}
.col span,.axis div{font-size:12px;color:var(--muted);font-weight:600}
.axis{display:flex;gap:8px;margin-top:6px}.axis div{flex:1;text-align:center}
.tw{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:560px}
th{text-align:left;font-weight:600;color:var(--muted);font-size:14px;padding:8px 10px;border-bottom:1px solid var(--line)}
td{padding:11px 10px;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums}
tr:last-child td{border-bottom:0}
tr.row-unread{background:rgba(242,107,15,.06)}
.r{text-align:right}
.link{background:none;border:0;color:var(--strong);font-weight:600;padding:0;text-decoration:underline}
.pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:13px;font-weight:600;background:var(--goodbg);color:var(--good)}
.pill.low{background:var(--tint);color:var(--strong)}.pill.out{background:var(--badbg);color:var(--bad)}
.tools{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.tools input,.tools select,.f input,.f select{border:1px solid var(--line);background:var(--surface);border-radius:10px;padding:9px 12px;min-width:0}
.tools input[type=search]{flex:1;min-width:160px}
.f{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end}
.f label{display:flex;flex-direction:column;gap:4px;font-size:14px;font-weight:600}
.drop{border:2px dashed var(--orange);background:var(--tint);border-radius:14px;padding:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}
.drop p{margin:0}
.inv-pager{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-top:14px;padding-top:12px;border-top:1px solid var(--line)}
.inv-pager .pager-btns{display:flex;gap:8px;align-items:center}
.inv-pager .pager-pos{font-size:14px;font-weight:600;color:var(--muted);min-width:110px;text-align:center}
.rc{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:18px;align-items:start}
.rlist button{display:flex;justify-content:space-between;width:100%;text-align:left;background:none;border:0;border-bottom:1px solid var(--line);padding:12px 8px;border-radius:0}
.rlist button:hover{background:var(--bg)}
.rlist button[aria-pressed=true]{background:var(--tint)}
.paper{background:#fff;border:1px solid var(--line);border-top:6px solid var(--orange);border-radius:6px;padding:22px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:14px;position:sticky;top:20px}
.paper h3{font-family:var(--display);text-align:center;font-size:22px}
.paper .c{text-align:center;color:var(--muted);margin-bottom:12px}
.paper .row{display:flex;justify-content:space-between;gap:10px;padding:3px 0}
.paper hr{border:0;border-top:1px dashed var(--muted);margin:10px 0}
.paper .tot{font-weight:700;font-size:16px}
.toast{position:fixed;left:50%;bottom:calc(20px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);background:#1E0F03;color:#FFE8D4;padding:12px 18px;border-radius:12px;font-weight:600;opacity:0;pointer-events:none;transition:opacity .2s;z-index:20;max-width:90vw}
.toast.on{opacity:1}
.cnt{width:84px;text-align:right}
.neg{color:var(--bad);font-weight:700}.posv{color:var(--good);font-weight:700}
.month-compare{display:flex;gap:18px;flex-wrap:wrap;margin-bottom:18px}
.month-card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:18px;flex:1;min-width:200px}
.month-card .mhead{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.month-card .mhead b{font-size:17px}
.month-card .mvalue{font:800 28px/1.2 var(--display)}
.month-card .delta{font-size:14px;font-weight:600;margin-top:4px}
.delta.pos{color:var(--good)}.delta.neg{color:var(--bad)}
.month-bars{display:flex;align-items:flex-end;gap:8px;height:120px;margin-top:8px}
.month-bars .mb{flex:1;background:var(--tint);border-radius:6px 6px 0 0;min-width:20px;position:relative}
.month-bars .mb.current{background:var(--orange)}
.month-bars .mb span{position:absolute;bottom:-20px;left:50%;transform:translateX(-50%);font-size:11px;color:var(--muted);white-space:nowrap;font-weight:600}
.stocktake-hero{display:flex;flex-wrap:wrap;gap:18px;align-items:center;justify-content:space-between;margin-bottom:22px}
.stocktake-hero .stat{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:16px 22px;flex:1;min-width:160px}
.stocktake-hero .stat small{color:var(--muted);font-weight:500;display:block}
.stocktake-hero .stat b{display:block;font:800 28px/1.2 var(--display);margin-top:4px}
.stocktake-hero .stat.accent{background:var(--tint);border-color:var(--orange)}
.stocktake-hero .stat.accent b{color:var(--strong)}
.take-badge{display:inline-block;padding:4px 14px;border-radius:999px;font-size:13px;font-weight:600;background:var(--goodbg);color:var(--good)}
.take-badge.due{background:var(--tint);color:var(--strong)}
.take-badge.overdue{background:var(--badbg);color:var(--bad)}
.take-actions{display:flex;gap:10px;flex-wrap:wrap}
.stocktake-table td{padding:10px 8px}
.stocktake-table .cnt{width:90px;padding:8px 10px;border:1.5px solid var(--line);border-radius:8px;text-align:right;font-weight:600}
.stocktake-table .cnt:focus{border-color:var(--orange);outline:none;box-shadow:0 0 0 3px rgba(242,107,15,.15)}
.stocktake-table tr.row-changed{background:var(--tint)}
.stocktake-table tr.row-changed td{border-bottom-color:var(--orange)}
.stocktake-table .diff-pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:13px;font-weight:700;min-width:48px;text-align:center}
.diff-pill.zero{background:var(--goodbg);color:var(--good)}
.diff-pill.neg{background:var(--badbg);color:var(--bad)}
.diff-pill.pos{background:var(--tint);color:var(--strong)}
.weekly-note{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:14px 18px;margin-bottom:18px}
.weekly-note p{margin:0;font-size:14px;color:var(--muted)}
.weekly-note b{color:var(--ink)}
.pdf-preview{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:24px;margin-top:18px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px}
.pdf-preview h3{font-family:var(--display);font-size:20px;margin-bottom:4px}
.pdf-preview .pdf-head{display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid var(--orange)}
.pdf-preview .pdf-head small{color:var(--muted)}
.pdf-preview table{min-width:0;font-size:13px}
.pdf-preview th,.pdf-preview td{padding:7px 8px}
.pdf-preview .pdf-tot{display:flex;justify-content:space-between;font-weight:700;padding:10px 0;border-top:2px solid var(--line);margin-top:8px}
.modal{position:fixed;inset:0;background:rgba(30,15,3,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:20px}
.modal .paper{position:static;max-width:420px;width:100%;border-top:8px solid var(--orange);padding:28px;margin:0;animation:pop .18s ease-out}
@keyframes pop{from{transform:scale(.95);opacity:0}to{transform:scale(1);opacity:1}}
.act{display:flex;gap:6px;justify-content:flex-end;flex-wrap:nowrap}
.mini{padding:5px 11px;font-size:13px;font-weight:600;border-radius:8px;border:1px solid var(--line);background:var(--surface);white-space:nowrap}
.mini:hover{background:var(--bg)}
.mini.del{color:var(--bad);border-color:#F0C4C0}.mini.del:hover{background:var(--badbg)}
.modal .paper.formp{max-width:560px;max-height:calc(100vh - 40px);overflow:auto;font-family:var(--body);font-size:15px}
.modal .paper.pop{text-align:center;font-family:var(--body);font-size:15px}
.modal .paper.pop.bad{border-top-color:var(--bad)}
.modal .paper.pop.ok{border-top-color:var(--good)}
.pop h3{font-size:22px}
.pop p{margin:8px 0 20px;color:var(--muted);overflow-wrap:anywhere}
.pop .btn{min-width:120px}
@media(max-width:980px){.kpis{grid-template-columns:repeat(2,1fr)}.grid,.rc{grid-template-columns:1fr}.paper{position:static}.month-compare{flex-direction:column}}
@media(max-width:760px){.app{grid-template-columns:1fr}.side{position:sticky;height:auto;flex-direction:row;align-items:center;padding:10px 14px;gap:10px;z-index:5;overflow-x:auto}.brand span,nav a span,.who{display:none}.signout{margin-top:0;padding:9px 10px}.signout span{display:none}nav{flex-direction:row}nav a{padding:10px}}
@media(max-width:420px){.kpis{grid-template-columns:1fr}}
@media(max-width:760px){
  html,body{width:100%;max-width:100%;overflow-x:hidden}
  .app{display:block;width:100%;min-height:100vh}
  .side{position:sticky;top:0;height:auto;width:100%;display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:8px;padding:10px 12px;overflow:visible;z-index:10}
  .brand{font-size:21px;gap:8px;min-width:0}
  .brand span{display:inline}
  .brand svg{width:30px;height:30px;flex:none}
  nav{grid-column:1/-1;grid-row:2;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));align-items:stretch;gap:6px;width:100%;min-width:0;overflow:visible;padding:4px 0 2px}
  nav a{display:flex;min-width:0;justify-content:center;align-items:center;gap:6px;padding:8px 5px;font-size:12px;white-space:nowrap;border-radius:8px;background:rgba(255,255,255,.14)}
  nav a span{display:inline;overflow:hidden;text-overflow:ellipsis}
  nav svg{width:16px;height:16px;flex:none}
  .who{display:none}
  .signout{grid-column:2;grid-row:1;display:inline-flex;align-items:center;gap:7px;margin:0;padding:9px 11px;white-space:nowrap}
  .signout span{display:inline}
  main{width:100%;min-width:0;padding:20px 14px 36px}
  .top{align-items:flex-start;gap:12px}
  .top>div{min-width:0;flex:1}
  .top h1{font-size:clamp(24px,6vw,32px);overflow-wrap:anywhere}
  .top>.btn{flex:0 0 auto;max-width:100%;white-space:normal}
  .kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
  .kpi{min-width:0;padding:14px}
  .kpi b{font-size:clamp(22px,6vw,29px);overflow-wrap:anywhere}
  .grid,.rc{grid-template-columns:minmax(0,1fr);gap:14px}
  .card{padding:16px;min-width:0}
  .chart{gap:5px;height:175px}
  .col span,.axis div{font-size:10px}
  .axis{gap:5px}
  .tools{width:100%;min-width:0}
  .tools input[type=search]{flex:1 1 100%;min-width:0;width:100%}
  .tools select{flex:1 1 auto;min-width:0;max-width:100%}
  .f{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
  .f label{min-width:0}
  .f input,.f select{width:100%;max-width:100%}
  .f>.btn{width:100%;min-width:0}
  .drop{align-items:flex-start;flex-direction:column;padding:16px}
  .drop input[type=file]{max-width:100%;width:100%;font-size:13px}
  .tw{width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
  .tw table{min-width:560px}
  .paper{position:static;width:100%;max-width:100%;overflow-wrap:anywhere}
  .rlist{max-height:320px;overflow-y:auto}
  .month-compare{flex-direction:column;gap:12px}
  .month-card{width:100%;min-width:0;padding:16px}
  .month-card .mvalue{font-size:25px;overflow-wrap:anywhere}
  .stocktake-hero{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
  .stocktake-hero .stat{min-width:0;padding:14px}
  .stocktake-hero .stat b{font-size:25px;overflow-wrap:anywhere}
  .weekly-note{align-items:flex-start;padding:14px;gap:12px}
  .weekly-note>p{min-width:0;overflow-wrap:anywhere}
  .take-actions{width:100%;display:flex;flex-wrap:wrap}
  .take-actions .btn{flex:1 1 100%;white-space:normal}
  .pdf-preview{padding:14px;overflow:hidden}
  .pdf-preview .pdf-head{gap:8px}
  .pdf-preview .pdf-head>div{min-width:0;overflow-wrap:anywhere}
  .toast{width:max-content;max-width:calc(100vw - 28px);text-align:center}
}
@media(max-width:420px){
  main{padding:16px 10px 30px}
  .side{padding:9px 10px}
  .brand{font-size:19px}
  .signout{padding:8px 9px;font-size:12px}
  nav{grid-template-columns:repeat(3,minmax(0,1fr));gap:5px}
  nav a{padding:7px 3px;font-size:11px;gap:4px}
  .top{flex-direction:column;align-items:stretch}
  .top>.btn{align-self:flex-start}
  .kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
  .kpi{padding:12px}
  .kpi b{font-size:22px}
  .f{grid-template-columns:minmax(0,1fr)}
  .stocktake-hero{grid-template-columns:repeat(2,minmax(0,1fr))}
  .card{padding:13px}
  .weekly-note{padding:12px}
  .modal{padding:12px}
  .modal .paper{padding:20px}
}
@media(prefers-reduced-motion:reduce){.toast{transition:none}.modal .paper{animation:none}}

.hour-chart-note{font-size:13px;color:var(--muted)}
#hourChart{gap:3px;height:180px}
#hourChart .bar{max-width:22px}
#hourAxis{gap:3px}
#hourAxis div{font-size:10px;flex:1;text-align:center}
#hourAxis div:nth-child(2n){opacity:.7}
#smartAlerts .sub{font-size:13px}
@media(max-width:760px){#hourChart{gap:2px;height:150px}#hourChart .col span{font-size:8px}#hourAxis div{font-size:8px}}

</style>
</head>
<body>
<div class="app">
<aside class="side">
  <div class="brand"><svg viewBox="0 0 40 40" fill="none" aria-hidden="true"><circle cx="20" cy="24" r="11" fill="#1E0F03"/><path d="M9 17C5 15 4 9 8 6c1 4 4 5 6 6M31 17c4-2 5-8 1-11-1 4-4 5-6 6" stroke="#1E0F03" stroke-width="3" stroke-linecap="round"/><circle cx="16" cy="22" r="2" fill="#F26B0F"/><circle cx="24" cy="22" r="2" fill="#F26B0F"/></svg><span>crabkidz</span></div>
  <nav id="nav" aria-label="Main"></nav>
  <div class="who">
    <div class="avatar"><?= htmlspecialchars($initials ?: 'AM') ?></div>
    <div><b><?= htmlspecialchars($userName) ?></b><br>Store admin</div>
  </div>
  <button type="button" class="signout" id="signoutBtn">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    <span>Sign out</span>
  </button>
</aside>
<main>

<?php if ($loginMsg): ?>
  <div style="max-width:100%;margin:0 0 18px;padding:14px 16px;border-radius:12px;background:#DDF3E6;color:#1F7A4D;font:600 15px/1.4 var(--body)">
    <?= htmlspecialchars($loginMsg) ?>
  </div>
<?php endif; ?>

<section class="view" id="v-dashboard">
  <div class="top"><div><h1>Sales monitor</h1><p id="today"></p></div><button class="btn primary" data-go="upload">Upload new stock</button></div>
  <div class="kpis" id="kpis"></div>
  <div class="grid">
    <div class="card"><h2>Sales by day</h2><p class="sub" id="dayPeak"></p><div class="chart" id="dayChart" role="img" aria-label="Bar chart of sales by day"></div><div class="axis" id="dayAxis"></div></div>
    <div class="card"><h2>Stock alerts</h2><p class="sub">Items at or below 5 pairs</p><div id="alerts"></div><button class="btn" style="width:100%;margin-top:12px" data-go="stocktake">Start stock take</button></div>
  </div>
  <div class="grid" style="margin-top:18px">
    <div class="card"><h2>Sales by hour</h2><p class="sub">Today's sales activity by hour</p><div class="chart" id="hourChart" role="img" aria-label="Bar chart of today's sales by hour"></div><div class="axis" id="hourAxis"></div><p class="sub" id="hourSummary" style="margin-top:14px;margin-bottom:0"></p></div>
    <div class="card"><h2>Business alerts</h2><p class="sub">Automated alerts requiring attention</p><div id="smartAlerts"></div></div>
  </div>
  <div class="card" style="margin-top:18px">
    <h2>Sales by month — comparison</h2>
    <p class="sub" id="monthSub">Current vs previous month</p>
    <div class="month-compare" id="monthCompare"></div>
  </div>
</section>

<section class="view" id="v-sales">
  <div class="top"><div><h1>Sales</h1><p>Every sale rung up by the cashiers. Updates live.</p></div></div>
  <div class="card">
    <div class="tools"><input type="search" id="sq" placeholder="Search receipt, item or cashier" aria-label="Search sales"><select id="sp" aria-label="Payment method"><option value="">All payments</option><option>Card</option><option>Cash</option><option>Mobile money</option></select></div>
    <div class="tw"><table><thead><tr><th>Receipt</th><th>Time</th><th>Items</th><th>Cashier</th><th>Payment</th><th class="r">Total</th><th></th></tr></thead><tbody id="stbody"></tbody></table></div>
    <p class="sub" id="ssum" style="margin:12px 0 0"></p>
  </div>
</section>

<section class="view" id="v-receipts">
  <div class="top"><div><h1>Receipts</h1><p>Select a receipt to see what the customer got.</p></div></div>
  <div class="rc"><div class="card rlist" id="rlist"></div><div class="paper" id="paper"></div></div>
</section>

<section class="view" id="v-upload">
  <div class="top"><div><h1>Upload new stock</h1><p>One item no. represents a shoe type and can have multiple sizes, colors, and prices.</p></div></div>
  <div class="card"><h2>Add a shoe variant</h2><p class="sub">Reuse the same item no. and shoe name across different sizes and prices.</p>
    <div class="f">
      <label>Item no.<input id="n-sku" placeholder="618106" autocomplete="off" list="skuNameList"></label>
      <label>Name (shoe type)<input id="n-name" placeholder="Tidepool Sneaker" autocomplete="off"></label>
      <label>Gender<select id="n-gender"><option>Boy</option><option>Girl</option><option>Unisex</option></select></label>
      <label>Color<select id="n-color"><option>Black</option><option>Brown</option><option>White</option></select></label>
      <label>Size<input id="n-size" placeholder="e.g. 25 / 37" autocomplete="off" list="sizePresetList"></label>
      <label>Quantity<input id="n-qty" type="number" min="1" step="1" value="1" inputmode="numeric"></label>
      <label>Cost ($)<input id="n-cost" type="number" min="0" step="0.01" inputmode="decimal"></label>
      <label>Price ($)<input id="n-price" type="number" min="0" step="0.01" inputmode="decimal"></label>
      <button class="btn primary" id="addBtn">Add to stock</button>
    </div>
    <datalist id="skuNameList"></datalist>
    <datalist id="sizePresetList">
      <option value="25">
      <option value="26">
      <option value="27">
      <option value="28">
      <option value="29">
      <option value="30">
      <option value="31">
      <option value="32">
      <option value="33">
      <option value="34">
      <option value="35">
      <option value="36">
      <option value="37">
    </datalist>
    <p id="err" class="neg" role="alert" style="margin:10px 0 0"></p>
    <p class="sub" style="margin:10px 0 0">Enter the quantity to add for this specific item no. + color + size variant with its own cost and price.</p>
  </div>
  <div class="card"><div class="drop"><p><b>Import from CSV</b><br>Columns: item no, name, gender, color, size, cost, price, quantity (optional, defaults to 1; first row is the header). Multiple rows can share the same item no. with different sizes and prices.</p><input type="file" id="csv" accept=".csv,text/csv" aria-label="Choose CSV file"></div></div>
  <div class="card"><h2>Current stock</h2><p class="sub" id="invsum"></p>
    <div class="tools">
      <input type="search" id="iq" placeholder="Search item no., shoe, color or size" aria-label="Search current stock">
      <select id="if-sku" aria-label="Filter by item no."><option value="">All item nos.</option></select>
      <select id="if-gender" aria-label="Filter by gender"><option value="">All genders</option><option>Boy</option><option>Girl</option><option>Unisex</option></select>
      <select id="if-color" aria-label="Filter by color"><option value="">All colors</option></select>
      <select id="if-status" aria-label="Filter by stock status"><option value="">All stock levels</option><option value="in">In stock (&gt;5)</option><option value="low">Low stock (1–5)</option><option value="out">Out of stock (0)</option></select>
      <button class="btn" id="invClearFilterBtn" type="button">Reset</button>
    </div>
    <div class="tw"><table><thead><tr><th>Item no.</th><th>Shoe</th><th>Gender</th><th>Color</th><th>Size</th><th class="r">Cost</th><th class="r">Price</th><th class="r">In stock</th><th>Uploaded by</th><th class="r">Actions</th></tr></thead><tbody id="inv"></tbody></table></div>
    <div class="inv-pager" id="invPager">
      <span class="sub" id="invPageInfo" style="margin:0"></span>
      <div class="pager-btns">
        <button class="btn" id="invPrevBtn" type="button">Previous</button>
        <span class="pager-pos" id="invPagePos">Page 0 of 0</span>
        <button class="btn" id="invNextBtn" type="button">Next</button>
      </div>
    </div>
  </div>
</section>

<section class="view" id="v-stocktake">
  <div class="top"><div><h1>Stock take</h1><p>Count what’s on the shelf. Differences show as you type.</p></div></div>

  <div class="stocktake-hero" id="takeHero">
    <div class="stat"><small>Lines in system</small><b id="statLines">0</b></div>
    <div class="stat"><small>Total pairs</small><b id="statPairs">0</b></div>
    <div class="stat accent"><small>Lines counted</small><b id="statCounted">0</b></div>
    <div class="stat"><small>Net difference</small><b id="statDiff">0</b></div>
  </div>

  <div class="weekly-note" id="weeklyNote">
    <p><b>Weekly auto stock take:</b> <span id="weekStatus">Due today</span> &middot; Last run: <b id="lastRun">—</b> &middot; Next run: <b id="nextRun">—</b></p>
    <div class="take-actions">
      <button class="btn ghost" id="weeklyBtn" type="button">Run weekly stock take now</button>
      <button class="btn primary" id="applyBtn">Apply counts &amp; generate report</button>
    </div>
  </div>

  <div class="card">
    <div class="tools">
      <input type="search" id="tq" placeholder="Search shoe, item no., color or size" aria-label="Search stock">
      <button class="btn" id="clearTakeBtn" type="button">Clear counts</button>
    </div>
    <div class="tw">
      <table class="stocktake-table">
        <thead><tr><th>Item no.</th><th>Shoe</th><th>Color</th><th>Size</th><th class="r">Price</th><th class="r">System</th><th class="r">Counted</th><th class="r">Difference</th></tr></thead>
        <tbody id="ttbody"></tbody>
      </table>
    </div>
    <p class="sub" id="tsum" style="margin:12px 0 0"></p>
  </div>

  <div class="card" id="pdfCard" style="display:none">
    <h2>Stock take report</h2>
    <p class="sub">Preview of the report. Click below to download or print.</p>
    <div class="pdf-preview" id="pdfPreview"></div>
    <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap">
      <button class="btn primary" id="downloadPdfBtn" type="button">Download/Print</button>
      <button class="btn" id="closePdfBtn" type="button">Close</button>
    </div>
  </div>
</section>

<section class="view" id="v-notifications">
  <div class="top">
    <div><h1>Stock request notifications</h1><p>Restock requests sent by cashiers, showing read and unread status (20 per page).</p></div>
    <button class="btn primary" id="markAllReadBtn" type="button">Mark all as read</button>
  </div>
  <div class="card">
    <div class="tools">
      <input type="search" id="nq" placeholder="Search item no., shoe, color, size or cashier" aria-label="Search notifications">
      <select id="nFilter" aria-label="Filter read or unread">
        <option value="all">All notifications</option>
        <option value="unread">Unread</option>
        <option value="read">Read</option>
      </select>
    </div>
    <div class="tw">
      <table>
        <thead><tr><th>Status</th><th>Item no.</th><th>Shoe</th><th>Color</th><th>Size</th><th class="r">Left</th><th>Requested by</th><th>Date</th><th class="r">Action</th></tr></thead>
        <tbody id="ntbody"></tbody>
      </table>
    </div>
    <div class="inv-pager" id="notifPager">
      <span class="sub" id="notifPageInfo" style="margin:0"></span>
      <div class="pager-btns">
        <button class="btn" id="notifPrevBtn" type="button">Previous</button>
        <span class="pager-pos" id="notifPagePos">Page 0 of 0</span>
        <button class="btn" id="notifNextBtn" type="button">Next</button>
      </div>
    </div>
  </div>
</section>

</main>
</div>
<div class="toast" id="toast" role="status"></div>

<script>
var $=function(i){return document.getElementById(i)};
function $m(n){return '$'+Number(n||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function itemsText(s){return (s.items||[]).map(function(i){return esc(i.name)+' '+i.size+(i.qty>1?' ×'+i.qty:'')}).join(', ')}
function toast(m){var t=$('toast');if(!t)return;t.textContent=m;t.classList.add('on');clearTimeout(toast.h);toast.h=setTimeout(function(){t.classList.remove('on')},2600)}
function pillFor(q){return q===0?'<span class="pill out">Out</span>':q<=5?'<span class="pill low">'+q+' left</span>':'<span class="pill">'+q+'</span>'}
function fmtNotifDate(dt){
  if(!dt)return '—';
  var d=new Date(String(dt).replace(' ','T'));
  if(isNaN(d.getTime()))return esc(dt);
  return d.toLocaleDateString(undefined,{day:'numeric',month:'short',year:'numeric'})+' '+d.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
}

/* ---------- Data ---------- */
var stock=[];
var notifications=[];
var sales=[];
function variantKey(sku,col,size){
  return String(sku||'').trim().toLowerCase()+'|'+String(col||'').trim().toLowerCase()+'|'+String(size||'').trim().toLowerCase();
}
function shoeNameForSku(sku){
  var k=String(sku||'').trim().toLowerCase();
  if(!k)return'';
  var m=stock.find(function(x){return x.sku.trim().toLowerCase()===k});
  return m?m.name:'';
}
function addStock(sku,n,s,q,c,p,g,col,by,id,top){
  var skuClean=String(sku||'').trim();
  var colClean=String(col||'Black').trim()||'Black';
  var sizeClean=String(s||'').trim();
  var key=variantKey(skuClean,colClean,sizeClean);
  var f=stock.find(function(x){return variantKey(x.sku,x.color,x.size)===key});
  if(f){
    f.qty+=q;
    if(typeof c==='number'&&!isNaN(c))f.cost=c;
    if(typeof p==='number'&&!isNaN(p))f.price=p;
    if(g)f.gender=g;
    if(by)f.by=by;
    if(id)f.id=id;
    if(top){var ix=stock.indexOf(f);if(ix>0){stock.splice(ix,1);stock.unshift(f);}}
    return f;
  }
  f={
    id:id||0,
    sku:skuClean,
    name:String(n||'').trim(),
    size:sizeClean,
    qty:q,
    cost:typeof c==='number'&&!isNaN(c)?c:0,
    price:typeof p==='number'&&!isNaN(p)?p:0,
    gender:g||'Unisex',
    color:colClean,
    by:by||''
  };
  if(top)stock.unshift(f);else stock.push(f);
  return f;
}

/* Stock saved in the database (loaded by PHP) */
var live=<?= json_encode($live, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
var dbStockError=<?= json_encode($dbStockError, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
var todaySales=[],hist=[0,0,0,0,0,0],months=[],selRc=null,lastSync='';
function applyLive(d){
  if(!d||typeof d!=='object')return;
  stock.length=0;
  (d.stock||[]).forEach(function(r){addStock(r.sku,r.name,r.size,r.qty,r.cost,r.price,r.gender,r.color,r.by,r.id)});
  sales=(d.sales||[]).slice().reverse();
  todaySales=sales.filter(function(s){return s.today});
  hist=Array.isArray(d.hist)?d.hist:[0,0,0,0,0,0];
  months=Array.isArray(d.months)?d.months:[];
  notifications=Array.isArray(d.notifications)?d.notifications:[];
  lastSync=d.serverTime||'';
  if(!sales.some(function(x){return x.id===selRc}))selRc=sales.length?sales[sales.length-1].id:null;
  updateNavBadge();
}

/* ---------- Modals ---------- */
function openModal(id,html,onClose){
  var old=document.getElementById(id);if(old&&old.__close)old.__close(true);
  var m=document.createElement('div');
  m.id=id;m.className='modal';m.setAttribute('role','dialog');m.setAttribute('aria-modal','true');
  m.innerHTML=html;document.body.appendChild(m);
  var done=false;
  function onKey(e){
    if(e.key!=='Escape')return;
    var all=document.querySelectorAll('.modal');
    if(all[all.length-1]===m)close();
  }
  function close(silent){
    if(done)return;done=true;
    document.removeEventListener('keydown',onKey);
    if(m.parentNode)m.parentNode.removeChild(m);
    if(silent!==true&&typeof onClose==='function')onClose();
  }
  m.__close=close;
  m.addEventListener('click',function(e){if(e.target===m)close()});
  document.addEventListener('keydown',onKey);
  return m;
}
function postJSON(payload){
  return fetch(location.href.split('#')[0],{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(payload)
  }).then(function(r){
    return r.json().catch(function(){return {ok:false,message:'Unexpected response from the server.'}});
  }).catch(function(){
    return {ok:false,message:'Could not reach the server. Check your connection and try again.'};
  });
}

/* ---------- Nav ---------- */
var views=[
  ['dashboard','Sales monitor','<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>'],
  ['sales','Sales','<path d="M6 6h15l-2 9H8L6 3H3M9 20h.01M18 20h.01"/>'],
  ['receipts','Receipts','<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-3 2zM9 8h6M9 12h6"/>'],
  ['upload','Upload stock','<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>'],
  ['stocktake','Stock take','<path d="M9 4h6v3H9zM6 5H5v16h14V5h-1M9 14l2 2 4-4"/>'],
  ['notifications','Notifications','<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/>']
];
$('nav').innerHTML=views.map(function(v){
  var badge=v[0]==='notifications'?'<span class="nav-badge" id="notifBadge" style="display:none">0</span>':'';
  return '<a href="#'+v[0]+'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+v[2]+'</svg><span>'+v[1]+'</span>'+badge+'</a>';
}).join('');
$('nav').insertAdjacentHTML('beforeend','<a href="activity.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg><span>Activity</span></a>');

function updateNavBadge(){
  var b=$('notifBadge');
  if(!b)return;
  var unread=notifications.filter(function(n){return !n.is_read}).length;
  b.textContent=unread;
  b.style.display=unread>0?'inline-block':'none';
}

applyLive(live);

function route(){
  var h=(location.hash||'#dashboard').slice(1);
  if(!views.some(function(v){return v[0]===h}))h='dashboard';
  views.forEach(function(v){var el=$('v-'+v[0]);if(el)el.classList.toggle('on',v[0]===h)});
  document.querySelectorAll('#nav a').forEach(function(a){
    if(a.getAttribute('href')==='#'+h)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');
  });
  var fn={dashboard:drawDash,sales:drawSales,receipts:drawReceipts,upload:drawInventory,stocktake:drawTake,notifications:drawNotifications}[h];
  if(typeof fn==='function')fn();
  window.scrollTo(0,0);
}
window.addEventListener('hashchange',route);
document.addEventListener('click',function(e){var g=e.target.closest('[data-go]');if(g)location.hash=g.dataset.go;
  var r=e.target.closest('[data-rc]');if(r){selRc=r.dataset.rc;if(location.hash==='#receipts')drawReceipts();else location.hash='receipts'}});

/* ---------- Dashboard ---------- */
function drawDash(){
  var tot=todaySales.reduce(function(t,s){return t+s.total},0),pairs=todaySales.reduce(function(t,s){return t+(s.items||[]).reduce(function(a,i){return a+i.qty},0)},0);
  $('today').textContent='Live totals for '+new Date().toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric'})+'.'+(lastSync?' Updated '+lastSync+'.':'');
  $('kpis').innerHTML='<div class="kpi hero"><small>Sales today</small><b>'+$m(tot)+'</b></div><div class="kpi"><small>Receipts</small><b>'+todaySales.length+'</b></div><div class="kpi"><small>Pairs sold</small><b>'+pairs+'</b></div><div class="kpi"><small>Average basket</small><b>'+$m(todaySales.length?tot/todaySales.length:0)+'</b></div>';

  var today = new Date();
  var dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  var last7 = [];
  for (var i=6; i>=0; i--) {
    var d = new Date(today);
    d.setDate(d.getDate() - i);
    last7.push({label: dayNames[d.getDay()],fullDate: d.toLocaleDateString(undefined,{month:'short',day:'numeric'}),total: 0});
  }
  for (var i=0; i<6; i++) { last7[i].total = hist[i] || 0; }
  last7[6].total = tot;

  var dayTotals = last7.map(function(d){return d.total});
  var dayMax = Math.max.apply(null, dayTotals);
  var dayPeakIdx = dayTotals.indexOf(dayMax);
  $('dayChart').innerHTML = last7.map(function(d,i){
    var height = dayMax > 0 ? Math.round(d.total / dayMax * 150) : 10;
    return '<div class="col'+(i===dayPeakIdx?' peak':'')+'"><span>'+$m(d.total).replace('.00','')+'</span><div class="bar" style="height:'+height+'px"></div></div>';
  }).join('');
  $('dayAxis').innerHTML = last7.map(function(d){return '<div>'+d.label+'<br><small style="font-size:10px;opacity:.7">'+d.fullDate+'</small></div>'}).join('');
  $('dayPeak').textContent = 'Busiest day: ' + last7[dayPeakIdx].fullDate + ' (' + last7[dayPeakIdx].label + ') with ' + $m(dayMax);

  // Sales-by-hour chart (uses today's transactions loaded from the sales table).
  var hourBuckets = Array.from({length:24},function(){return 0;});
  todaySales.forEach(function(s){
    var hm=String(s.time||'').match(/^(\d{1,2}):(\d{2})$/);
    if(hm){var hr=parseInt(hm[1],10);if(hr>=0&&hr<24)hourBuckets[hr]+=Number(s.total||0);}
  });
  var hourMax=Math.max.apply(null,hourBuckets)||0;
  var peakHour=hourBuckets.indexOf(hourMax);
  $('hourChart').innerHTML=hourBuckets.map(function(v,h){
    var height=hourMax>0?Math.max(3,Math.round(v/hourMax*145)):3;
    return '<div class="col'+(v===hourMax&&hourMax>0?' peak':'')+'"><span>'+(v>0?$m(v).replace('.00',''):'')+'</span><div class="bar" style="height:'+height+'px"></div></div>';
  }).join('');
  $('hourAxis').innerHTML=hourBuckets.map(function(v,h){return '<div>'+String(h).padStart(2,'0')+'</div>'}).join('');
  $('hourSummary').textContent=hourMax>0?'Peak sales hour: '+String(peakHour).padStart(2,'0')+':00–'+String((peakHour+1)%24).padStart(2,'0')+':00 ('+$m(hourMax)+')':'No sales recorded today yet.';

  var currentMonthName = today.toLocaleDateString(undefined,{month:'long',year:'numeric'});
  var prevMonthDate = new Date(today.getFullYear(), today.getMonth()-1, 1);
  var prevMonthName = prevMonthDate.toLocaleDateString(undefined,{month:'long',year:'numeric'});
  var currentMonthTotal = months.length?months[months.length-1].total:tot;
  var prevMonthTotal = months.length>1?months[months.length-2].total:0;
  var delta = currentMonthTotal - prevMonthTotal;
  var deltaPct = prevMonthTotal>0 ? (delta / prevMonthTotal * 100).toFixed(1) : '0.0';
  var deltaClass = delta >= 0 ? 'pos' : 'neg';
  var deltaSign = delta >= 0 ? '+' : '';

  var monthLabels = months.length?months.map(function(m){return m.label}):['','',''];
  var monthValues = months.length?months.map(function(m){return m.total}):[0, 0, currentMonthTotal];
  var monthMax = Math.max.apply(null, monthValues) || 1;
  var monthBarsHtml = monthValues.map(function(v,i){
    var h = Math.round(v / monthMax * 100);
    return '<div class="mb'+(i===2?' current':'')+'" style="height:'+h+'px"><span>'+monthLabels[i]+'<br>'+$m(v).replace('.00','')+'</span></div>';
  }).join('');

  $('monthSub').textContent = 'Current month (' + currentMonthName + ') vs previous month (' + prevMonthName + ')';
  $('monthCompare').innerHTML =
    '<div class="month-card"><div class="mhead"><b>Current month</b><small>'+currentMonthName+'</small></div><div class="mvalue">'+$m(currentMonthTotal)+'</div><div class="delta '+deltaClass+'">'+deltaSign+$m(delta)+' ('+deltaSign+deltaPct+'%) vs last month</div><div class="month-bars" style="height:100px;margin-top:18px">'+monthBarsHtml+'</div></div>' +
    '<div class="month-card"><div class="mhead"><b>Previous month</b><small>'+prevMonthName+'</small></div><div class="mvalue">'+$m(prevMonthTotal)+'</div><div class="delta" style="color:var(--muted)">Reference period</div></div>' +
    '<div class="month-card" style="display:flex;flex-direction:column;justify-content:center"><div style="font-size:15px;color:var(--muted)">Growth</div><div class="mvalue" style="font-size:36px;color:'+(delta>=0?'var(--good)':'var(--bad)')+'">'+deltaSign+deltaPct+'%</div><div style="font-size:14px;color:var(--muted)">Month-over-month change</div></div>';

  var low=stock.filter(function(s){return s.qty<=5}).sort(function(a,b){return a.qty-b.qty});
  $('alerts').innerHTML=low.length?'<table style="min-width:0"><tbody>'+low.map(function(s){return '<tr><td>'+esc(s.name)+' ('+esc(s.sku)+')<br><small style="color:var(--muted)">'+esc(s.color)+' · Size '+esc(s.size)+' · '+$m(s.price)+'</small></td><td class="r">'+pillFor(s.qty)+'</td></tr>'}).join('')+'</tbody></table>':'<p class="sub">Everything is stocked.</p>';

  // Smart alerts: out-of-stock, low-stock and pending cashier restock requests.
  var outOfStock=stock.filter(function(s){return s.qty===0;});
  var lowOnly=stock.filter(function(s){return s.qty>0&&s.qty<=5;});
  var pending=notifications.filter(function(n){return String(n.status||'').toLowerCase()==='pending';});
  var alertRows=[];
  if(outOfStock.length)alertRows.push('<div style="display:flex;gap:10px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--line)"><span class="pill out">Out of stock</span><div><b>'+outOfStock.length+' item variant'+(outOfStock.length===1?'':'s')+'</b><div class="sub" style="margin:2px 0 0">'+outOfStock.slice(0,3).map(function(x){return esc(x.name)+' · '+esc(x.size)}).join(', ')+(outOfStock.length>3?' and more':'')+'</div></div></div>');
  if(lowOnly.length)alertRows.push('<div style="display:flex;gap:10px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--line)"><span class="pill low">Low stock</span><div><b>'+lowOnly.length+' variant'+(lowOnly.length===1?'':'s')+' need attention</b><div class="sub" style="margin:2px 0 0">Stock is at 1–5 pairs.</div></div></div>');
  if(pending.length)alertRows.push('<div style="display:flex;gap:10px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--line)"><span class="pill low">Restock request</span><div><b>'+pending.length+' pending request'+(pending.length===1?'':'s')+'</b><div class="sub" style="margin:2px 0 0">Cashiers have requested stock replenishment.</div><a href="#notifications" style="color:var(--strong);font-weight:600">Review requests</a></div></div>');
  $('smartAlerts').innerHTML=alertRows.length?alertRows.join(''):'<div style="padding:12px 0"><span class="pill">All clear</span><p class="sub" style="margin:8px 0 0">No stock or restock-request alerts at the moment.</p></div>';
}

/* ---------- Sales ---------- */
function drawSales(){
  var q=$('sq').value.toLowerCase(),p=$('sp').value;
  var rows=sales.filter(function(s){return(!p||s.pay===p)&&(!q||(s.id+' '+itemsText(s)+' '+s.cashier).toLowerCase().indexOf(q)>-1)}).slice().reverse();
  $('stbody').innerHTML=rows.map(function(s){return '<tr><td>#'+s.id+'</td><td>'+s.time+'</td><td>'+itemsText(s)+'</td><td>'+s.cashier+'</td><td>'+s.pay+'</td><td class="r">'+$m(s.total)+'</td><td><button class="link" data-rc="'+s.id+'">View receipt</button></td></tr>'}).join('')||'<tr><td colspan="7">No sales match. Clear the search or payment filter.</td></tr>';
  $('ssum').textContent=rows.length+' sales, '+$m(rows.reduce(function(t,s){return t+s.total},0))+' total'}
$('sq').addEventListener('input',drawSales);$('sp').addEventListener('change',drawSales);

function drawReceipts(){
  $('rlist').innerHTML=sales.slice().reverse().map(function(s){return '<button data-rc="'+s.id+'" aria-pressed="'+(s.id===selRc)+'"><span><b>#'+s.id+'</b><br><small style="color:var(--muted)">'+s.time+' · '+s.pay+'</small></span><b>'+$m(s.total)+'</b></button>'}).join('');
  var s=sales.find(function(x){return x.id===selRc});
  if(!s){$('paper').innerHTML='<h3>crabkidz</h3><div class="c">No receipts yet</div>';return}
  $('paper').innerHTML='<h3>crabkidz</h3><div class="c">Shoes for little feet<br>Receipt #'+s.id+' · '+s.time+'</div><hr>'+(s.items||[]).map(function(i){return '<div class="row"><span>'+esc(i.name)+(i.color?' ('+esc(i.color)+')':'')+'<br>Size '+i.size+' × '+i.qty+'</span><span>'+$m(i.qty*i.price)+'</span></div>'}).join('')+'<hr><div class="row tot"><span>Total</span><span>'+$m(s.total)+'</span></div><div class="row"><span>Paid by</span><span>'+s.pay+'</span></div><div class="row"><span>Served by</span><span>'+s.cashier+'</span></div><hr><div class="c">Thank you for shopping at Crabkidz!</div>'}

/* ---------- Notifications (Read / Unread + 20 per page pagination) ---------- */
var NOTIF_PAGE_SIZE=20;
var notifPage=0;

function getFilteredNotifications(){
  var q=($('nq').value||'').trim().toLowerCase();
  var f=$('nFilter').value;
  return notifications.filter(function(n){
    if(f==='unread' && n.is_read)return false;
    if(f==='read' && !n.is_read)return false;
    if(q && (n.item_no+' '+n.name+' '+n.color+' '+n.size+' '+n.requested_by_name+' '+n.created_at).toLowerCase().indexOf(q)===-1)return false;
    return true;
  });
}

function drawNotifications(){
  updateNavBadge();
  var filtered=getFilteredNotifications();
  if(!filtered.length){
    notifPage=0;
    $('ntbody').innerHTML='<tr><td colspan="9">No stock request notifications match.</td></tr>';
    $('notifPageInfo').textContent='Showing 0 of '+notifications.length+' notifications';
    $('notifPagePos').textContent='Page 0 of 0';
    $('notifPrevBtn').disabled=true;
    $('notifNextBtn').disabled=true;
    return;
  }
  var totalPages=Math.ceil(filtered.length/NOTIF_PAGE_SIZE);
  if(notifPage>=totalPages)notifPage=totalPages-1;
  if(notifPage<0)notifPage=0;

  var start=notifPage*NOTIF_PAGE_SIZE;
  var pageRows=filtered.slice(start, start+NOTIF_PAGE_SIZE);
  $('ntbody').innerHTML=pageRows.map(function(n){
    var statusPill=n.is_read?'<span class="pill">Read</span>':'<span class="pill low">Unread</span>';
    var btnLabel=n.is_read?'Mark unread':'Mark read';
    return '<tr class="'+(n.is_read?'':'row-unread')+'">'
      +'<td>'+statusPill+'</td>'
      +'<td><b>'+esc(n.item_no)+'</b></td>'
      +'<td>'+esc(n.name)+'</td>'
      +'<td>'+esc(n.color)+'</td>'
      +'<td>'+esc(n.size)+'</td>'
      +'<td class="r">'+pillFor(n.current_qty)+'</td>'
      +'<td>'+esc(n.requested_by_name)+'</td>'
      +'<td>'+fmtNotifDate(n.created_at)+'</td>'
      +'<td class="r"><button type="button" class="mini" data-notif-toggle="'+n.id+'" data-next-read="'+(n.is_read?'0':'1')+'">'+btnLabel+'</button></td>'
      +'</tr>';
  }).join('');
  $('notifPageInfo').textContent='Showing '+(start+1)+'–'+(start+pageRows.length)+' of '+filtered.length+' ('+notifications.length+' total · 20 per page)';
  $('notifPagePos').textContent='Page '+(notifPage+1)+' of '+totalPages;
  $('notifPrevBtn').disabled=notifPage<=0;
  $('notifNextBtn').disabled=notifPage>=totalPages-1;
}

$('nq').addEventListener('input',function(){notifPage=0;drawNotifications()});
$('nFilter').addEventListener('change',function(){notifPage=0;drawNotifications()});
$('notifPrevBtn').addEventListener('click',function(){if(notifPage>0){notifPage--;drawNotifications()}});
$('notifNextBtn').addEventListener('click',function(){
  var totalPages=Math.ceil(getFilteredNotifications().length/NOTIF_PAGE_SIZE);
  if(notifPage<totalPages-1){notifPage++;drawNotifications()}
});

$('ntbody').addEventListener('click',function(e){
  var btn=e.target.closest('button[data-notif-toggle]');
  if(!btn)return;
  var id=parseInt(btn.dataset.notifToggle,10);
  var nextRead=btn.dataset.nextRead==='1';
  btn.disabled=true;
  postJSON({action:'set_notification_read',id:id,is_read:nextRead}).then(function(res){
    btn.disabled=false;
    if(!res||!res.ok){toast((res&&res.message)||'Could not update notification status.');return;}
    var item=notifications.find(function(x){return x.id===id});
    if(item)item.is_read=nextRead;
    drawNotifications();
    toast(nextRead?'Marked notification as read.':'Marked notification as unread.');
  });
});

$('markAllReadBtn').addEventListener('click',function(){
  var btn=$('markAllReadBtn');
  btn.disabled=true;
  postJSON({action:'mark_all_notifications_read'}).then(function(res){
    btn.disabled=false;
    if(!res||!res.ok){toast((res&&res.message)||'Could not mark all as read.');return;}
    notifications.forEach(function(x){x.is_read=true});
    drawNotifications();
    toast('All notifications marked as read.');
  });
});

/* ---------- Inventory (Upload stock — 10 per page + filters) ---------- */
var INV_PAGE_SIZE=10;
var invPage=0;

function syncSkuSuggestions(){
  var seen={};
  var opts=[];
  stock.forEach(function(s){
    var k=s.sku.trim().toLowerCase();
    if(!k||seen[k])return;
    seen[k]=true;
    opts.push('<option value="'+esc(s.sku)+'">'+esc(s.name)+'</option>');
  });
  $('skuNameList').innerHTML=opts.join('');
}

function syncInventoryFilters(){
  var skuSel=$('if-sku');
  var curSku=skuSel.value;
  var seenSku={};
  var skuOpts=['<option value="">All item nos.</option>'];
  stock.forEach(function(s){
    var k=s.sku.trim().toLowerCase();
    if(!k||seenSku[k])return;
    seenSku[k]=true;
    skuOpts.push('<option value="'+esc(k)+'">'+esc(s.sku)+' — '+esc(s.name)+'</option>');
  });
  skuSel.innerHTML=skuOpts.join('');
  if(curSku && seenSku[curSku])skuSel.value=curSku;

  var colSel=$('if-color');
  var curCol=colSel.value;
  var seenCol={};
  var colOpts=['<option value="">All colors</option>'];
  stock.forEach(function(s){
    var c=s.color.trim().toLowerCase();
    if(!c||seenCol[c])return;
    seenCol[c]=true;
    colOpts.push('<option value="'+esc(c)+'">'+esc(s.color)+'</option>');
  });
  colSel.innerHTML=colOpts.join('');
  if(curCol && seenCol[curCol])colSel.value=curCol;
}

function getFilteredInventory(){
  var q=$('iq').value.trim().toLowerCase();
  var fSku=$('if-sku').value;
  var fGender=$('if-gender').value;
  var fColor=$('if-color').value;
  var fStatus=$('if-status').value;
  return stock.filter(function(s){
    if(fSku && s.sku.trim().toLowerCase()!==fSku)return false;
    if(fGender && s.gender!==fGender)return false;
    if(fColor && s.color.trim().toLowerCase()!==fColor)return false;
    if(fStatus==='in' && s.qty<=5)return false;
    if(fStatus==='low' && (s.qty<1 || s.qty>5))return false;
    if(fStatus==='out' && s.qty!==0)return false;
    if(q && (s.sku+' '+s.name+' '+s.gender+' '+s.color+' '+s.size+' '+(s.by||'')).toLowerCase().indexOf(q)===-1)return false;
    return true;
  });
}

$('n-sku').addEventListener('input',function(){
  var existingName=shoeNameForSku($('n-sku').value);
  if(existingName && !$('n-name').value.trim()){
    $('n-name').value=existingName;
  }
});

function drawInventory(){
  syncSkuSuggestions();
  syncInventoryFilters();
  var filtered=getFilteredInventory();
  var uniqueSkus={};
  stock.forEach(function(s){uniqueSkus[s.sku.trim().toLowerCase()]=true});
  $('invsum').textContent=stock.length+' size/price variants across '+Object.keys(uniqueSkus).length+' item no.(s), '+stock.reduce(function(t,s){return t+s.qty},0)+' pairs, '+$m(stock.reduce(function(t,s){return t+s.qty*s.cost},0))+' at cost';

  if(!filtered.length){
    invPage=0;
    $('inv').innerHTML='<tr><td colspan="10">No stock items match the current filter.</td></tr>';
    $('invPageInfo').textContent='Showing 0 of '+stock.length+' variants';
    $('invPagePos').textContent='Page 0 of 0';
    $('invPrevBtn').disabled=true;
    $('invNextBtn').disabled=true;
    return;
  }

  var totalPages=Math.ceil(filtered.length/INV_PAGE_SIZE);
  if(invPage>=totalPages)invPage=totalPages-1;
  if(invPage<0)invPage=0;

  var start=invPage*INV_PAGE_SIZE;
  var pageRows=filtered.slice(start, start+INV_PAGE_SIZE);
  $('inv').innerHTML=pageRows.map(function(s){return '<tr><td>'+esc(s.sku)+'</td><td>'+esc(s.name)+'</td><td>'+esc(s.gender)+'</td><td>'+esc(s.color)+'</td><td>'+esc(s.size)+'</td><td class="r">'+$m(s.cost)+'</td><td class="r">'+$m(s.price)+'</td><td class="r">'+pillFor(s.qty)+'</td><td>'+esc(s.by||'—')+'</td><td class="r"><div class="act"><button class="mini" type="button" data-edit="'+s.id+'" aria-label="Edit '+esc(s.name)+' '+esc(s.color)+' size '+esc(s.size)+'">Edit</button><button class="mini del" type="button" data-del="'+s.id+'" aria-label="Delete '+esc(s.name)+' '+esc(s.color)+' size '+esc(s.size)+'">Delete</button></div></td></tr>'}).join('');
  $('invPageInfo').textContent='Showing '+(start+1)+'–'+(start+pageRows.length)+' of '+filtered.length+' matching ('+stock.length+' total)';
  $('invPagePos').textContent='Page '+(invPage+1)+' of '+totalPages;
  $('invPrevBtn').disabled=invPage<=0;
  $('invNextBtn').disabled=invPage>=totalPages-1;
}

$('invPrevBtn').addEventListener('click',function(){
  if(invPage>0){invPage--;drawInventory();}
});
$('invNextBtn').addEventListener('click',function(){
  var totalPages=Math.ceil(getFilteredInventory().length/INV_PAGE_SIZE);
  if(invPage<totalPages-1){invPage++;drawInventory();}
});
['iq','if-sku','if-gender','if-color','if-status'].forEach(function(id){
  var ev=(id==='iq')?'input':'change';
  $(id).addEventListener(ev,function(){invPage=0;drawInventory();});
});
$('invClearFilterBtn').addEventListener('click',function(){
  $('iq').value='';
  $('if-sku').value='';
  $('if-gender').value='';
  $('if-color').value='';
  $('if-status').value='';
  invPage=0;
  drawInventory();
});

function saveStock(items){
  return fetch(location.href.split('#')[0],{
    method:'POST',
    credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'add_stock',items:items})
  }).then(function(r){
    return r.json().catch(function(){return {ok:false,message:'Unexpected response from the server.'}});
  }).catch(function(){
    return {ok:false,message:'Could not reach the server. Check your connection and try again.'};
  });
}

$('addBtn').addEventListener('click',function(){
  var btn=$('addBtn');
  var sku=$('n-sku').value.trim();
  var n=$('n-name').value.trim();
  var g=$('n-gender').value;
  var col=$('n-color').value;
  var s=$('n-size').value.trim();
  var q=parseInt($('n-qty').value,10);
  var c=parseFloat($('n-cost').value);
  var p=parseFloat($('n-price').value);
  if(!sku||!n||!s||isNaN(q)||q<1||isNaN(c)||isNaN(p)){
    $('err').textContent='Fill in item no., name, size, quantity (at least 1), cost and price.';
    return;
  }

  var existingName=shoeNameForSku(sku);
  if(existingName && existingName.toLowerCase()!==n.toLowerCase()){
    $('err').textContent='Item no. '+sku+' represents shoe type "'+existingName+'". Use the same name for different sizes or prices.';
    return;
  }

  $('err').textContent='';
  btn.disabled=true;
  saveStock([{sku:sku,name:n,size:s,gender:g,color:col,cost:c,price:p,qty:q}]).then(function(res){
    btn.disabled=false;
    if(res && res.ok && res.saved && res.saved.length){
      var r=res.saved[0];
      addStock(r.sku,r.name,r.size,r.qty||q,r.cost,r.price,r.gender,r.color,r.by,r.id,true);
      ['n-size','n-cost','n-price'].forEach(function(i){$(i).value=''});
      $('n-qty').value='1';
      $('n-size').focus();
      var filtered=getFilteredInventory();
      var addedIdx=filtered.findIndex(function(x){return variantKey(x.sku,x.color,x.size)===variantKey(r.sku,r.color,r.size)});
      if(addedIdx>=0)invPage=Math.floor(addedIdx/INV_PAGE_SIZE);
      drawInventory();
      toast('✓ Saved: '+(r.qty||q)+' × '+r.name+' ('+r.color+', size '+r.size+', '+$m(r.price)+') [item '+r.sku+']');
    } else {
      var msg=(res&&res.message)?res.message:'Could not save the stock.';
      $('err').textContent=msg;
      toast('✕ Stock not saved. '+msg);
    }
  });
});

$('csv').addEventListener('change',function(e){
  var input=e.target;
  var f=input.files[0];if(!f)return;var r=new FileReader();
  r.onload=function(){
    var items=[],bad=0,batchNames={};
    stock.forEach(function(x){batchNames[x.sku.trim().toLowerCase()]=x.name.trim().toLowerCase()});
    r.result.split(/\r?\n/).slice(1).forEach(function(l){
      if(!l.trim())return;
      var c=l.split(',').map(function(x){return x.trim()});
      var sku=c[0],name=c[1],gender=c[2]||'Unisex',color=c[3]||'Black',size=c[4],cost=parseFloat(c[5]),price=parseFloat(c[6]);
      var qty=(c[7]!==undefined && c[7]!=='')?parseInt(c[7],10):1;
      if(sku&&name&&size&&!isNaN(cost)&&!isNaN(price)&&!isNaN(qty)&&qty>=1){
        var k=sku.toLowerCase();
        if(batchNames[k] && batchNames[k]!==name.toLowerCase()){bad++;return}
        batchNames[k]=name.toLowerCase();
        items.push({sku:sku,name:name,size:size,gender:gender,color:color,cost:cost,price:price,qty:qty});
      } else bad++;
    });
    input.value='';
    if(!items.length){
      toast('✕ Nothing imported'+(bad?', '+bad+' skipped':'')+'. Check the CSV columns.');
      return;
    }
    saveStock(items).then(function(res){
      if(res && res.ok && res.saved){
        res.saved.slice().reverse().forEach(function(x){addStock(x.sku,x.name,x.size,x.qty||1,x.cost,x.price,x.gender,x.color,x.by,x.id,true)});
        invPage=0;
        drawInventory();
        var skipped=bad+(res.skipped||0);
        toast('✓ '+res.saved.length+' rows saved'+(skipped?', '+skipped+' skipped':'')+' — uploaded by '+(res.uploadedBy||'you'));
      } else {
        toast('✕ Import failed. '+((res&&res.message)?res.message:'Could not save the stock.'));
      }
    });
  };
  r.readAsText(f);
});

/* ---------- Edit / delete a stock line ---------- */
function findStockById(id){return stock.find(function(x){return x.id===id})}

function openEditStock(id){
  var s=findStockById(id);
  if(!s){toast('That stock line could not be found. Refresh and try again.');return}
  var m=openModal('editModal',
    '<div class="paper formp"><h3 style="margin-bottom:4px;text-align:left">Edit stock</h3>'+
    '<p class="sub" style="margin:0 0 14px">Item no. <b>'+esc(s.sku)+'</b>. Renaming the shoe applies to every size and color under this item no.</p>'+
    '<div class="f">'+
      '<label>Name (shoe type)<input id="e-name" value="'+esc(s.name)+'" autocomplete="off"></label>'+
      '<label>Gender<select id="e-gender"><option>Boy</option><option>Girl</option><option>Unisex</option></select></label>'+
      '<label>Color<input id="e-color" value="'+esc(s.color)+'" list="e-colors" autocomplete="off"><datalist id="e-colors"><option value="Black"><option value="Brown"><option value="White"></datalist></label>'+
      '<label>Size<input id="e-size" value="'+esc(s.size)+'" autocomplete="off" list="sizePresetList"></label>'+
      '<label>In stock<input id="e-qty" type="number" min="0" step="1" value="'+s.qty+'" inputmode="numeric"></label>'+
      '<label>Cost ($)<input id="e-cost" type="number" min="0" step="0.01" value="'+s.cost+'" inputmode="decimal"></label>'+
      '<label>Price ($)<input id="e-price" type="number" min="0" step="0.01" value="'+s.price+'" inputmode="decimal"></label>'+
    '</div>'+
    '<p id="e-err" class="neg" role="alert" style="margin:10px 0 0"></p>'+
    '<div style="display:flex;gap:10px;margin-top:16px"><button class="btn" id="e-cancel" type="button" style="flex:1">Cancel</button><button class="btn primary" id="e-save" type="button" style="flex:1">Save changes</button></div></div>');
  m.querySelector('#e-gender').value=s.gender||'Unisex';
  m.querySelector('#e-cancel').addEventListener('click',function(){m.__close()});
  m.querySelector('#e-name').focus();
  m.querySelector('#e-save').addEventListener('click',function(){
    var btn=m.querySelector('#e-save');
    var name=m.querySelector('#e-name').value.trim();
    var gender=m.querySelector('#e-gender').value;
    var color=m.querySelector('#e-color').value.trim()||'Black';
    var size=m.querySelector('#e-size').value.trim();
    var qty=parseInt(m.querySelector('#e-qty').value,10);
    var cost=parseFloat(m.querySelector('#e-cost').value);
    var price=parseFloat(m.querySelector('#e-price').value);
    var err=m.querySelector('#e-err');
    if(!name||!size||isNaN(qty)||qty<0||isNaN(cost)||cost<0||isNaN(price)||price<0){
      err.textContent='Fill in name, size, stock (0 or more), cost and price.';
      return;
    }
    var clash=stock.some(function(x){return x.id!==s.id&&variantKey(x.sku,x.color,x.size)===variantKey(s.sku,color,size)});
    if(clash){err.textContent='Item no. '+s.sku+' already has a '+color+' line in size '+size+'.';return}
    err.textContent='';
    btn.disabled=true;
    postJSON({action:'edit_stock',id:s.id,name:name,gender:gender,color:color,size:size,qty:qty,cost:cost,price:price}).then(function(res){
      btn.disabled=false;
      if(res&&res.ok){
        var skuKey=s.sku.trim().toLowerCase();
        stock.forEach(function(x){if(x.sku.trim().toLowerCase()===skuKey)x.name=name});
        s.gender=gender;s.color=color;s.size=size;s.qty=qty;s.cost=cost;s.price=price;
        m.__close(true);
        drawInventory();
        toast('✓ Stock updated: '+qty+' × '+name+' ('+color+', size '+size+', '+$m(price)+') [item '+s.sku+'] saved successfully.');
      } else {
        toast('✕ Stock not updated. '+((res&&res.message)?res.message:'Could not save the changes.'));
      }
    });
  });
}

function confirmDeleteStock(id){
  var s=findStockById(id);
  if(!s){toast('That stock line could not be found. Refresh and try again.');return}
  var m=openModal('delModal',
    '<div class="paper pop bad"><h3>Delete this stock line?</h3>'+
    '<p><b>'+esc(s.name)+'</b> ('+esc(s.color)+', size '+esc(s.size)+', item '+esc(s.sku)+') with '+s.qty+' in stock will be removed from the stock list. This can’t be undone.</p>'+
    '<div style="display:flex;gap:10px"><button class="btn" id="d-no" type="button" style="flex:1">No, keep it</button><button class="btn primary" id="d-yes" type="button" style="flex:1;background:var(--bad);border-color:var(--bad)">Yes, delete</button></div></div>');
  m.querySelector('#d-no').addEventListener('click',function(){m.__close()});
  m.querySelector('#d-no').focus();
  m.querySelector('#d-yes').addEventListener('click',function(){
    var btn=m.querySelector('#d-yes');btn.disabled=true;
    postJSON({action:'delete_stock',id:s.id}).then(function(res){
      if(res&&res.ok){
        var i=stock.indexOf(s);if(i>-1)stock.splice(i,1);
        delete counts[lineKey(s)];
        m.__close(true);
        drawInventory();
        toast('✓ Stock deleted: '+s.name+' ('+s.color+', size '+s.size+') was removed.');
      } else {
        btn.disabled=false;
        m.__close(true);
        toast('✕ Stock not deleted. '+((res&&res.message)?res.message:'Could not delete the stock line.'));
      }
    });
  });
}

$('inv').addEventListener('click',function(e){
  var b=e.target.closest('button[data-edit],button[data-del]');
  if(!b)return;
  if(b.dataset.edit)openEditStock(parseInt(b.dataset.edit,10));
  else confirmDeleteStock(parseInt(b.dataset.del,10));
});

/* ---------- Stock take ---------- */
var counts={};
var lastRunKey='crabkidz_last_stocktake';
var reportData=null;

function lineKey(s){
  return s.id ? ('id:'+s.id) : ('vk:'+variantKey(s.sku,s.color,s.size));
}

function getLastRun(){
  try{var v=localStorage.getItem(lastRunKey);if(v)return new Date(v)}catch(e){}
  return null;
}
function setLastRun(d){
  try{localStorage.setItem(lastRunKey,d.toISOString())}catch(e){}
}
function daysBetween(a,b){
  var ms=b-a;return Math.floor(ms/(1000*60*60*24));
}

function updateWeeklyStatus(){
  var now=new Date();
  var last=getLastRun();
  var lastStr=last?last.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'}):'Never';
  var next=last?new Date(last.getTime()+7*24*60*60*1000):now;
  var nextStr=next.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'});
  var due=false,overdue=false;
  if(!last){due=true}
  else {
    var days=daysBetween(last,now);
    if(days>=7)due=true;
    if(days>14)overdue=true;
  }
  var badge=$('weekStatus');
  if(overdue){badge.innerHTML='<span class="take-badge overdue">Overdue</span>'}
  else if(due){badge.innerHTML='<span class="take-badge due">Due now</span>'}
  else {badge.innerHTML='<span class="take-badge">Up to date</span>'}
  $('lastRun').textContent=lastStr;
  $('nextRun').textContent=nextStr;
}

function drawTake(){
  var q=$('tq').value.toLowerCase();
  var rows=stock.filter(function(s){return !q || (s.name+' '+s.sku+' '+s.color+' '+s.size).toLowerCase().indexOf(q)>-1});
  $('ttbody').innerHTML=rows.map(function(s){
    var lk=lineKey(s);
    var c=counts[lk];
    var d=(c!==undefined)?(c-s.qty):null;
    var diffCell='';
    if(d!==null){
      var cls=d===0?'zero':d<0?'neg':'pos';
      var sign=d>0?'+':'';
      diffCell='<span class="diff-pill '+cls+'">'+sign+d+'</span>';
    }
    var rowCls=(c!==undefined && c!==s.qty)?'row-changed':'';
    return '<tr class="'+rowCls+'"><td>'+esc(s.sku)+'</td><td>'+esc(s.name)+'</td><td>'+esc(s.color)+'</td><td>'+esc(s.size)+'</td><td class="r">'+$m(s.price)+'</td><td class="r">'+s.qty+'</td><td class="r"><input class="cnt" type="number" min="0" inputmode="numeric" data-lk="'+esc(lk)+'" value="'+(c!==undefined?c:'')+'" aria-label="Counted '+esc(s.name)+' '+esc(s.color)+' size '+esc(s.size)+'"></td><td class="r">'+diffCell+'</td></tr>';
  }).join('');
  updateStats();
}

function updateStats(){
  var counted=Object.keys(counts).length;
  var net=0;
  Object.keys(counts).forEach(function(k){
    var s=stock.find(function(x){return lineKey(x)===k});
    if(s) net+=counts[k]-s.qty;
  });
  $('statLines').textContent=stock.length;
  $('statPairs').textContent=stock.reduce(function(t,s){return t+s.qty},0);
  $('statCounted').textContent=counted;
  var dEl=$('statDiff');
  dEl.textContent=(net>0?'+':'')+net;
  dEl.style.color=net<0?'var(--bad)':net>0?'var(--strong)':'var(--good)';
  $('tsum').textContent=counted+' of '+stock.length+' lines counted. Net difference: '+(net>0?'+':'')+net+' pairs.';
}

$('ttbody').addEventListener('input',function(e){
  var k=e.target.dataset.lk;if(!k)return;
  var n=parseInt(e.target.value,10);
  if(isNaN(n)||n<0)delete counts[k];else counts[k]=n;
  var tr=e.target.closest('tr');
  var s=stock.find(function(x){return lineKey(x)===k});
  if(s){
    var c=counts[k];
    if(c!==undefined && c!==s.qty)tr.classList.add('row-changed');else tr.classList.remove('row-changed');
    var d=(c!==undefined)?(c-s.qty):null;
    var cell=tr.querySelector('td:last-child');
    if(d!==null){
      var cls=d===0?'zero':d<0?'neg':'pos';
      var sign=d>0?'+':'';
      cell.innerHTML='<span class="diff-pill '+cls+'">'+sign+d+'</span>';
    } else cell.innerHTML='';
  }
  updateStats();
});
$('tq').addEventListener('input',drawTake);
$('clearTakeBtn').addEventListener('click',function(){counts={};drawTake();toast('Counts cleared')});

function autoWeeklyCheck(){
  updateWeeklyStatus();
  var last=getLastRun();
  var now=new Date();
  if(!last || daysBetween(last,now)>=7){
    toast('Weekly stock take is due — review counts and apply.');
  }
}

function logActivity(event,details){
  try{
    fetch(location.href.split('#')[0],{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'log_activity',event:event,details:details})}).catch(function(){});
  }catch(e){}
}

$('weeklyBtn').addEventListener('click',function(){
  var lines=stock.map(function(s){
    return {id:s.id||0,sku:s.sku,name:s.name,size:s.size,system:s.qty,counted:s.qty,diff:0,gender:s.gender,color:s.color,cost:s.cost,price:s.price};
  });
  var now=new Date();
  setLastRun(now);
  updateWeeklyStatus();
  logActivity('Weekly stock take run',lines.length+' lines recorded');
  buildReport(lines, now, 'Weekly auto stock take');
  toast('Weekly stock take recorded. Report ready below.');
  $('pdfCard').scrollIntoView({behavior:'smooth',block:'start'});
});

$('applyBtn').addEventListener('click',function(){
  var keys=Object.keys(counts);
  if(!keys.length){toast('Enter at least one count first');return}
  var lines=[];
  keys.forEach(function(k){
    var s=stock.find(function(x){return lineKey(x)===k});
    if(!s)return;
    var counted=counts[k];
    var diff=counted-s.qty;
    lines.push({id:s.id||0,sku:s.sku,name:s.name,size:s.size,system:s.qty,counted:counted,diff:diff,gender:s.gender,color:s.color,cost:s.cost,price:s.price});
  });
  var changedLines=lines.filter(function(l){return l.diff!==0}).length;
  var netDiff=lines.reduce(function(t,l){return t+l.diff},0);
  var btn=$('applyBtn');btn.disabled=true;
  fetch(location.href.split('#')[0],{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({
      action:'apply_stocktake',
      items:lines.map(function(l){return {id:l.id,sku:l.sku,color:l.color,size:l.size,counted:l.counted}}),
      details:lines.length+' lines counted, '+changedLines+' changed, net '+(netDiff>0?'+':'')+netDiff+' pairs'
    })})
  .then(function(r){return r.json().catch(function(){return {ok:false,message:'Unexpected response from the server.'}})})
  .catch(function(){return {ok:false,message:'Could not reach the server.'}})
  .then(function(res){
    btn.disabled=false;
    if(!res||!res.ok){toast('✕ Stock take not saved. '+((res&&res.message)?res.message:'Please try again.'));return}
    lines.forEach(function(l){
      var s=stock.find(function(x){return (l.id && x.id===l.id) || variantKey(x.sku,x.color,x.size)===variantKey(l.sku,l.color,l.size)});
      if(s)s.qty=l.counted;
    });
    counts={};
    drawTake();
    var now=new Date();
    setLastRun(now);
    updateWeeklyStatus();
    buildReport(lines, now, 'Stock take');
    toast('✓ Stock take saved. Report ready below.');
    $('pdfCard').scrollIntoView({behavior:'smooth',block:'start'});
  });
});

/* ---------- Report (Download/Print) ---------- */
function buildReport(lines, date, title){
  reportData={lines:lines,date:date,title:title};
  var totalSys=lines.reduce(function(t,l){return t+l.system},0);
  var totalCnt=lines.reduce(function(t,l){return t+l.counted},0);
  var net=totalCnt-totalSys;
  var changed=lines.filter(function(l){return l.diff!==0}).length;
  var valueAtCost=lines.reduce(function(t,l){return t+l.counted*l.cost},0);
  var valueAtPrice=lines.reduce(function(t,l){return t+l.counted*l.price},0);

  var rowsHtml=lines.map(function(l){
    var cls=l.diff===0?'zero':l.diff<0?'neg':'pos';
    var sign=l.diff>0?'+':'';
    return '<tr><td>'+esc(l.sku)+'</td><td>'+esc(l.name)+'</td><td>'+esc(l.gender)+'</td><td>'+esc(l.color)+'</td><td>'+esc(l.size)+'</td><td class="r">'+l.system+'</td><td class="r">'+l.counted+'</td><td class="r"><span class="diff-pill '+cls+'">'+sign+l.diff+'</span></td><td class="r">'+$m(l.cost)+'</td><td class="r">'+$m(l.price)+'</td></tr>';
  }).join('');

  $('pdfPreview').innerHTML=
    '<div class="pdf-head"><div><h3 style="margin:0">Crabkidz — '+esc(title)+'</h3><small>Generated '+date.toLocaleString()+'</small></div><div style="text-align:right"><small>Lines: '+lines.length+'</small><br><small>Changed: '+changed+'</small></div></div>'+
    '<div style="overflow-x:auto"><table><thead><tr><th>Item no.</th><th>Shoe</th><th>Gender</th><th>Color</th><th>Size</th><th class="r">System</th><th class="r">Counted</th><th class="r">Diff</th><th class="r">Cost</th><th class="r">Price</th></tr></thead><tbody>'+rowsHtml+'</tbody></table></div>'+
    '<div class="pdf-tot"><span>Totals</span><span>System '+totalSys+' · Counted '+totalCnt+' · Net '+(net>0?'+':'')+net+'</span></div>'+
    '<div class="pdf-tot"><span>Value at cost</span><span>'+$m(valueAtCost)+'</span></div>'+
    '<div class="pdf-tot"><span>Value at retail</span><span>'+$m(valueAtPrice)+'</span></div>';
  $('pdfCard').style.display='block';
}

$('downloadPdfBtn').addEventListener('click',function(){
  if(!reportData){toast('Generate a report first');return}
  var r=reportData;
  var w=window.open('','_blank');
  if(!w){toast('Allow pop-ups to download or print the report');return}
  var totalSys=r.lines.reduce(function(t,l){return t+l.system},0);
  var totalCnt=r.lines.reduce(function(t,l){return t+l.counted},0);
  var net=totalCnt-totalSys;
  var changed=r.lines.filter(function(l){return l.diff!==0}).length;
  var valueAtCost=r.lines.reduce(function(t,l){return t+l.counted*l.cost},0);
  var valueAtPrice=r.lines.reduce(function(t,l){return t+l.counted*l.price},0);
  var rows=r.lines.map(function(l){
    var sign=l.diff>0?'+':'';
    return '<tr><td>'+esc(l.sku)+'</td><td>'+esc(l.name)+'</td><td>'+esc(l.gender)+'</td><td>'+esc(l.color)+'</td><td>'+esc(l.size)+'</td><td style="text-align:right">'+l.system+'</td><td style="text-align:right">'+l.counted+'</td><td style="text-align:right">'+sign+l.diff+'</td><td style="text-align:right">'+$m(l.cost)+'</td><td style="text-align:right">'+$m(l.price)+'</td></tr>';
  }).join('');
  w.document.write('<!DOCTYPE html><html><head><title>Crabkidz Stock Take Report</title><style>body{font-family:Arial,Helvetica,sans-serif;padding:30px;color:#241A13}h1{font-size:22px;margin:0 0 4px}h2{font-size:15px;color:#6F6259;margin:0 0 18px;font-weight:normal}.meta{display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px;font-size:13px;color:#6F6259}table{width:100%;border-collapse:collapse;font-size:12px}th,td{border-bottom:1px solid #E8E1DB;padding:7px 8px;text-align:left}th{background:#FFE8D4;color:#241A13}.r{text-align:right}.tot{margin-top:14px;font-size:13px}.tot div{display:flex;justify-content:space-between;padding:6px 0;border-top:1px solid #E8E1DB}.tot div:first-child{border-top:2px solid #F26B0F;font-weight:bold}.actions{position:fixed;top:16px;right:16px;display:flex;gap:10px;z-index:10}.actions button{padding:10px 16px;border:1px solid #E8E1DB;background:#fff;border-radius:10px;font-weight:600;cursor:pointer}.actions button.primary{background:#C74A00;border-color:#C74A00;color:#fff}@media print{.actions{display:none}body{padding:0}}</style></head><body>');
  w.document.write('<div class="actions"><button class="primary" onclick="window.print()">Print / Save as PDF</button><button onclick="window.close()">Close</button></div>');
  w.document.write('<h1>Crabkidz — '+esc(r.title)+'</h1><h2>Generated '+r.date.toLocaleString()+'</h2>');
  w.document.write('<div class="meta"><span>Lines: '+r.lines.length+'</span><span>Changed: '+changed+'</span></div>');
  w.document.write('<table><thead><tr><th>Item no.</th><th>Shoe</th><th>Gender</th><th>Color</th><th>Size</th><th class="r">System</th><th class="r">Counted</th><th class="r">Diff</th><th class="r">Cost</th><th class="r">Price</th></tr></thead><tbody>'+rows+'</tbody></table>');
  w.document.write('<div class="tot"><div><span>Totals</span><span>System '+totalSys+' · Counted '+totalCnt+' · Net '+(net>0?'+':'')+net+'</span></div><div><span>Value at cost</span><span>'+$m(valueAtCost)+'</span></div><div><span>Value at retail</span><span>'+$m(valueAtPrice)+'</span></div></div>');
  w.document.write('<p style="margin-top:24px;font-size:11px;color:#6F6259">Generated from Crabkidz Admin · Stock take report</p>');
  w.document.write('</body></html>');
  w.document.close();
  w.focus();
});

$('closePdfBtn').addEventListener('click',function(){$('pdfCard').style.display='none'});

/* ---------- Sign out (centered modal) ---------- */
document.getElementById('signoutBtn').addEventListener('click', function () {
  var m = document.getElementById('signoutModal');
  if (!m) {
    m = document.createElement('div');
    m.id = 'signoutModal';
    m.className = 'modal';
    m.setAttribute('role', 'dialog');
    m.setAttribute('aria-modal', 'true');
    m.innerHTML = '<div class="paper" style="border-top:8px solid var(--orange)"><h3 id="soTitle" style="margin-bottom:6px;text-align:center;font-size:24px">Sign out</h3><p style="margin:0 0 22px;color:var(--muted);text-align:center">Are you sure you want to exit?</p><div style="display:flex;gap:10px;justify-content:center"><button class="btn" id="soNo" type="button" style="flex:1">No, stay</button><button class="btn primary" id="soYes" type="button" style="flex:1">Yes, sign out</button></div></div>';
    document.body.appendChild(m);
    m.querySelector('#soNo').addEventListener('click', function () { m.remove(); });
    m.querySelector('#soYes').addEventListener('click', function () { window.location.href = '../logout.php'; });
    m.addEventListener('click', function (e) { if (e.target === m) m.remove(); });
    m.querySelector('#soNo').focus();
    document.addEventListener('keydown', function escHandler(e){
      if(e.key==='Escape'){ m.remove(); document.removeEventListener('keydown', escHandler); }
    });
  }
});

/* ---------- Live refresh (sales + stock + notifications) ---------- */
var refreshing=false;
function refreshData(){
  if(refreshing)return;refreshing=true;
  fetch(location.href.split('#')[0],{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_data'})})
  .then(function(r){return r.json()})
  .then(function(d){
    refreshing=false;
    if(!d||d.error)return;
    applyLive(d);
    var h=(location.hash||'#dashboard').slice(1);
    if(h==='dashboard')drawDash();else if(h==='sales')drawSales();else if(h==='receipts')drawReceipts();else if(h==='upload')drawInventory();
    else if(h==='stocktake')updateStats();else if(h==='notifications')drawNotifications();
  })
  .catch(function(){refreshing=false});
}
setInterval(refreshData,10*1000);
document.addEventListener('visibilitychange',function(){if(!document.hidden)refreshData()});
window.addEventListener('hashchange',refreshData);

/* ---------- Boot ---------- */
try {
  route();
  autoWeeklyCheck();
  if(dbStockError){toast('✕ '+dbStockError)}
} catch (err) {
  console.error('Admin dashboard boot error:', err);
  var dashEl=$('v-dashboard');
  if(dashEl)dashEl.classList.add('on');
  toast('✕ Dashboard render error: '+(err&&err.message?err.message:'Check console'));
}
setInterval(autoWeeklyCheck, 60*1000);
</script>
</body>
</html>
```[cite: 2]