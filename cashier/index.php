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
$loginMsg = $_SESSION['login_ok'] ?? '';
unset($_SESSION['login_ok']);

// Record sign-in once per session
if (empty($_SESSION['activity_login_logged'])) {
    try {
        require __DIR__ . '/../db.php';
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
html{scroll-padding-top:env(safe-area-inset-top,0px)}
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
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn.sm{padding:5px 10px;font-size:14px}
.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px;align-items:stretch}
.kpi{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:16px 18px;min-width:0;min-height:132px;display:flex;flex-direction:column;justify-content:center;overflow-wrap:anywhere}
.kpi.hero{background:var(--orange);border-color:var(--orange)}
.kpi small{color:var(--muted);font-weight:500}.kpi.hero small,.kpi.hero em{color:#4A2208}
.kpi b{display:block;font:800 clamp(24px,5.5vw,32px)/1.15 var(--display);margin:6px 0 2px}
.kpi em{font-style:normal;font-size:14px;color:var(--muted)}
.grid{display:grid;grid-template-columns:3fr 2fr;gap:18px;margin-bottom:18px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:20px;min-width:0;margin-bottom:18px}
.grid .card{margin-bottom:0}
.card h2{font-size:19px;font-weight:700}
.sub{color:var(--muted);font-size:14px;margin:2px 0 14px}
.chart{display:flex;align-items:flex-end;gap:8px;height:200px;border-bottom:1px solid var(--line)}
.col{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%;gap:6px}
.bar{width:100%;max-width:48px;background:var(--tint);border-radius:8px 8px 0 0}
.col.today .bar{background:var(--orange)}
.col span,.axis div{font-size:12px;color:var(--muted);font-weight:600}
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
.f{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end}
.f label,.fl{display:flex;flex-direction:column;gap:4px;font-size:14px;font-weight:600}
.stock-meter{height:8px;min-width:90px;background:var(--line);border-radius:999px;overflow:hidden}
.stock-meter span{display:block;height:100%;background:var(--good);border-radius:inherit}
.stock-meter span.low{background:var(--orange)}.stock-meter span.critical{background:var(--bad)}
.sale-dialog{width:min(760px,100%);margin:auto}
.sale-dialog .paper{max-width:none;width:100%;max-height:calc(100vh - 100px);overflow-y:auto;margin:0;padding:24px}
.sale-dialog .mbar{max-width:none;width:100%;margin:0 0 8px}
.sale-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}
.sale-head p{margin:4px 0 0;color:var(--muted)}
.sale-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.sale-fields label{display:flex;flex-direction:column;gap:5px;font-weight:600;font-size:14px}
.sale-fields input,.sale-fields select{width:100%}
.sale-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:18px}
.sale-actions .tot{margin:0}
.sale-cart{max-height:220px;overflow:auto;margin-top:14px}
.sale-pay{display:grid;grid-template-columns:1fr;gap:12px;margin-top:18px}
.sale-pay label{display:flex;flex-direction:column;gap:5px;font-weight:600;font-size:14px}
.sale-pay input,.sale-pay select{width:100%}
@media(max-width:520px){.sale-fields,.sale-pay{grid-template-columns:1fr}.sale-dialog .paper{padding:18px}}
.line{display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid var(--line)}
.line .nm{flex:1;min-width:0;line-height:1.3}.line small{color:var(--muted)}
.qty{display:flex;align-items:center;gap:6px}
.qty button{width:28px;height:28px;border-radius:8px;border:1px solid var(--line);background:var(--surface);font-weight:700;line-height:1}
.tot{display:flex;justify-content:space-between;font:800 24px var(--display);margin:14px 0}
.link{background:none;border:0;color:var(--strong);font-weight:600;padding:0;text-decoration:underline}
.neg{color:var(--bad);font-weight:600}
.modal{position:fixed;inset:0;background:rgba(30,15,3,.6);display:none;overflow:auto;padding:20px 12px;z-index:30}
.modal.on{display:flex;flex-direction:column;align-items:center;justify-content:center}
.mbar{max-width:560px;margin:0 auto 12px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
.paper{background:#fff;max-width:560px;margin:0 auto;border-top:8px solid var(--orange);border-radius:6px;padding:28px;font-size:14px;color:#241A13}
.paper h3{font-size:26px}
.paper .hd{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-bottom:14px}
.paper .meta{text-align:right;color:var(--muted)}
.paper .meta b{display:block;color:#241A13;font:700 18px var(--display)}
.paper table{min-width:0;margin:10px 0}
.paper .row{display:flex;justify-content:space-between;gap:10px;padding:3px 0}
.paper .row.t{font:800 20px var(--display);border-top:2px solid #241A13;margin-top:6px;padding-top:8px}
.paper hr{border:0;border-top:1px dashed #999;margin:12px 0}
.paper .st{display:inline-block;border:2px solid var(--strong);color:var(--strong);font-weight:700;padding:2px 10px;border-radius:6px;transform:rotate(-4deg)}
.paper .st.paid{border-color:var(--good);color:var(--good)}
.toast{position:fixed;left:50%;bottom:calc(20px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);background:#1E0F03;color:#FFE8D4;padding:12px 18px;border-radius:12px;font-weight:600;opacity:0;pointer-events:none;transition:opacity .2s;z-index:60;max-width:90vw}
.toast.on{opacity:1}
@media(max-width:980px){.grid{grid-template-columns:minmax(0,1fr)}.kpi{padding:14px 14px}}
@media(max-width:760px){.app{grid-template-columns:minmax(0,1fr)}.top{align-items:flex-start}.top .btn{width:auto}.chart{gap:5px}.col span{font-size:10px}.axis{gap:5px}.axis div{font-size:11px}.side{position:sticky;height:auto;flex-direction:row;align-items:center;padding:10px 14px;gap:10px;z-index:5;overflow-x:auto}.brand span,nav a span,.who{display:none}.signout{margin-top:0;padding:9px 10px}.signout span{display:none}nav{flex-direction:row}nav a{padding:10px}}
@media(max-width:640px){.cards{grid-template-columns:minmax(0,1fr)}.kpi{min-height:0}main{padding-top:20px}}
@media(prefers-reduced-motion:reduce){.toast{transition:none}}
/* ===== Mobile refinements (phones only; desktop/tablet layout untouched) ===== */
@media(max-width:760px){
  html{-webkit-text-size-adjust:100%}
  /* Top bar: brand + avatar + sign out. Navigation moves to a thumb-reachable bottom tab bar. */
  .side{overflow:visible;padding:8px 14px;gap:10px;box-shadow:0 1px 0 rgba(30,15,3,.15)}
  .brand{font-size:21px}.brand svg{width:30px;height:30px}
  .brand span{display:inline}
  .who{display:flex;margin-left:auto}.who>div:last-child{display:none}
  .avatar{width:34px;height:34px;font-size:14px}
  .signout{margin-top:0;padding:0;width:40px;height:40px;justify-content:center}
  .signout svg{width:18px;height:18px}
  nav{position:fixed;left:0;right:0;bottom:0;z-index:5;flex-direction:row;gap:4px;padding:6px 8px calc(6px + env(safe-area-inset-bottom,0px));background:var(--orange);box-shadow:0 -1px 0 rgba(30,15,3,.18),0 -6px 18px rgba(30,15,3,.12)}
  nav a{flex:1;min-width:0;flex-direction:column;justify-content:center;gap:2px;min-height:52px;padding:6px 4px;font-size:11.5px;line-height:1.15;text-align:center}
  nav a span{display:inline}
  nav svg{width:22px;height:22px}
  main{padding:18px 14px calc(92px + env(safe-area-inset-bottom,0px))}
  .toast{bottom:calc(84px + env(safe-area-inset-bottom,0px))}

  /* Page headers + touch targets */
  .top{gap:12px;margin-bottom:16px}
  .top>div:first-child{flex:1 1 100%}
  .top h1{font-size:26px}
  .top p{font-size:14px}
  .top>.btn.primary{width:100%}
  .top>div[style]{display:flex;width:100%}
  .top>div[style] .btn{flex:1}
  .btn{min-height:44px}
  input,select{font-size:16px;min-height:44px}

  /* KPI cards: hero full width, other two side by side */
  .cards{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:14px}
  .kpi{min-height:0;padding:14px}
  .kpi.hero{grid-column:1/-1}
  .kpi b{font-size:clamp(22px,7vw,30px)}
  .kpi em{font-size:13px}

  /* Cards */
  .card{padding:16px;margin-bottom:14px;border-radius:12px}
  .grid{gap:14px;margin-bottom:14px}
  .card h2{font-size:18px}
  .col span{max-width:100%;overflow:hidden;white-space:nowrap}
  .tools input[type=search]{flex:1 1 100%;width:100%}

  /* Tables -> stacked cards (no sideways scrolling) */
  .tw table{min-width:0}
  #latest,#dtb,#stockRows{display:block}
  #v-sale thead,#v-documents thead{display:none}

  #latest tr,#dtb tr{display:grid;grid-template-columns:minmax(0,1fr) auto;grid-template-areas:"no total" "date pay" "cust open";gap:4px 12px;align-items:center;padding:12px 0;border-bottom:1px solid var(--line)}
  #latest tr:last-child,#dtb tr:last-child{border-bottom:0}
  #latest td,#dtb td{padding:0;border:0;min-width:0}
  #latest td:nth-child(1),#dtb td:nth-child(1){grid-area:no}
  #latest td:nth-child(2),#dtb td:nth-child(2){grid-area:date;color:var(--muted);font-size:13px}
  #latest td:nth-child(3),#dtb td:nth-child(3){grid-area:cust;overflow-wrap:anywhere}
  #latest td:nth-child(4),#dtb td:nth-child(4){grid-area:pay;justify-self:end;color:var(--muted);font-size:13px}
  #latest td:nth-child(5),#dtb td:nth-child(5){grid-area:total;text-align:right;font:700 17px var(--display)}
  #latest td:nth-child(6),#dtb td:nth-child(6){grid-area:open;justify-self:end}
  #dtb td[colspan]{grid-area:auto;grid-column:1/-1;color:var(--muted)}
  #latest .link,#dtb .link{display:inline-flex;align-items:center;min-height:40px;padding:0 16px;border:1px solid var(--line);border-radius:10px;background:var(--surface);text-decoration:none}

  #stockRows tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));grid-template-areas:"prod prod" "item size" "gen color" "price stock" "level level";gap:8px 12px;padding:14px 0;border-bottom:1px solid var(--line)}
  #stockRows tr:last-child{border-bottom:0}
  #stockRows td{padding:0;border:0;min-width:0;text-align:left}
  #stockRows td::before{display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:1px}
  #stockRows td:nth-child(1){grid-area:gen}#stockRows td:nth-child(1)::before{content:"Gender"}
  #stockRows td:nth-child(2){grid-area:item}#stockRows td:nth-child(2)::before{content:"Item no."}
  #stockRows td:nth-child(3){grid-area:prod;font-weight:600;font-size:17px}
  #stockRows td:nth-child(4){grid-area:color}#stockRows td:nth-child(4)::before{content:"Color"}
  #stockRows td:nth-child(5){grid-area:size}#stockRows td:nth-child(5)::before{content:"Size"}
  #stockRows td:nth-child(6){grid-area:price}#stockRows td:nth-child(6)::before{content:"Price"}
  #stockRows td:nth-child(7){grid-area:stock}#stockRows td:nth-child(7)::before{content:"Current stock"}
  #stockRows td:nth-child(8){grid-area:level;display:flex;align-items:center;gap:10px}
  #stockRows .stock-meter{flex:1;min-width:0}

  /* Modals: safe scrolling (no clipped top), full-width, comfy padding */
  .modal{padding:12px}
  .modal.on{justify-content:flex-start}
  .modal.on>:first-child{margin-top:auto}
  .modal.on>:last-child{margin-bottom:auto}
  .modal>.paper,.modal>.mbar{width:100%}
  .mbar .btn{flex:1}
  .paper{padding:20px 16px}
  .paper h3{font-size:22px}
  .paper th,.paper td{padding:6px 4px}

  /* New sale: bottom sheet with sticky total / Complete sale bar */
  .sale-modal{padding:0;overflow:hidden}
  .sale-modal.on{justify-content:flex-end}
  .sale-modal.on>.sale-dialog{margin:0;width:100%;display:flex;flex-direction:column;max-height:100%}
  .sale-dialog .mbar{flex:none;margin:0;padding:10px 12px;justify-content:flex-end}
  .sale-dialog .mbar .btn{flex:none}
  .sale-dialog .paper{flex:1 1 auto;min-height:0;max-height:none;border-radius:16px 16px 0 0;padding:16px 16px 0;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}
  .sale-head{margin-bottom:14px}
  .sale-fields{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
  .sale-fields label:first-child,.sale-fields label:last-child{grid-column:1/-1}
  .sale-cart{max-height:none;overflow:visible}
  .qty{gap:8px}
  .qty button{width:38px;height:38px;font-size:18px}
  .qty b{min-width:18px;text-align:center}
  .sale-pay{margin-top:14px}
  .sale-actions{position:sticky;bottom:0;flex-wrap:nowrap;background:#fff;margin:14px -16px 0;padding:12px 16px calc(12px + env(safe-area-inset-bottom,0px));border-top:1px solid var(--line)}
  .sale-actions .tot{flex:none;font-size:20px;gap:10px}
  .sale-actions .btn{flex:1;min-height:48px}
}
@media print{
  body > * { visibility: hidden !important; }
  body > #modal, body > #modal * { visibility: visible !important; }
  body > #modal { display: block !important; position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important; height: auto !important; background: none !important; padding: 0 !important; margin: 0 !important; overflow: visible !important; }
  body > #modal > .mbar { display: none !important; }
  body > #modal > .paper { max-width: none !important; width: 100% !important; margin: 0 !important; padding: 0 !important; border: 0 !important; box-shadow: none !important; border-top: 8px solid var(--orange) !important; padding-top: 20px !important; color: #000 !important; background: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>
<div class="app">
<aside class="side">
  <div class="brand"><svg viewBox="0 0 40 40" fill="none" aria-hidden="true"><circle cx="20" cy="24" r="11" fill="#1E0F03"/><path d="M9 17C5 15 4 9 8 6c1 4 4 5 6 6M31 17c4-2 5-8 1-11-1 4-4 5-6 6" stroke="#1E0F03" stroke-width="3" stroke-linecap="round"/><circle cx="16" cy="22" r="2" fill="#F26B0F"/><circle cx="24" cy="22" r="2" fill="#F26B0F"/></svg><span>crabkidz</span></div>
  <nav id="nav" aria-label="Main"></nav>
  <div class="who">
    <div class="avatar"><?= htmlspecialchars($initials ?: 'CK') ?></div>
    <div><b><?= htmlspecialchars($userName) ?></b><br>Cashier</div>
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
  <div class="top"><div><h1>Hi <?= htmlspecialchars($firstName) ?></h1><p id="today"></p></div><div style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn primary" type="button" data-sale-open>New sale</button></div></div>
  <div class="cards" id="cards"></div>
  <div class="grid">
    <div class="card"><h2>Total sales per day</h2><p class="sub" id="csub"></p><div class="chart" id="chart" role="img" aria-label="Bar chart of total sales for the last 7 days"></div><div class="axis" id="axis"></div></div>
    <div class="card"><h2>Running low</h2><p class="sub" id="lowSub">5 pairs or fewer left</p><div class="tw"><table style="min-width:0"><tbody id="low"></tbody></table></div></div>
  </div>
  <div class="card"><h2>Latest sales</h2><p class="sub">Open a sale receipt to print it or save it as a PDF.</p><div class="tw"><table><tbody id="latest"></tbody></table></div></div>
</section>

<section class="view" id="v-sale">
  <div class="top"><div><h1>Stock in store</h1><p>View remaining stock by item. Counts update as sales are completed.</p></div><button class="btn primary" type="button" data-sale-open>New sale</button></div>
  <div class="card"><div class="tw"><table><thead><tr><th>Gender</th><th>Item no.</th><th>Product</th><th>Color</th><th>Size</th><th class="r">Price</th><th>Current stock</th><th>Level</th></tr></thead><tbody id="stockRows"></tbody></table></div></div>
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
      <div class="sale-head"><div><h3 id="saleTitle">New sale</h3><p>Enter the item details and complete the sale.</p></div></div>
      <div class="sale-fields">
        <label>Customer<select id="cust"><option>Walk-in customer</option><option>Non-walk-in customer</option></select></label>
        <label>Gender<select id="gender"><option>Boy</option><option>Girl</option><option>Unisex</option></select></label>
        <label>Color<select id="color"><option>Black</option><option>Brown</option><option>White</option></select></label>
        <label>Size<select id="size"></select></label>
        <label>Item number<input id="itemNo" type="text" inputmode="numeric" autocomplete="off" placeholder="Enter item number to add" required></label>
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
<div class="toast" id="toast" role="status"></div>

<script>
var $=function(i){return document.getElementById(i)};
function $m(n){return '$'+n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function toast(m){var t=$('toast');t.textContent=m;t.classList.add('on');clearTimeout(toast.h);toast.h=setTimeout(function(){t.classList.remove('on')},2800)}
function fmtD(d){return new Date(d).toLocaleDateString(undefined,{day:'numeric',month:'short',year:'numeric'})}
function fmtT(d){return new Date(d).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}
function pill(q){return q===0?'<span class="pill out">Out</span>':q<=5?'<span class="pill low">'+q+' left</span>':'<span class="pill">'+q+'</span>'}

var stock=[], sales=[], hist=[0,0,0,0,0,0];
var CASHIER_NAME = <?= json_encode($userName) ?>;

// --- Load Real Data ---
function loadData() {
    fetch('api_get_data.php')
        .then(r => r.json())
        .then(data => {
            stock = data.stock;
            sales = data.sales;
            hist  = data.hist;
            route(); // draw the current view
            showStockNotifications(data.notifications || []);
        })
        .catch(err => console.error("Error loading data:", err));
}

// --- New stock notifications (uploaded by the admin) ---
var notifIds = [];
function showStockNotifications(list) {
    if (!list.length || $('notifModal').classList.contains('on')) return;
    notifIds = list.map(function (n) { return parseInt(n.id, 10); });
    var pairs = list.reduce(function (t, n) { return t + (parseInt(n.pairs, 10) || 0); }, 0);
    $('notifSub').textContent = pairs + ' new pair' + (pairs === 1 ? '' : 's') + ' added to the store stock.';
    $('notifList').innerHTML = list.map(function (n) {
        return '<div style="padding:12px 0;border-bottom:1px solid var(--line)">'
            + '<div>New stock uploaded by <b>' + esc(n.uploaded_by_name) + '</b></div>'
            + '<small style="color:var(--muted)">' + fmtD(n.created_at) + ' ' + fmtT(n.created_at) + '</small>'
            + '<div style="margin-top:6px;white-space:pre-line">' + esc(n.summary || '') + '</div>'
            + '</div>';
    }).join('');
    $('notifModal').classList.add('on');
    $('notifOk').focus();
}
function dismissNotifications() {
    $('notifModal').classList.remove('on');
    var ids = notifIds; notifIds = [];
    if (!ids.length) return;
    fetch('api_mark_notifications_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: ids })
    }).catch(function (err) { console.error('Could not mark notifications read:', err); });
}
$('notifOk').addEventListener('click', dismissNotifications);
document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && $('notifModal').classList.contains('on')) dismissNotifications(); });

var views=[['dashboard','Dashboard','<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>'],['sale','New sale','<path d="M6 6h15l-2 9H8L6 3H3M9 20h.01M18 20h.01"/>'],['documents','Sales receipts','<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-3 2zM9 8h6M9 12h6"/>']];
$('nav').innerHTML=views.map(function(v){return '<a href="#'+v[0]+'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+v[2]+'</svg><span>'+v[1]+'</span></a>'}).join('');

function route(){var h=(location.hash||'#dashboard').slice(1);if(!views.some(function(v){return v[0]===h}))h='dashboard';
  views.forEach(function(v){$('v-'+v[0]).classList.toggle('on',v[0]===h)});
  document.querySelectorAll('#nav a').forEach(function(a){if(a.getAttribute('href')==='#'+h)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current')});
  ({dashboard:drawDash,sale:drawStockRows,documents:drawSales})[h]();window.scrollTo(0,0)}
window.addEventListener('hashchange',route);
document.addEventListener('click',function(e){var sale=e.target.closest('[data-sale-open]'),g=e.target.closest('[data-go]'),o=e.target.closest('[data-open]');
  if(sale){e.preventDefault();openSale()}
  if(g)location.hash=g.dataset.go;
  if(o)openDoc(o.dataset.open)});

function saleRow(s){return '<tr><td><b>'+s.no+'</b></td><td>'+fmtD(s.date)+' '+fmtT(s.date)+'</td><td>'+esc(s.customer)+'</td><td>'+esc(s.pay)+'</td><td class="r">'+$m(s.total_amount)+'</td><td class="r"><button class="link" data-open="'+s.no+'">Open</button></td></tr>'}

function drawStockRows(){
  $('stockRows').innerHTML=stock.map(function(s){var level=s.initial_qty?Math.round(s.stock_qty/s.initial_qty*100):0,cls=level<25?'critical':s.stock_qty<=5?'low':'';return '<tr><td>Varies</td><td><b>'+esc(s.item_no)+'</b></td><td>'+esc(s.name)+'</td><td>Varies</td><td>'+esc(s.size)+'</td><td class="r">'+$m(s.price)+'</td><td><b>'+s.stock_qty+'</b> left</td><td><div class="stock-meter" aria-label="'+level+' percent of added stock remaining"><span class="'+cls+'" style="width:'+level+'%"></span></div><small>'+level+'%</small></td></tr>'}).join('');
}

function drawDash(){
  var tot=sales.reduce(function(t,s){return t+parseFloat(s.total_amount)},0),pairs=sales.reduce(function(t,s){return t+s.items.reduce(function(a,i){return a+i.qty},0)},0);
  var by={};
  sales.forEach(function(s){s.items.forEach(function(i){by[i.name]=(by[i.name]||0)+i.qty})});var best=Object.keys(by).sort(function(a,b){return by[b]-by[a]})[0];
  $('today').textContent=fmtD(new Date())+'. Here’s your shift so far.';
  $('cards').innerHTML='<div class="kpi hero"><small>Sales today</small><b>'+$m(tot)+'</b><em>'+sales.length+' receipts, '+$m(sales.length?tot/sales.length:0)+' average</em></div>'
   +'<div class="kpi"><small>Pairs sold today</small><b>'+pairs+'</b><em>Best seller: '+esc(best||'none yet')+'</em></div>'
   +'<div class="kpi"><small>Cash in drawer today</small><b>'+$m(sales.filter(function(s){return s.pay==='Cash'}).reduce(function(t,s){return t+parseFloat(s.total_amount)},0))+'</b><em>All sales are paid in cash</em></div>';
  var v=hist.concat([tot]),mx=Math.max.apply(null,v) || 1;
  $('chart').innerHTML=v.map(function(x,i){return '<div class="col'+(i===6?' today':'')+'"><span>$'+Math.round(x)+'</span><div class="bar" style="height:'+Math.round(x/mx*150)+'px"></div></div>'}).join('');
  $('axis').innerHTML=v.map(function(x,i){var d=new Date(new Date().getTime()-(6-i)*864e5);return '<div>'+(i===6?'Today':d.toLocaleDateString(undefined,{weekday:'short'}))+'</div>'}).join('');
  var avg = hist.reduce(function(a,b){return a+b},0)/6;
  if (avg === 0) {
      $('csub').textContent = 'No sales data for the past 6 days yet.';
  } else {
      $('csub').textContent = 'Today is ' + (tot >= avg ? 'above' : 'below') + ' the 6-day average of ' + $m(avg);
  }
  var low=stock.filter(function(s){return s.stock_qty<=5}).sort(function(a,b){return a.stock_qty-b.stock_qty});
  if (stock.length === 0) {
    $('lowSub').textContent = 'Add products to start tracking stock';
    $('low').innerHTML = '<tr><td colspan="2" style="text-align:center;color:var(--muted);padding:24px 10px;">No products have been added to the store yet.</td></tr>';
  } else if (low.length === 0) {
    $('lowSub').textContent = 'No items are running low right now';
    $('low').innerHTML = '<tr><td colspan="2" style="text-align:center;color:var(--muted);padding:24px 10px;">All products are well stocked.</td></tr>';
  } else {
    $('lowSub').textContent = low.length + ' item' + (low.length === 1 ? '' : 's') + ' running low (5 pairs or fewer left)';
    $('low').innerHTML = low.map(function(s){return '<tr><td>'+esc(s.name)+'<br><small style="color:var(--muted)">Item '+s.item_no+'</small></td><td class="r">'+pill(s.stock_qty)+'</td></tr>'}).join('');
  }
  $('latest').innerHTML=sales.slice(0,5).map(saleRow).join('')}

var cart=[];
function drawSale(){
  if(!$('size').options.length)$('size').innerHTML=Array.from({length:12},function(_,i){return '<option value="'+(i+25)+'">'+(i+25)+'</option>'}).join('');
  drawCart()}
function openSale(){drawSale();$('serr').textContent='';$('saleModal').classList.add('on');$('cust').focus()}
function closeSale(){$('saleModal').classList.remove('on')}
function findStockByItemNo(itemNo){return stock.find(function(s){return String(s.item_no)===String(itemNo).trim()})}
function cartTotal(){return cart.reduce(function(t,c){return t+c.qty*c.price},0)}
function cartQtyFor(item){return cart.reduce(function(total,c){return total+(c.item_no===item.item_no?c.qty:0)},0)}
function drawCart(){
  $('cart').innerHTML=cart.length?cart.map(function(c,index){return '<div class="line"><div class="nm">Item '+esc(c.item_no)+' · '+esc(c.name)+'<br><small>Size '+esc(c.size)+' · '+esc(c.gender)+' · '+esc(c.color)+' · '+$m(c.price)+'</small></div><div class="qty"><button type="button" data-cart-dec="'+index+'" aria-label="Remove one">−</button><b>'+c.qty+'</b><button type="button" data-cart-inc="'+index+'" aria-label="Add one"'+(cartQtyFor(c)>=c.stock_qty?' disabled':'')+'>+</button></div></div>'}).join(''):'<p class="sub">No items added yet.</p>';
  $('ctot').textContent=$m(cartTotal());$('done').disabled=!cart.length;chg()}
function chg(){var v=parseFloat($('cash').value),t=cartTotal();
  $('chg').textContent=!isNaN(v)&&cart.length?(v>=t?'Change to give back: '+$m(v-t):'Short by '+$m(t-v)):''}
function addItemToSale(){
  var s=findStockByItemNo($('itemNo').value);$('serr').textContent='';
  if(!$('itemNo').value.trim())return;
  if(!s){$('serr').textContent='That item number is not in the stock list.';return}
  if(1>s.stock_qty-cartQtyFor(s)){ $('serr').textContent='There is not enough stock for this item.';return }
  cart.push({id:s.id,item_no:s.item_no,name:s.name,qty:1,gender:$('gender').value,color:$('color').value,size:$('size').value,price:s.price,stock_qty:s.stock_qty});
  $('itemNo').value='';$('serr').textContent='';drawCart();$('itemNo').focus()}
$('itemNo').addEventListener('change',addItemToSale);
$('itemNo').addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();addItemToSale()}});
document.addEventListener('click',function(e){
  var dec=e.target.closest('[data-cart-dec]'),inc=e.target.closest('[data-cart-inc]');
  if(dec){var index=+dec.dataset.cartDec;cart[index].qty--;if(!cart[index].qty)cart.splice(index,1);drawSale()}
  if(inc){var item=cart[+inc.dataset.cartInc];if(cartQtyFor(item)<item.stock_qty)item.qty++;drawSale()}});
$('cash').addEventListener('input',chg);

// --- Save to DB ---
$('done').addEventListener('click',function(){
  var t=cartTotal(),cashV=parseFloat($('cash').value);$('serr').textContent='';
  if(!isNaN(cashV)&&cashV<t){$('serr').textContent='Cash received is less than the total.';return}
  
  $('done').disabled=true;
  $('done').textContent='Saving...';

  var payload = {
    customer: $('cust').value,
    total: t,
    cash: isNaN(cashV) ? null : cashV,
    change: isNaN(cashV) ? null : (cashV - t),
    pay: 'Cash',
    items: cart.map(c => ({ id: c.id, qty: c.qty, price: c.price }))
  };

  fetch('api_save_sale.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(r => r.json())
  .then(res => {
    $('done').disabled=false;
    $('done').textContent='Complete sale';
    if(res.error) { $('serr').textContent = res.error; return; }
    
    toast('Sale complete. Receipt '+res.receipt_no+' is ready.');
    cart=[];$('cust').value='Walk-in customer';$('cash').value='';$('itemNo').value='';
    closeSale();
    loadData(); // Reload the page data
    setTimeout(() => openDoc(res.receipt_no), 500); // open the new receipt
  })
  .catch(err => {
    $('done').disabled=false;
    $('done').textContent='Complete sale';
    $('serr').textContent = 'Network error. Could not save sale.';
  });
});

function drawSales(){var q=$('dq').value.toLowerCase();
  $('dtb').innerHTML=sales.filter(function(s){return!q||(s.no+' '+s.customer).toLowerCase().indexOf(q)>-1}).map(saleRow).join('')||'<tr><td colspan="6">No sales match.</td></tr>'}
$('dq').addEventListener('input',drawSales);

var cur=null;
function openDoc(no){cur=sales.find(function(s){return s.no===no});if(!cur)return;
  var d=cur;
  var h='<div class="hd"><div><h3>crabkidz</h3><div style="color:var(--muted)">Shoes for little feet</div></div><div class="meta"><b>Receipt '+d.no+'</b>'+fmtD(d.date)+', '+fmtT(d.date)+'</div></div><div>Customer: '+esc(d.customer)+'<br>Cashier: '+esc(d.cashier || 'N/A')+'</div><table><thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Amount</th></tr></thead><tbody>'
    +d.items.map(function(i){return '<tr><td>'+(i.item_no?'Item '+i.item_no+' · ':'')+esc(i.name)+', size '+esc(i.size)+'</td><td class="r">'+i.qty+'</td><td class="r">'+$m(i.price)+'</td><td class="r">'+$m(i.qty*i.price)+'</td></tr>'}).join('')+'</tbody></table>'
    +'<div class="row t"><span>Total</span><span>'+$m(d.total_amount)+'</span></div><div class="row"><span>Paid by</span><span>'+d.pay+'</span></div>'+(d.cash_received!==null?'<div class="row"><span>Cash received</span><span>'+$m(d.cash_received)+'</span></div><div class="row"><span>Change</span><span>'+$m(d.change_given)+'</span></div>':'')+'<hr><div style="text-align:center;color:var(--muted)">Thank you for shopping at Crabkidz!</div><div style="text-align:center;color:#555;font-size:12px;margin-top:14px;padding:6px 12px;background-color:#f3f3f3;border-radius:6px;display:inline-block;width:100%;font-weight:600;letter-spacing:0.5px;">System developed by Smug Solutions</div>';
  
  $('doc').innerHTML=h;$('modal').classList.add('on');$('prt').focus()}
function closeDoc(){$('modal').classList.remove('on');route()}
$('cls').addEventListener('click',closeDoc);
$('saleClose').addEventListener('click',closeSale);
document.addEventListener('click',function(e){if(e.target===$('saleModal'))closeSale()});
document.addEventListener('keydown',function(e){if(e.key==='Escape'&&$('saleModal').classList.contains('on'))closeSale();else if(e.key==='Escape'&&$('modal').classList.contains('on'))closeDoc()});
$('prt').addEventListener('click',function(){var t=document.title;document.title=cur.no+' Crabkidz';
  try{window.print()}catch(e){toast('Printing is blocked here. Open this page in its own browser tab and try again.')}
  setTimeout(function(){document.title=t},500)});

// Sign out
document.getElementById('signoutBtn').addEventListener('click', function () {
  var m = document.getElementById('signoutModal');
  if (!m) {
    m = document.createElement('div');
    m.id = 'signoutModal';
    m.className = 'modal on';
    m.setAttribute('role', 'dialog');
    m.setAttribute('aria-modal', 'true');
    m.innerHTML = '<div class="paper" style="max-width:380px;border-top:8px solid var(--orange)"><h3 id="soTitle" style="margin-bottom:6px">Sign out</h3><p style="margin:0 0 18px;color:var(--muted)">Are you sure you want to exit?</p><div style="display:flex;gap:10px;justify-content:flex-end"><button class="btn" id="soNo" type="button">No</button><button class="btn primary" id="soYes" type="button">Yes, sign out</button></div></div>';
    document.body.appendChild(m);
    m.querySelector('#soNo').addEventListener('click', function () { m.remove(); });
    m.querySelector('#soYes').addEventListener('click', function () { window.location.href = '../logout.php'; });
    m.addEventListener('click', function (e) { if (e.target === m) m.remove(); });
    m.querySelector('#soNo').focus();
  }
});

// Init
loadData();
</script>
</body>
</html>