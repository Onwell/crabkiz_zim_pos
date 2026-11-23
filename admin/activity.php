<?php
// admin/activity.php — System activity log
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

require __DIR__ . '/../db.php';   // provides $pdo

$user      = $_SESSION['user'];
$userName  = $user['full_name'] ?? 'Admin';
$initials  = strtoupper(
    substr($userName, 0, 1) .
    (strpos(trim($userName), ' ') !== false
        ? substr(strrchr(trim($userName), ' '), 1, 1)
        : '')
);

const PER_PAGE = 10;

$fUser   = isset($_GET['user'])   && is_string($_GET['user'])   ? trim($_GET['user'])   : '';
$fAction = isset($_GET['action']) && is_string($_GET['action']) ? trim($_GET['action']) : '';
$page    = max(1, (int)($_GET['page'] ?? 1));

$rows = [];
$total = 0;
$userOptions = [];
$actionOptions = [];
$loadError = '';

try {
    $where  = [];
    $params = [];
    if ($fUser !== '')   { $where[] = 'user_name = ?'; $params[] = $fUser; }
    if ($fAction !== '') { $where[] = 'action = ?';    $params[] = $fAction; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log $whereSql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $pages = max(1, (int)ceil($total / PER_PAGE));
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * PER_PAGE;

    // Latest first
    $stmt = $pdo->prepare(
        "SELECT id, user_name, user_role, action, details, ip_address, created_at
           FROM activity_log $whereSql
          ORDER BY created_at DESC, id DESC
          LIMIT " . PER_PAGE . " OFFSET " . $offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $userOptions   = $pdo->query('SELECT DISTINCT user_name FROM activity_log ORDER BY user_name')->fetchAll(PDO::FETCH_COLUMN);
    $actionOptions = $pdo->query('SELECT DISTINCT action FROM activity_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('Activity page failed: ' . $e->getMessage());
    $loadError = 'Could not load the activity log. Make sure the activity_log table exists in the database.';
    $pages = 1;
    $offset = 0;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function page_url(int $p, string $fUser, string $fAction): string
{
    $q = ['page' => $p];
    if ($fUser !== '')   { $q['user'] = $fUser; }
    if ($fAction !== '') { $q['action'] = $fAction; }
    return 'activity.php?' . http_build_query($q);
}

function action_class(string $a): string
{
    if (stripos($a, 'signed') === 0)     { return 'neutral'; }
    if (stripos($a, 'sale') !== false)   { return ''; }
    return 'low';
}

$filtered = ($fUser !== '' || $fAction !== '');
$from = $total ? $offset + 1 : 0;
$to   = $total ? min($offset + PER_PAGE, $total) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Crabkidz Admin — Activity</title>
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
/* Monthly comparison styles */
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

/* Stock take redesigned */
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

/* Sign out modal — centered */
.modal{position:fixed;inset:0;background:rgba(30,15,3,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:20px}
.modal .paper{position:static;max-width:420px;width:100%;border-top:8px solid var(--orange);padding:28px;margin:0;animation:pop .18s ease-out}
@keyframes pop{from{transform:scale(.95);opacity:0}to{transform:scale(1);opacity:1}}

@media(max-width:980px){.kpis{grid-template-columns:repeat(2,1fr)}.grid,.rc{grid-template-columns:1fr}.paper{position:static}.month-compare{flex-direction:column}}
@media(max-width:760px){.app{grid-template-columns:1fr}.side{position:sticky;height:auto;flex-direction:row;align-items:center;padding:10px 14px;gap:10px;z-index:5;overflow-x:auto}.brand span,nav a span,.who{display:none}.signout{margin-top:0;padding:9px 10px}.signout span{display:none}nav{flex-direction:row}nav a{padding:10px}}
@media(max-width:420px){.kpis{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){.toast{transition:none}.modal .paper{animation:none}}

/* Activity page */
.pill.neutral{background:var(--bg);color:var(--muted);border:1px solid var(--line)}
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
.filters select{border:1px solid var(--line);background:var(--surface);border-radius:10px;padding:9px 12px;min-width:0}
.pager{display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-top:14px}
.pager .pages{display:flex;gap:6px;flex-wrap:wrap}
.pager a,.pager span.pg{display:inline-block;min-width:38px;text-align:center;padding:8px 12px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--ink);text-decoration:none;font-weight:600;font-size:14px}
.pager a:hover{background:var(--tint)}
.pager span.cur{background:var(--strong);border-color:var(--strong);color:#fff}
.pager span.off{opacity:.45}
.act-details{max-width:420px;white-space:pre-line;word-break:break-word}
td.when{white-space:nowrap}
</style>
</head>
<body>
<div class="app">
<aside class="side">
  <div class="brand"><svg viewBox="0 0 40 40" fill="none" aria-hidden="true"><circle cx="20" cy="24" r="11" fill="#1E0F03"/><path d="M9 17C5 15 4 9 8 6c1 4 4 5 6 6M31 17c4-2 5-8 1-11-1 4-4 5-6 6" stroke="#1E0F03" stroke-width="3" stroke-linecap="round"/><circle cx="16" cy="22" r="2" fill="#F26B0F"/><circle cx="24" cy="22" r="2" fill="#F26B0F"/></svg><span>crabkidz</span></div>
  <nav id="nav" aria-label="Main"><a href="index.php#dashboard"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg><span>Sales monitor</span></a><a href="index.php#sales"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6h15l-2 9H8L6 3H3M9 20h.01M18 20h.01"/></svg><span>Sales</span></a><a href="index.php#receipts"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2zM9 8h6M9 12h6"/></svg><span>Receipts</span></a><a href="index.php#upload"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M7 9l5-5 5 5M4 20h16"/></svg><span>Upload stock</span></a><a href="index.php#stocktake"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4h6v3H9zM6 5H5v16h14V5h-1M9 14l2 2 4-4"/></svg><span>Stock take</span></a><a href="activity.php" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg><span>Activity</span></a></nav>
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

<section class="view on" id="v-activity">
  <div class="top"><div><h1>Activity</h1><p>Everything done in the system: who did what, and when. Latest first.</p></div></div>

  <div class="card">
    <?php if ($loadError): ?>
      <p class="neg" role="alert"><?= h($loadError) ?></p>
    <?php else: ?>
      <form class="filters" method="get" action="activity.php">
        <select name="user" aria-label="Filter by user" onchange="this.form.submit()">
          <option value="">All users</option>
          <?php foreach ($userOptions as $u): ?>
            <option value="<?= h((string)$u) ?>"<?= $u === $fUser ? ' selected' : '' ?>><?= h((string)$u) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="action" aria-label="Filter by action" onchange="this.form.submit()">
          <option value="">All actions</option>
          <?php foreach ($actionOptions as $a): ?>
            <option value="<?= h((string)$a) ?>"<?= $a === $fAction ? ' selected' : '' ?>><?= h((string)$a) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($filtered): ?><a class="btn" href="activity.php" style="text-decoration:none">Clear filters</a><?php endif; ?>
        <noscript><button class="btn" type="submit">Apply</button></noscript>
      </form>

      <h2><?= $page === 1 ? 'Latest ' . min(PER_PAGE, $total) . ' activities' : 'Activities ' . $from . '–' . $to ?></h2>
      <p class="sub"><?= $total ? ('Showing ' . $from . '–' . $to . ' of ' . $total . ($filtered ? ' matching' : ' total') . '.') : 'Nothing to show yet.' ?></p>

      <div class="tw">
        <table>
          <thead><tr><th>When</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP address</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6"><?= $filtered ? 'No activity matches these filters.' : 'No activity recorded yet.' ?></td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="when"><?= h(date('d M Y, H:i', strtotime((string)$r['created_at']))) ?></td>
              <td><b><?= h((string)$r['user_name']) ?></b></td>
              <td><?= h((string)$r['user_role']) ?></td>
              <td><span class="pill <?= action_class((string)$r['action']) ?>"><?= h((string)$r['action']) ?></span></td>
              <td class="act-details"><?= h((string)($r['details'] ?? '')) ?></td>
              <td><?= h((string)($r['ip_address'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total > PER_PAGE): ?>
        <nav class="pager" aria-label="Activity pages">
          <span class="sub" style="margin:0">Page <?= $page ?> of <?= $pages ?></span>
          <div class="pages">
            <?php if ($page > 1): ?><a href="<?= h(page_url($page - 1, $fUser, $fAction)) ?>">&larr; Newer</a><?php else: ?><span class="pg off">&larr; Newer</span><?php endif; ?>
            <?php
              $start = max(1, $page - 2);
              $end   = min($pages, $page + 2);
              if ($start > 1): ?><a href="<?= h(page_url(1, $fUser, $fAction)) ?>">1</a><?php if ($start > 2): ?><span class="pg off">…</span><?php endif; endif;
              for ($i = $start; $i <= $end; $i++):
                if ($i === $page): ?><span class="pg cur" aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= h(page_url($i, $fUser, $fAction)) ?>"><?= $i ?></a><?php endif;
              endfor;
              if ($end < $pages): if ($end < $pages - 1): ?><span class="pg off">…</span><?php endif; ?><a href="<?= h(page_url($pages, $fUser, $fAction)) ?>"><?= $pages ?></a><?php endif; ?>
            <?php if ($page < $pages): ?><a href="<?= h(page_url($page + 1, $fUser, $fAction)) ?>">Older &rarr;</a><?php else: ?><span class="pg off">Older &rarr;</span><?php endif; ?>
          </div>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

</main>
</div>

<script>
var $=function(i){return document.getElementById(i)};
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


</script>
</body>
</html>
