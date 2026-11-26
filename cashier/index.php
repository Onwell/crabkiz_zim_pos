<?php
// cashier/index.php — Cashier dashboard
declare(strict_types=1);
session_start();

if (empty($_SESSION['user'])) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => 'Please sign in first.'];
    header('Location: ../index.php');
    exit;
}
if ($_SESSION['user']['role'] !== 'Cashier') {
    header('Location: ../admin/index.php');
    exit;
}

$user      = $_SESSION['user'];
$userName  = $user['full_name'] ?? 'Cashier';
$firstName = preg_split('/\s+/', trim($userName))[0] ?? 'Cashier';
$initials  = strtoupper(substr($userName, 0, 1) . (strpos(trim($userName), ' ') !== false ? substr(strrchr(trim($userName), ' '), 1, 1) : ''));
$loginMsg  = $_SESSION['login_ok'] ?? '';
unset($_SESSION['login_ok']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in     = json_decode((string)file_get_contents('php://input'), true);
    $action = is_array($in) ? (string)($in['action'] ?? '') : '';

    $cleanItems = static function ($raw, bool $full): array {
        $out = [];
        if (!is_array($raw) || count($raw) > 100) {
            return $out;
        }
        foreach ($raw as $it) {
            if (!is_array($it)) {
                continue;
            }
            $str = static function (string $k) use ($it): string {
                return (isset($it[$k]) && is_scalar($it[$k])) ? trim((string)$it[$k]) : '';
            };
            $sku   = $str('sku');
            $color = $str('color');
            $size  = $str('size');
            $qty   = $it['qty'] ?? null;
            if ($sku === '' || $color === '' || $size === '' || !is_numeric($qty) || (int)$qty < 1 || (int)$qty > 100000) {
                continue;
            }
            $row = ['sku' => $sku, 'color' => $color, 'size' => $size, 'qty' => (int)$qty];
            if ($full) {
                $price = $it['price'] ?? null;
                $name  = $str('name');
                if ($name === '' || !is_numeric($price) || (float)$price < 0) {
                    continue;
                }
                $row['name']  = mb_substr($name, 0, 120);
                $row['price'] = round((float)$price, 2);
            }
            $out[] = $row;
        }
        return $out;
    };

    try {
        require_once __DIR__ . '/../db.php';
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $ensureTable = static function (PDO $pdo): void {
            $pdo->exec('CREATE TABLE IF NOT EXISTS sale_variants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                receipt_no VARCHAR(40) NOT NULL,
                item_no VARCHAR(40) NOT NULL,
                name VARCHAR(120) NOT NULL,
                color VARCHAR(40) NOT NULL,
                size VARCHAR(10) NOT NULL,
                qty INT NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                KEY idx_receipt (receipt_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        };

        $ensureRestockTable = static function (PDO $pdo): void {
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
        };

        if ($action === 'bootstrap') {
            $ensureTable($pdo);

            // One item no. has one products row per size: match on item no. + size
            $prod = [];
            $prodAny = [];
            foreach ($pdo->query('SELECT id, item_no, name, size, price, stock_qty, initial_qty FROM products ORDER BY id')->fetchAll() as $p) {
                $prod[strtolower((string)$p['item_no'] . '|' . (string)$p['size'])] = $p;
                $prodAny[strtolower((string)$p['item_no'])] = $prodAny[strtolower((string)$p['item_no'])] ?? $p;
            }
            $rows = $pdo->query('SELECT sku, name, gender, color, size, qty, price FROM stock_items ORDER BY sku, color, size')->fetchAll();

            $count = [];
            foreach ($rows as $r) {
                $k = strtolower((string)$r['sku']);
                $count[$k] = ($count[$k] ?? 0) + 1;
            }

            $sold = [];
            foreach ($pdo->query('SELECT item_no, color, size, SUM(qty) AS q FROM sale_variants GROUP BY item_no, color, size')->fetchAll() as $r) {
                $sold[strtolower($r['item_no'] . '|' . $r['color'] . '|' . $r['size'])] = (int)$r['q'];
            }

            $variants = [];
            foreach ($rows as $r) {
                $k = strtolower((string)$r['sku']);
                $p = $prod[$k . '|' . strtolower((string)$r['size'])] ?? ($prodAny[$k] ?? null);
                if (!$p) {
                    continue;
                }
                $single = ($count[$k] ?? 0) === 1;
                $qty    = $single ? (int)$p['stock_qty'] : (int)$r['qty'];
                $s      = $sold[strtolower($r['sku'] . '|' . $r['color'] . '|' . $r['size'])] ?? 0;
                $variants[] = [
                    'sku'        => (string)$r['sku'],
                    'name'       => (string)$r['name'],
                    'gender'     => (string)$r['gender'],
                    'color'      => (string)$r['color'],
                    'size'       => (string)$r['size'],
                    'qty'        => max(0, $qty),
                    'initial'    => $single ? max((int)$p['initial_qty'], $qty) : max(0, $qty) + $s,
                    'price'      => (float)$r['price'],
                    'product_id' => (int)$p['id'],
                    'legacy'     => false,
                ];
            }

            $receipts = [];
            foreach ($pdo->query('SELECT receipt_no, item_no, name, color, size, qty, price FROM sale_variants ORDER BY id DESC LIMIT 4000')->fetchAll() as $r) {
                $receipts[(string)$r['receipt_no']][] = [
                    'item_no' => (string)$r['item_no'],
                    'name'    => (string)$r['name'],
                    'color'   => (string)$r['color'],
                    'size'    => (string)$r['size'],
                    'qty'     => (int)$r['qty'],
                    'price'   => (float)$r['price'],
                ];
            }
            foreach ($receipts as $no => $list) {
                $receipts[$no] = array_reverse($list);
            }

            echo json_encode(['ok' => true, 'variants' => $variants, 'receipts' => (object)$receipts]);
            exit;
        }

        if ($action === 'reserve') {
            $raw   = is_array($in) ? ($in['items'] ?? null) : null;
            $items = $cleanItems($raw, false);
            if (!$items || count($items) !== count((array)$raw)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid items in the sale.']);
                exit;
            }
            $pdo->beginTransaction();
            $sel = $pdo->prepare('SELECT qty FROM stock_items WHERE sku = ? AND color = ? AND size = ? LIMIT 1 FOR UPDATE');
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM stock_items WHERE sku = ?');
            $upd = $pdo->prepare('UPDATE stock_items SET qty = IF(qty >= ?, qty - ?, 0) WHERE sku = ? AND color = ? AND size = ?');
            foreach ($items as $it) {
                $sel->execute([$it['sku'], $it['color'], $it['size']]);
                $have = $sel->fetchColumn();
                if ($have === false) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'error' => 'Item ' . $it['sku'] . ' (' . $it['color'] . ', size ' . $it['size'] . ') is no longer in stock.']);
                    exit;
                }
                $cnt->execute([$it['sku']]);
                $multi = ((int)$cnt->fetchColumn()) > 1;
                if ($multi && (int)$have < $it['qty']) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'error' => 'Only ' . (int)$have . ' left of item ' . $it['sku'] . ' (' . $it['color'] . ', size ' . $it['size'] . ').']);
                    exit;
                }
                $upd->execute([$it['qty'], $it['qty'], $it['sku'], $it['color'], $it['size']]);
            }
            $pdo->commit();
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'release') {
            $items = $cleanItems(is_array($in) ? ($in['items'] ?? null) : null, false);
            $pdo->beginTransaction();
            $add = $pdo->prepare('UPDATE stock_items SET qty = qty + ? WHERE sku = ? AND color = ? AND size = ?');
            foreach ($items as $it) {
                $add->execute([$it['qty'], $it['sku'], $it['color'], $it['size']]);
            }
            $pdo->commit();
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'attach') {
            $receipt = is_array($in) && isset($in['receipt_no']) && is_scalar($in['receipt_no']) ? trim((string)$in['receipt_no']) : '';
            $items   = $cleanItems(is_array($in) ? ($in['items'] ?? null) : null, true);
            if ($receipt === '' || mb_strlen($receipt) > 40 || !$items) {
                echo json_encode(['ok' => false, 'error' => 'Invalid receipt details.']);
                exit;
            }
            $ensureTable($pdo);
            $pdo->beginTransaction();
            $ins = $pdo->prepare('INSERT INTO sale_variants (receipt_no, item_no, name, color, size, qty, price) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($items as $it) {
                $ins->execute([$receipt, $it['sku'], $it['name'], $it['color'], $it['size'], $it['qty'], $it['price']]);
            }
            $pdo->commit();
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'request_restock') {
            $sku   = isset($in['sku']) && is_scalar($in['sku']) ? trim((string)$in['sku']) : '';
            $name  = isset($in['name']) && is_scalar($in['name']) ? trim((string)$in['name']) : '';
            $color = isset($in['color']) && is_scalar($in['color']) ? trim((string)$in['color']) : '';
            $size  = isset($in['size']) && is_scalar($in['size']) ? trim((string)$in['size']) : '';
            $qty   = isset($in['qty']) && is_numeric($in['qty']) ? max(0, (int)$in['qty']) : 0;

            if ($sku === '' || $name === '') {
                echo json_encode(['ok' => false, 'error' => 'Missing item details for restock request.']);
                exit;
            }

            $ensureRestockTable($pdo);
            $reqById = isset($user['id']) && is_numeric($user['id']) ? (int)$user['id'] : null;
            $ins = $pdo->prepare('INSERT INTO restock_requests (item_no, name, color, size, current_qty, requested_by_id, requested_by_name) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([
                mb_substr($sku, 0, 40),
                mb_substr($name, 0, 120),
                mb_substr($color, 0, 40),
                mb_substr($size, 0, 20),
                $qty,
                $reqById,
                mb_substr($userName, 0, 120),
            ]);

            if (file_exists(__DIR__ . '/../log_activity.php')) {
                require_once __DIR__ . '/../log_activity.php';
                if (function_exists('log_activity')) {
                    log_activity($pdo, $user, 'Requested restock', "Item {$sku} ({$name}, {$color}, size {$size}) — {$qty} left");
                }
            }

            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'change_password') {
            $curPass = isset($in['current_password']) && is_string($in['current_password']) ? $in['current_password'] : '';
            $newPass = isset($in['new_password']) && is_string($in['new_password']) ? $in['new_password'] : '';
            $cfmPass = isset($in['confirm_password']) && is_string($in['confirm_password']) ? $in['confirm_password'] : '';

            if ($curPass === '' || $newPass === '' || $cfmPass === '') {
                echo json_encode(['ok' => false, 'error' => 'Please fill in all password fields.']);
                exit;
            }
            if (mb_strlen($newPass) < 6) {
                echo json_encode(['ok' => false, 'error' => 'New password must be at least 6 characters.']);
                exit;
            }
            if ($newPass !== $cfmPass) {
                echo json_encode(['ok' => false, 'error' => 'New passwords do not match.']);
                exit;
            }

            $userId = isset($user['id']) && is_numeric($user['id']) ? (int)$user['id'] : 0;
            if ($userId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Could not identify your account.']);
                exit;
            }

            $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
            $passCol = in_array('password_hash', $cols, true) ? 'password_hash' : (in_array('password', $cols, true) ? 'password' : '');
            if ($passCol === '') {
                echo json_encode(['ok' => false, 'error' => 'Password column not found on users table.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, `{$passCol}` AS p_val FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                echo json_encode(['ok' => false, 'error' => 'User account not found.']);
                exit;
            }

            $stored = (string)($row['p_val'] ?? '');
            $valid = password_verify($curPass, $stored) || hash_equals($stored, $curPass);
            if (!$valid) {
                echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
                exit;
            }

            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE users SET `{$passCol}` = ? WHERE id = ?");
            $upd->execute([$newHash, $userId]);

            if (file_exists(__DIR__ . '/../log_activity.php')) {
                require_once __DIR__ . '/../log_activity.php';
                if (function_exists('log_activity')) {
                    log_activity($pdo, $user, 'Changed password', 'Updated cashier account password');
                }
            }

            echo json_encode(['ok' => true]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Cashier variant action failed (' . $action . '): ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if (empty($_SESSION['activity_login_logged'])) {
    try {
        require_once __DIR__ . '/../db.php';
        require_once __DIR__ . '/../log_activity.php';
        log_activity($pdo, $user, 'Signed in', 'Opened the cashier dashboard');
        $_SESSION['activity_login_logged'] = true;
    } catch (Throwable $e) {
        error_log('Cashier sign-in log failed: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Crabkidz Cashier</title>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@500;700;800&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--orange:#F26B0F;--strong:#C74A00;--tint:#FFE8D4;--bg:#F6F3F0;--surface:#fff;--ink:#241A13;--muted:#6F6259;--line:#E8E1DB;--good:#1F7A4D;--goodbg:#DDF3E6;--bad:#B3261E;--badbg:#FBE0DD;--display:'Bricolage Grotesque','Trebuchet MS',system-ui,sans-serif;--body:'Figtree',system-ui,-apple-system,'Segoe UI',sans-serif;box-sizing:border-box;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}
*,*::before,*::after{box-sizing:inherit}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 var(--body)}
h1,h2,h3{font-family:var(--display);margin:0;letter-spacing:-.01em}
button,input,select{font:inherit;color:inherit}button{cursor:pointer}
:focus-visible{outline:3px solid var(--orange);outline-offset:2px}
.app{display:grid;grid-template-columns:224px minmax(0,1fr);min-height:100vh}
.side{background:var(--orange);color:#1E0F03;padding:22px 16px;display:flex;flex-direction:column;gap:26px;position:sticky;top:0;height:100vh}
.brand{display:flex;align-items:center;gap:10px;font:800 24px var(--display)}
.brand svg{width:34px;height:34px}
nav{display:flex;flex-direction:column;gap:4px}
nav a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:#1E0F03;text-decoration:none;font-weight:600}
nav a:hover{background:rgba(255,255,255,.25)}
nav a[aria-current=page]{background:#1E0F03;color:#FFE8D4}
nav svg{width:20px;height:20px;flex:none}
.who{margin-top:auto;font-size:14px;display:flex;gap:10px;align-items:center}
.avatar{width:38px;height:38px;border-radius:50%;background:#1E0F03;color:#FFE8D4;display:grid;place-items:center;font-weight:700}
.side-actions{display:flex;flex-direction:column;gap:8px;margin-top:10px}
.signout{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:9px 14px;border-radius:10px;font:600 13.5px/1 var(--body);color:#FFE8D4;background:rgba(30,15,3,.85);border:1.5px solid rgba(30,15,3,.85)}
.pwbtn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:9px 14px;border-radius:10px;font:600 13.5px/1 var(--body);color:#1E0F03;background:rgba(255,255,255,.55);border:1.5px solid rgba(30,15,3,.25)}
.pwbtn:hover{background:rgba(255,255,255,.8)}
main{padding:28px clamp(16px,3vw,40px) 48px;min-width:0}
.view{display:none}.view.on{display:block}
.top{display:flex;flex-wrap:wrap;gap:14px;justify-content:space-between;align-items:flex-end;margin-bottom:22px}
.top h1{font-size:clamp(26px,4vw,36px);font-weight:800}
.top p{margin:4px 0 0;color:var(--muted)}
.btn{border:1px solid var(--line);background:var(--surface);padding:10px 16px;border-radius:10px;font-weight:600}
.btn.primary{background:var(--strong);border-color:var(--strong);color:#fff}
.btn.sm{padding:6px 11px;font-size:13px;border-radius:8px}
.btn:disabled{opacity:.45;cursor:not-allowed}
.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}
.kpi{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:16px 18px;min-height:124px;display:flex;flex-direction:column;justify-content:center}
.kpi.hero{background:var(--orange);border-color:var(--orange)}
.kpi small{color:var(--muted);font-weight:500}.kpi.hero small{color:#4A2208}
.kpi b{display:block;font:800 clamp(24px,4vw,32px)/1.15 var(--display);margin-top:6px}
.grid{display:grid;grid-template-columns:3fr 2fr;gap:18px;margin-bottom:18px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:20px;min-width:0;margin-bottom:18px}
.grid .card{margin-bottom:0}
.card h2{font-size:19px;font-weight:700}
.sub{color:var(--muted);font-size:14px;margin:2px 0 14px}
.chart{display:flex;align-items:flex-end;gap:8px;height:180px;padding-top:20px;border-bottom:1px solid var(--line)}
.col{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%;gap:6px}
.bar{flex:0 0 auto;width:100%;max-width:48px;min-height:4px;background:var(--tint);border-radius:8px 8px 0 0}
.col.today .bar{background:var(--orange)}
.col span,.axis div{font-size:12px;color:var(--muted);font-weight:600;white-space:nowrap}
.axis{display:flex;gap:8px;margin-top:6px}.axis div{flex:1;text-align:center}
.tw{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:480px}
th{text-align:left;font-weight:600;color:var(--muted);font-size:14px;padding:8px 10px;border-bottom:1px solid var(--line)}
td{padding:10px;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums}
tr:last-child td{border-bottom:0}
.r{text-align:right}
.pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:13px;font-weight:600;background:var(--goodbg);color:var(--good)}
.pill.low{background:var(--tint);color:var(--strong)}.pill.out{background:var(--badbg);color:var(--bad)}
.tools{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
input,select{border:1px solid var(--line);background:var(--surface);border-radius:10px;padding:9px 12px;min-width:0}
.tools input[type=search]{flex:1;min-width:160px}
.tools input.flt{width:130px}
.sale-dialog{width:min(760px,100%);margin:auto}
.sale-dialog .paper{max-width:none;width:100%;max-height:calc(100vh - 100px);overflow-y:auto;margin:0;padding:24px}
.sale-dialog .mbar{max-width:none;width:100%;margin:0 0 8px}
.sale-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.sale-fields label{display:flex;flex-direction:column;gap:5px;font-weight:600;font-size:14px}
.sale-fields .span2{grid-column:1/-1}
.sale-fields .addcell{display:flex;align-items:flex-end}
.sale-fields .addcell .btn{width:100%}
.var-info{margin:0;min-height:22px;color:var(--muted);font-size:14px}
.sale-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:18px}
.sale-cart{max-height:220px;overflow:auto;margin-top:14px}
.sale-pay{display:grid;grid-template-columns:1fr;gap:12px;margin-top:18px}
.line{display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid var(--line)}
.line .nm{flex:1;min-width:0}
.qty{display:flex;align-items:center;gap:6px}
.qty button{width:28px;height:28px;border-radius:8px;border:1px solid var(--line);background:var(--surface);font-weight:700}
.tot{display:flex;justify-content:space-between;font:800 24px var(--display);margin:14px 0}
.link{background:none;border:0;color:var(--strong);font-weight:600;padding:0;text-decoration:underline}
.neg{color:var(--bad);font-weight:600}
.modal{position:fixed;inset:0;background:rgba(30,15,3,.6);display:none;overflow:auto;padding:20px 12px;z-index:30}
.modal.on{display:flex;flex-direction:column;align-items:center;justify-content:center}
.mbar{max-width:560px;margin:0 auto 12px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
.paper{background:#fff;max-width:560px;margin:0 auto;border-top:8px solid var(--orange);border-radius:6px;padding:28px;font-size:14px;color:#241A13}
.paper .hd{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-bottom:14px}
.paper .meta{text-align:right;color:var(--muted)}
.paper .row{display:flex;justify-content:space-between;gap:10px;padding:3px 0}
.paper .row.t{font:800 20px var(--display);border-top:2px solid #241A13;margin-top:6px;padding-top:8px}
.paper hr{border:0;border-top:1px dashed #999;margin:12px 0}
.form-stack{display:flex;flex-direction:column;gap:12px;margin-top:12px}
.form-stack label{display:flex;flex-direction:column;gap:5px;font-weight:600;font-size:14px}
.toast{position:fixed;left:50%;bottom:calc(20px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);background:#1E0F03;color:#FFE8D4;padding:12px 18px;border-radius:12px;font-weight:600;opacity:0;pointer-events:none;transition:opacity .2s;z-index:60;max-width:90vw}
.toast.on{opacity:1}
@media(max-width:980px){.grid{grid-template-columns:1fr}}
@media(max-width:760px){.app{grid-template-columns:1fr}.cards{grid-template-columns:1fr}.side{position:sticky;height:auto;flex-direction:row;align-items:center;padding:10px 14px;gap:10px;z-index:5}.brand span,nav a span,.who>div:last-child{display:none}.signout span,.pwbtn span{display:none}nav{flex-direction:row}nav a{padding:10px}}
</style>
</head>
<body>
<div class="app">
<aside class="side">
  <div class="brand"><span>crabkidz</span></div>
  <nav id="nav" aria-label="Main"></nav>
  <div class="who">
    <div class="avatar"><?= htmlspecialchars($initials ?: 'CK') ?></div>
    <div><b><?= htmlspecialchars($userName) ?></b><br>Cashier</div>
  </div>
  <div class="side-actions">
    <button type="button" class="pwbtn" id="pwBtn"><span>Change password</span></button>
    <button type="button" class="signout" id="signoutBtn"><span>Sign out</span></button>
  </div>
</aside>
<main>
<?php if ($loginMsg): ?>
  <div style="margin:0 0 18px;padding:14px 16px;border-radius:12px;background:#DDF3E6;color:#1F7A4D;font:600 15px/1.4 var(--body)">
    <?= htmlspecialchars($loginMsg) ?>
  </div>
<?php endif; ?>

<section class="view" id="v-dashboard">
  <div class="top"><div><h1>Hi <?= htmlspecialchars($firstName) ?></h1><p id="today"></p></div><button class="btn primary" type="button" data-sale-open>New sale</button></div>
  <div class="cards" id="cards"></div>
  <div class="grid">
    <div class="card"><h2>Total sales per day</h2><p class="sub" id="csub"></p><div class="chart" id="chart" role="img" aria-label="Bar chart of total sales for the last 7 days"></div><div class="axis" id="axis"></div></div>
    <div class="card"><h2>Running low</h2><p class="sub" id="lowSub">5 pairs or fewer left</p><div class="tw"><table style="min-width:0"><tbody id="low"></tbody></table></div></div>
  </div>
  <div class="card"><h2>Latest sales</h2><p class="sub">Open a sale receipt to print it or save it as a PDF.</p><div class="tw"><table><tbody id="latest"></tbody></table></div></div>
</section>

<section class="view" id="v-sale">
  <div class="top"><div><h1>Stock &amp; price lookup</h1><p>Search by item no., product, color, or size to check price and quantity quickly.</p></div><button class="btn primary" type="button" data-sale-open>New sale</button></div>
  <div class="card">
    <div class="tools">
      <input type="search" id="vq" placeholder="Search item no., product, color or size" aria-label="Search stock">
      <input type="text" class="flt" id="vColor" placeholder="Color (e.g. Navy)" aria-label="Filter by color">
      <input type="text" class="flt" id="vSize" placeholder="Size (e.g. 30)" aria-label="Filter by size">
    </div>
    <div class="tw"><table><thead><tr><th>Gender</th><th>Item no.</th><th>Product</th><th>Color</th><th>Size</th><th class="r">Price</th><th>Current stock</th></tr></thead><tbody id="stockRows"></tbody></table></div>
  </div>
</section>

<section class="view" id="v-documents">
  <div class="top"><div><h1>Sales receipts</h1><p>Open a receipt to print it or save it as a PDF.</p></div></div>
  <div class="card"><div class="tools"><input type="search" id="dq" placeholder="Search receipt number or customer" aria-label="Search sales receipts"></div>
  <div class="tw"><table><thead><tr><th>Receipt</th><th>Date</th><th>Customer</th><th>Payment</th><th class="r">Total</th><th></th></tr></thead><tbody id="dtb"></tbody></table></div></div>
</section>
</main>
</div>

<div class="modal sale-modal" id="saleModal" role="dialog" aria-modal="true" aria-labelledby="saleTitle">
  <div class="sale-dialog">
    <div class="mbar"><button class="btn" type="button" id="saleClose">Close</button></div>
    <form class="paper" id="saleForm">
      <div><h3 id="saleTitle">New sale</h3><p class="sub">Enter an item number, pick its color and size, then add it to the sale.</p></div>
      <div class="sale-fields">
        <label class="span2">Customer<select id="cust"><option>Walk-in customer</option><option>Non-walk-in customer</option></select></label>
        <label class="span2">Item number<input id="itemNo" type="text" inputmode="numeric" autocomplete="off" placeholder="Enter the item number"></label>
        <p class="var-info span2" id="varInfo" aria-live="polite">Enter an item number to see its colors and sizes.</p>
        <label>Color<select id="color"><option value="">Enter item number first</option></select></label>
        <label>Size<select id="size"><option value="">Enter item number first</option></select></label>
        <label>Quantity (pairs)<input id="qty" type="number" min="1" step="1" value="1" inputmode="numeric"></label>
        <div class="addcell"><button class="btn primary" type="button" id="addItem" disabled>Add to sale</button></div>
      </div>
      <div class="sale-cart" id="cart"></div>
      <div class="sale-pay">
        <label>Cash received ($)<input id="cash" type="number" min="0" step="0.01" inputmode="decimal"></label>
      </div>
      <p id="chg" class="sub" style="margin:8px 0" aria-live="polite"></p>
      <p id="serr" class="neg" role="alert" style="margin:8px 0"></p>
      <div class="sale-actions"><div class="tot"><span>Total</span><span id="ctot">$0.00</span></div><button class="btn primary" id="done" type="button" disabled>Complete sale</button></div>
    </form>
  </div>
</div>

<div class="modal" id="modal" role="dialog" aria-modal="true" aria-label="Document preview">
  <div class="mbar"><button class="btn primary" id="prt">Print / Save as PDF</button><button class="btn" id="cls">Close</button></div>
  <div class="paper" id="doc"></div>
</div>
<div class="modal" id="notifModal" role="dialog" aria-modal="true" aria-labelledby="notifTitle">
  <div class="paper" style="max-width:480px;width:100%">
    <h3 id="notifTitle">New stock uploaded</h3>
    <p class="sub" id="notifSub" style="margin:4px 0 12px"></p>
    <div id="notifList" style="max-height:50vh;overflow:auto"></div>
    <div style="display:flex;justify-content:flex-end;margin-top:16px"><button class="btn primary" type="button" id="notifOk">Got it</button></div>
  </div>
</div>
<div class="modal" id="pwModal" role="dialog" aria-modal="true" aria-labelledby="pwTitle">
  <div class="paper" style="max-width:420px;width:100%">
    <h3 id="pwTitle">Change password</h3>
    <p class="sub" style="margin:4px 0 8px">Update your cashier login password.</p>
    <form class="form-stack" id="pwForm">
      <label>Current password<input id="curPw" type="password" autocomplete="current-password" required></label>
      <label>New password<input id="newPw" type="password" minlength="6" autocomplete="new-password" required></label>
      <label>Confirm new password<input id="cfmPw" type="password" minlength="6" autocomplete="new-password" required></label>
      <p id="pwErr" class="neg" role="alert" style="margin:2px 0"></p>
      <div style="display:flex;gap:10px;margin-top:6px">
        <button class="btn" type="button" id="pwCancel" style="flex:1">Cancel</button>
        <button class="btn primary" type="submit" id="pwSave" style="flex:1">Save password</button>
      </div>
    </form>
  </div>
</div>
<div class="modal" id="signoutModal" role="dialog" aria-modal="true" aria-labelledby="soTitle">
  <div class="paper" style="max-width:420px;width:100%">
    <h3 id="soTitle" style="text-align:center">Sign out</h3>
    <p class="sub" style="margin:6px 0 20px;text-align:center">Are you sure you want to exit?</p>
    <div style="display:flex;gap:10px"><button class="btn" type="button" id="soNo" style="flex:1">No, stay</button><button class="btn primary" type="button" id="soYes" style="flex:1">Yes, sign out</button></div>
  </div>
</div>
<div class="toast" id="toast" role="status"></div>

<script>
var $=function(i){return document.getElementById(i)};
function $m(n){return '$'+(+n||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function toast(m){var t=$('toast');t.textContent=m;t.classList.add('on');clearTimeout(toast.h);toast.h=setTimeout(function(){t.classList.remove('on')},3200)}
function fmtD(d){return new Date(d).toLocaleDateString(undefined,{day:'numeric',month:'short',year:'numeric'})}
function fmtT(d){return new Date(d).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}
function pill(q){return q===0?'<span class="pill out">Out</span>':q<=5?'<span class="pill low">'+q+' left</span>':'<span class="pill">'+q+'</span>'}

var stock=[], sales=[], hist=[0,0,0,0,0,0], todayData={sales:[],total:0,pairs:0,cash:0};
var variants=[], recVars={}, variantsLoaded=false, notifIds=[], requestedRestock={};

function postJson(body){
  return fetch(location.href.split('#')[0],{
    method:'POST',
    credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(body)
  }).then(function(r){
    return r.json().catch(function(){return {ok:false,error:'Invalid JSON response from server.'}});
  }).catch(function(){
    return {ok:false,error:'Could not reach the server.'};
  });
}

function applyBootstrap(b){
  if(b && b.ok){
    variants = Array.isArray(b.variants) ? b.variants : [];
    recVars  = (b.receipts && typeof b.receipts === 'object') ? b.receipts : {};
  } else {
    variants = [];
    recVars  = {};
  }
  variantsLoaded = true;
}

function mergeReceiptVariants(){
  sales.forEach(function(s){
    var v = recVars[String(s.no)];
    if(v && v.length){
      s.items = v.map(function(i){return {item_no:i.item_no,name:i.name,color:i.color,size:i.size,qty:i.qty,price:i.price}});
    }
  });
}

function loadData(){
  return Promise.all([
    fetch('api_get_data.php',{credentials:'same-origin'}).then(function(r){
      return r.json().catch(function(){return {error:'Invalid data response'}});
    }),
    postJson({action:'bootstrap'})
  ]).then(function(res){
    var data = res[0];
    if(!data || data.error){
      toast('Database sync issue: '+((data&&data.error)||'Check api_get_data.php'));
      return;
    }
    stock     = Array.isArray(data.stock) ? data.stock : [];
    sales     = Array.isArray(data.sales) ? data.sales : [];
    hist      = Array.isArray(data.hist) ? data.hist.map(function(x){return +x||0}) : [0,0,0,0,0,0];
    todayData = data.today || {sales:[],total:0,pairs:0,cash:0};
    applyBootstrap(res[1]);
    mergeReceiptVariants();
    route();
    if($('saleModal').classList.contains('on'))drawSale();
    showStockNotifications(data.notifications || []);
  }).catch(function(err){
    console.error('Error loading data:', err);
    toast('Could not load dashboard data.');
  });
}

function refreshVariants(){
  if(document.hidden)return;
  postJson({action:'bootstrap'}).then(function(b){
    if(!b || !b.ok)return;
    applyBootstrap(b);
    var h=(location.hash||'#dashboard').slice(1);
    if(h==='sale')drawStockRows();else if(h==='dashboard')drawDash();
    if($('saleModal').classList.contains('on'))refreshPickers();
  });
}

function showStockNotifications(list){
  if(!Array.isArray(list) || !list.length || $('notifModal').classList.contains('on'))return;
  notifIds = list.map(function(n){return parseInt(n.id,10)});
  var pairs = list.reduce(function(t,n){return t+(parseInt(n.pairs,10)||0)},0);
  $('notifSub').textContent = pairs+' new pair'+(pairs===1?'':'s')+' added to the store stock.';
  $('notifList').innerHTML = list.map(function(n){
    return '<div style="padding:12px 0;border-bottom:1px solid var(--line)">'
      +'<div>New stock uploaded by <b>'+esc(n.uploaded_by_name)+'</b></div>'
      +'<small style="color:var(--muted)">'+fmtD(n.created_at)+' '+fmtT(n.created_at)+'</small>'
      +'<div style="margin-top:6px;white-space:pre-line">'+esc(n.summary||'')+'</div>'
      +'</div>';
  }).join('');
  $('notifModal').classList.add('on');
  $('notifOk').focus();
}

function dismissNotifications(){
  $('notifModal').classList.remove('on');
  var ids = notifIds; notifIds = [];
  if(!ids.length)return;
  fetch('api_mark_notifications_read.php',{
    method:'POST',
    credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ids:ids})
  }).catch(function(err){console.error('Could not mark notifications read:',err)});
}
$('notifOk').addEventListener('click',dismissNotifications);

var views=[
  ['dashboard','Dashboard','<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>'],
  ['sale','Stock lookup','<path d="M6 6h15l-2 9H8L6 3H3M9 20h.01M18 20h.01"/>'],
  ['documents','Sales receipts','<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-3 2zM9 8h6M9 12h6"/>']
];
$('nav').innerHTML=views.map(function(v){
  return '<a href="#'+v[0]+'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+v[2]+'</svg><span>'+v[1]+'</span></a>';
}).join('');

function route(){
  var h=(location.hash||'#dashboard').slice(1);
  if(!views.some(function(v){return v[0]===h}))h='dashboard';
  views.forEach(function(v){$('v-'+v[0]).classList.toggle('on',v[0]===h)});
  document.querySelectorAll('#nav a').forEach(function(a){
    if(a.getAttribute('href')==='#'+h)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');
  });
  ({dashboard:drawDash,sale:drawStockRows,documents:drawSales})[h]();
  window.scrollTo(0,0);
}
window.addEventListener('hashchange',route);

document.addEventListener('click',function(e){
  var sale=e.target.closest('[data-sale-open]'),g=e.target.closest('[data-go]'),o=e.target.closest('[data-open]'),rst=e.target.closest('[data-restock]');
  if(sale){e.preventDefault();openSale();}
  if(g)location.hash=g.dataset.go;
  if(o)openDoc(o.dataset.open);
  if(rst)requestRestock(+rst.dataset.restock, rst);
});

function saleRow(s){
  return '<tr><td><b>'+esc(s.no)+'</b></td><td>'+fmtD(s.date)+' '+fmtT(s.date)+'</td><td>'+esc(s.customer)+'</td><td>'+esc(s.pay)+'</td><td class="r">'+$m(s.total_amount)+'</td><td class="r"><button class="link" data-open="'+esc(s.no)+'">Open</button></td></tr>';
}

function drawStockRows(){
  var q=($('vq').value||'').trim().toLowerCase();
  var c=($('vColor').value||'').trim().toLowerCase();
  var s=($('vSize').value||'').trim().toLowerCase();
  var rows=variants.filter(function(v){
    var matchQ=!q || (v.sku+' '+v.name+' '+v.color+' '+v.size+' '+v.gender).toLowerCase().indexOf(q)>-1;
    var matchC=!c || String(v.color).toLowerCase().indexOf(c)>-1;
    var matchS=!s || String(v.size).toLowerCase()===s || String(v.size).toLowerCase().indexOf(s)>-1;
    return matchQ && matchC && matchS;
  });
  $('stockRows').innerHTML=rows.map(function(v){
    return '<tr><td>'+esc(v.gender)+'</td><td><b>'+esc(v.sku)+'</b></td><td>'+esc(v.name)+'</td><td>'+esc(v.color)+'</td><td>'+esc(v.size)+'</td><td class="r">'+$m(v.price)+'</td><td>'+pill(v.qty)+'</td></tr>';
  }).join('')||'<tr><td colspan="7">'+(variants.length?'No stock lines match your search.':'No stock has been added to the store yet.')+'</td></tr>';
}
$('vq').addEventListener('input',drawStockRows);
$('vColor').addEventListener('input',drawStockRows);
$('vSize').addEventListener('input',drawStockRows);

var currentLowItems = [];
function requestRestock(idx, btn){
  var v = currentLowItems[idx];
  if(!v)return;
  var key = (v.sku+'|'+v.color+'|'+v.size).toLowerCase();
  if(requestedRestock[key])return;
  if(btn){btn.disabled=true;btn.textContent='Sending...';}
  postJson({
    action:'request_restock',
    sku:v.sku,
    name:v.name,
    color:v.color,
    size:v.size,
    qty:v.qty
  }).then(function(res){
    if(!res || !res.ok){
      if(btn){btn.disabled=false;btn.textContent='Request restock';}
      toast((res&&res.error)||'Could not send restock request.');
      return;
    }
    requestedRestock[key]=true;
    if(btn){btn.disabled=true;btn.textContent='Requested';}
    toast('Restock request sent to admin for '+v.name+' (size '+v.size+').');
  });
}

function drawDash(){
  var tot   = +todayData.total || 0;
  var pairs = +todayData.pairs || 0;
  var cash  = +todayData.cash  || 0;

  $('today').textContent = fmtD(new Date()) + '. Here’s your shift so far.';
  $('cards').innerHTML =
    '<div class="kpi hero"><small>Sales today</small><b>'+$m(tot)+'</b></div>'
    +'<div class="kpi"><small>Pairs sold today</small><b>'+pairs+'</b></div>'
    +'<div class="kpi"><small>Cash in drawer today</small><b>'+$m(cash)+'</b></div>';

  var v  = hist.slice(0,6).concat([tot]);
  var mx = Math.max.apply(null,v) || 1;
  $('chart').innerHTML = v.map(function(x,i){
    var px = Math.max(4, Math.round((x / mx) * 135));
    return '<div class="col'+(i===6?' today':'')+'"><span>$'+Math.round(x)+'</span><div class="bar" style="height:'+px+'px"></div></div>';
  }).join('');
  $('axis').innerHTML = v.map(function(x,i){
    var d = new Date(Date.now() - (6 - i) * 864e5);
    return '<div>'+(i===6?'Today':d.toLocaleDateString(undefined,{weekday:'short'}))+'</div>';
  }).join('');

  var avg = hist.slice(0,6).reduce(function(a,b){return a+b},0) / 6;
  $('csub').textContent = avg === 0
    ? 'No sales recorded in the past 6 days.'
    : 'Today is '+(tot >= avg ? 'above' : 'below')+' the 6-day average of '+$m(avg);

  var low = variants.filter(function(v){return v.qty <= 5}).sort(function(a,b){return a.qty - b.qty});
  currentLowItems = low;
  if(!variants.length){
    $('lowSub').textContent = 'Add products to start tracking stock';
    $('low').innerHTML = '<tr><td colspan="2" style="text-align:center;color:var(--muted);padding:24px 10px;">No products have been added to the store yet.</td></tr>';
  } else if(!low.length){
    $('lowSub').textContent = 'No items are running low right now';
    $('low').innerHTML = '<tr><td colspan="2" style="text-align:center;color:var(--muted);padding:24px 10px;">All products are well stocked.</td></tr>';
  } else {
    $('lowSub').textContent = low.length+' line'+(low.length===1?'':'s')+' running low (5 pairs or fewer left)';
    $('low').innerHTML = low.map(function(v, idx){
      var rkey = (v.sku+'|'+v.color+'|'+v.size).toLowerCase();
      var done = !!requestedRestock[rkey];
      return '<tr><td>'+esc(v.name)+'<br><small style="color:var(--muted)">Item '+esc(v.sku)+' · '+esc(v.color)+' · size '+esc(v.size)+'</small></td><td class="r">'+pill(v.qty)+' <button type="button" class="btn sm" data-restock="'+idx+'"'+(done?' disabled':'')+'>'+(done?'Requested':'Request restock')+'</button></td></tr>';
    }).join('');
  }

  $('latest').innerHTML = sales.slice(0,5).map(saleRow).join('') || '<tr><td colspan="6">No sales recorded yet.</td></tr>';
}

var cart=[];
function variantsFor(no){var k=String(no||'').trim().toLowerCase();return k?variants.filter(function(v){return String(v.sku).toLowerCase()===k}):[]}
function vkey(v){return (String(v.sku)+'|'+String(v.color)+'|'+String(v.size)).toLowerCase()}
function inCart(key){return cart.reduce(function(t,c){return t+(c.key===key?c.qty:0)},0)}
function avail(v){return v.qty-inCart(vkey(v))}
function pickedVariant(){
  var c=$('color').value,s=$('size').value;
  return variantsFor($('itemNo').value).find(function(v){return v.color===c&&String(v.size)===s})||null;
}
function refreshPickers(){
  var no=$('itemNo').value.trim(),list=variantsFor(no),info=$('varInfo'),cs=$('color'),ss=$('size'),add=$('addItem');
  var curC=cs.value,curS=ss.value;
  function none(msg){
    var ph='<option value="">'+(no&&variantsLoaded?'Not available':'Enter item number first')+'</option>';
    cs.innerHTML=ph;ss.innerHTML=ph;info.textContent=msg;add.disabled=true;
  }
  if(!no){none('Enter an item number to see its colors and sizes.');return;}
  if(!variantsLoaded){none('Loading stock, please wait…');return;}
  if(!list.length){none('That item number is not in the stock list.');return;}
  var ok=list.filter(function(v){return avail(v)>0});
  if(!ok.length){none(list[0].name+': every line of this item is sold out.');return;}
  var colors=[];ok.forEach(function(v){if(colors.indexOf(v.color)<0)colors.push(v.color)});
  cs.innerHTML=colors.map(function(c){return '<option value="'+esc(c)+'">'+esc(c)+'</option>'}).join('');
  if(colors.indexOf(curC)>-1)cs.value=curC;
  var sizes=ok.filter(function(v){return v.color===cs.value});
  ss.innerHTML=sizes.map(function(v){return '<option value="'+esc(v.size)+'">'+esc(v.size)+' — '+avail(v)+' left</option>'}).join('');
  if(sizes.some(function(v){return String(v.size)===curS}))ss.value=curS;
  var v=pickedVariant();
  info.innerHTML=v?'<b>'+esc(v.name)+'</b> · '+esc(v.gender)+' · '+$m(v.price)+' per pair · '+avail(v)+' in stock':'';
  add.disabled=!v;
}
function drawSale(){refreshPickers();drawCart()}
function openSale(){$('qty').value='1';drawSale();$('serr').textContent='';$('saleModal').classList.add('on');$('cust').focus()}
function closeSale(){$('saleModal').classList.remove('on')}
function cartTotal(){return cart.reduce(function(t,c){return t+c.qty*c.price},0)}
function drawCart(){
  $('cart').innerHTML=cart.length?cart.map(function(c,index){
    return '<div class="line"><div class="nm">Item '+esc(c.item_no)+' · '+esc(c.name)+'<br><small>'+esc(c.color)+' · Size '+esc(c.size)+' · '+$m(c.price)+' per pair</small></div><div class="qty"><button type="button" data-cart-dec="'+index+'" aria-label="Remove one">−</button><b>'+c.qty+'</b><button type="button" data-cart-inc="'+index+'" aria-label="Add one"'+(c.qty>=c.stock_qty?' disabled':'')+'>+</button></div></div>';
  }).join(''):'<p class="sub">No items added yet.</p>';
  $('ctot').textContent=$m(cartTotal());$('done').disabled=!cart.length;chg();
}
function chg(){
  var v=parseFloat($('cash').value),t=cartTotal();
  $('chg').textContent=!isNaN(v)&&cart.length?(v>=t?'Change to give back: '+$m(v-t):'Short by '+$m(t-v)):'';
}
function addItemToSale(){
  var no=$('itemNo').value.trim();$('serr').textContent='';
  if(!no)return;
  var v=pickedVariant();
  if(!v){$('serr').textContent=variantsFor(no).length?'Choose a color and size.':'That item number is not in the stock list.';return;}
  var q=parseInt($('qty').value,10),a=avail(v);
  if(isNaN(q)||q<1){$('serr').textContent='Enter a quantity of 1 or more.';return;}
  if(q>a){$('serr').textContent='Only '+a+' pair'+(a===1?'':'s')+' left of that color and size.';return;}
  var key=vkey(v),ex=cart.find(function(c){return c.key===key});
  if(ex){ex.qty+=q;ex.stock_qty=v.qty;ex.price=v.price}
  else cart.push({key:key,id:v.product_id,item_no:v.sku,name:v.name,qty:q,gender:v.gender,color:v.color,size:String(v.size),price:v.price,stock_qty:v.qty});
  $('itemNo').value='';$('qty').value='1';refreshPickers();drawCart();$('itemNo').focus();
}
['input','change','keyup','paste','blur'].forEach(function(ev){$('itemNo').addEventListener(ev,function(){setTimeout(refreshPickers,0)})});
$('color').addEventListener('change',refreshPickers);
$('size').addEventListener('change',refreshPickers);
$('addItem').addEventListener('click',addItemToSale);
$('saleForm').addEventListener('submit',function(e){e.preventDefault()});
document.addEventListener('click',function(e){
  var dec=e.target.closest('[data-cart-dec]'),inc=e.target.closest('[data-cart-inc]');
  if(dec){var index=+dec.dataset.cartDec;cart[index].qty--;if(!cart[index].qty)cart.splice(index,1);drawSale()}
  if(inc){var item=cart[+inc.dataset.cartInc];if(item.qty<item.stock_qty)item.qty++;drawSale()}
});
$('cash').addEventListener('input',chg);

function failSale(msg){$('done').disabled=false;$('done').textContent='Complete sale';$('serr').textContent=msg}
$('done').addEventListener('click',function(){
  var t=cartTotal(),cashV=parseFloat($('cash').value);$('serr').textContent='';
  if(!cart.length)return;
  if(!isNaN(cashV)&&cashV<t){$('serr').textContent='Cash received is less than the total.';return;}

  $('done').disabled=true;
  $('done').textContent='Saving...';

  var sold=cart.slice();
  var vitems=sold.map(function(c){return {sku:c.item_no,color:c.color,size:c.size,qty:c.qty}});
  var payload={
    customer:$('cust').value,
    total:t,
    cash:isNaN(cashV)?null:cashV,
    change:isNaN(cashV)?null:(cashV-t),
    pay:'Cash',
    items:sold.map(function(c){return {id:c.id,qty:c.qty,price:c.price,color:c.color,size:c.size}})
  };

  postJson({action:'reserve',items:vitems}).then(function(r){
    if(!r||!r.ok){failSale((r&&r.error)||'Could not reserve the stock.');refreshVariants();return;}
    return fetch('api_save_sale.php',{
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(payload)
    }).then(function(x){
      return x.json().catch(function(){return {error:'Invalid response from api_save_sale.php'}});
    }).then(function(res){
      if(!res || res.error){
        postJson({action:'release',items:vitems}).then(function(){refreshVariants()});
        failSale((res&&res.error)||'Could not save sale.');
        return;
      }
      return postJson({
        action:'attach',
        receipt_no:String(res.receipt_no),
        items:sold.map(function(c){return {sku:c.item_no,name:c.name,color:c.color,size:c.size,qty:c.qty,price:c.price}})
      }).then(function(){
        $('done').disabled=false;$('done').textContent='Complete sale';
        toast('Sale complete. Receipt '+res.receipt_no+' saved.');
        cart=[];$('cust').value='Walk-in customer';$('cash').value='';$('itemNo').value='';$('qty').value='1';
        closeSale();
        return loadData().then(function(){openDoc(String(res.receipt_no))});
      });
    }).catch(function(){
      postJson({action:'release',items:vitems});
      failSale('Network error while saving sale.');
    });
  });
});

function drawSales(){
  var q=$('dq').value.toLowerCase();
  $('dtb').innerHTML=sales.filter(function(s){
    return !q || (s.no+' '+s.customer).toLowerCase().indexOf(q)>-1;
  }).map(saleRow).join('')||'<tr><td colspan="6">No sales match.</td></tr>';
}
$('dq').addEventListener('input',drawSales);

var cur=null;
function openDoc(no){
  cur=sales.find(function(s){return String(s.no)===String(no)});
  if(!cur)return;
  var d=cur;
  $('doc').innerHTML='<div class="hd"><div><h3>crabkidz</h3><div style="color:var(--muted)">Shoes for little feet</div></div><div class="meta"><b>Receipt '+esc(d.no)+'</b>'+fmtD(d.date)+', '+fmtT(d.date)+'</div></div>'
    +'<div>Customer: '+esc(d.customer)+'<br>Cashier: '+esc(d.cashier||'N/A')+'</div>'
    +'<table><thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Amount</th></tr></thead><tbody>'
    +(d.items||[]).map(function(i){
      return '<tr><td>'+(i.item_no?'Item '+esc(i.item_no)+' · ':'')+esc(i.name)+(i.color&&i.color!=='—'?', '+esc(i.color):'')+', size '+esc(i.size)+'</td><td class="r">'+i.qty+'</td><td class="r">'+$m(i.price)+'</td><td class="r">'+$m(i.qty*i.price)+'</td></tr>';
    }).join('')+'</tbody></table>'
    +'<div class="row t"><span>Total</span><span>'+$m(d.total_amount)+'</span></div>'
    +'<div class="row"><span>Paid by</span><span>'+esc(d.pay)+'</span></div>'
    +(d.cash_received!==null&&d.cash_received!==undefined?'<div class="row"><span>Cash received</span><span>'+$m(+d.cash_received)+'</span></div><div class="row"><span>Change</span><span>'+$m(+d.change_given)+'</span></div>':'')
    +'<hr><div style="text-align:center;color:var(--muted)">Thank you for shopping at Crabkidz!</div>';
  $('modal').classList.add('on');
  $('prt').focus();
}
function closeDoc(){$('modal').classList.remove('on');route()}
$('cls').addEventListener('click',closeDoc);
$('saleClose').addEventListener('click',closeSale);
$('prt').addEventListener('click',function(){window.print()});

function openPw(){
  $('pwForm').reset();
  $('pwErr').textContent='';
  $('pwModal').classList.add('on');
  $('curPw').focus();
}
function closePw(){
  $('pwModal').classList.remove('on');
  $('pwBtn').focus();
}
$('pwBtn').addEventListener('click',openPw);
$('pwCancel').addEventListener('click',closePw);
$('pwModal').addEventListener('click',function(e){if(e.target===this)closePw()});
$('pwForm').addEventListener('submit',function(e){
  e.preventDefault();
  $('pwErr').textContent='';
  var curP=$('curPw').value, newP=$('newPw').value, cfmP=$('cfmPw').value;
  if(newP.length<6){$('pwErr').textContent='New password must be at least 6 characters.';return;}
  if(newP!==cfmP){$('pwErr').textContent='New passwords do not match.';return;}
  $('pwSave').disabled=true;
  $('pwSave').textContent='Saving...';
  postJson({
    action:'change_password',
    current_password:curP,
    new_password:newP,
    confirm_password:cfmP
  }).then(function(res){
    $('pwSave').disabled=false;
    $('pwSave').textContent='Save password';
    if(!res || !res.ok){
      $('pwErr').textContent=(res&&res.error)||'Could not update password.';
      return;
    }
    closePw();
    toast('Password updated.');
  });
});

function openSignout(){$('signoutModal').classList.add('on');$('soNo').focus()}
function closeSignout(){$('signoutModal').classList.remove('on');$('signoutBtn').focus()}
$('signoutBtn').addEventListener('click',openSignout);
$('soNo').addEventListener('click',closeSignout);
$('soYes').addEventListener('click',function(){window.location.href='../logout.php'});
$('signoutModal').addEventListener('click',function(e){if(e.target===this)closeSignout()});
document.addEventListener('keydown',function(e){
  if(e.key==='Escape'){
    if($('signoutModal').classList.contains('on'))closeSignout();
    else if($('pwModal').classList.contains('on'))closePw();
  }
});

loadData();
setInterval(loadData,15000);
document.addEventListener('visibilitychange',function(){if(!document.hidden)loadData()});
</script>
</body>
</html>