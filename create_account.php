<?php
// create_account.php
declare(strict_types=1);
session_start();
require __DIR__ . '/db.php';

$errors = [];
$posted = [
    'nm'  => '',
    'em'  => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted['nm'] = trim($_POST['nm'] ?? '');
    $posted['em'] = trim($_POST['em'] ?? '');
    $pw  = $_POST['pw']  ?? '';
    $pw2 = $_POST['pw2'] ?? '';

    if ($posted['nm'] === '')                                    $errors[] = 'Enter your full name.';
    if (!filter_var($posted['em'], FILTER_VALIDATE_EMAIL))       $errors[] = 'Enter a valid email address.';
    if (strlen($pw) < 8)                                         $errors[] = 'Your password needs at least 8 characters.';
    if ($pw !== $pw2)                                            $errors[] = 'The passwords do not match.';

    if (!$errors) {
        try {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $check->execute([$posted['em']]);
            if ($check->fetch()) {
                $errors[] = 'An account with that email already exists.';
            } else {
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                $ins  = $pdo->prepare(
                    'INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)'
                );
                $ins->execute([$posted['nm'], $posted['em'], $hash]);

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'text' => 'Account created for ' . $posted['nm'] . '. Please sign in.',
                ];
                header('Location: index.php');
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = 'Could not save your account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Register · Crabkidz</title>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@500;700;800&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--orange:#F26B0F;--strong:#C74A00;--bg:#F6F3F0;--ink:#241A13;--muted:#6F6259;--line:#E0D8D1;--bad:#B3261E;--good:#1F7A4D;
--display:'Bricolage Grotesque','Trebuchet MS',system-ui,sans-serif;--body:'Figtree',system-ui,-apple-system,'Segoe UI',sans-serif;
box-sizing:border-box;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}
*,*::before,*::after{box-sizing:inherit}
html{scroll-padding-top:env(safe-area-inset-top,0px)}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 var(--body);min-height:100vh}
h1,h2{font-family:var(--display);margin:0;letter-spacing:-.02em}
button,input,select{font:inherit;color:inherit}
:focus-visible{outline:3px solid var(--orange);outline-offset:2px}
.wrap{display:grid;grid-template-columns:1.05fr 1fr;min-height:100vh}
.hero{background:var(--orange);color:#1E0F03;padding:40px clamp(24px,5vw,64px);display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden}
.brand{display:flex;align-items:center;gap:10px;font:800 28px var(--display)}
.brand svg{width:44px;height:44px}
.hero h2{font-size:clamp(34px,4.6vw,58px);font-weight:800;line-height:1.05;max-width:11ch}
.hero p{max-width:34ch;font-weight:500;margin:14px 0 0}
.hero ol{margin:0;padding-left:20px;font-weight:600;line-height:1.9}
.panel{display:flex;align-items:center;justify-content:center;padding:32px 20px}
.box{width:100%;max-width:400px}
.box h1{font-size:34px;font-weight:800}
.lead{color:var(--muted);margin:6px 0 24px}
.fl{display:flex;flex-direction:column;gap:6px;font-weight:600;font-size:15px;margin-bottom:16px}
input[type=email],input[type=password],input[type=text],select{width:100%;border:1.5px solid var(--line);background:#fff;border-radius:12px;padding:12px 14px;font-weight:400}
input:focus,select:focus{border-color:var(--orange)}
input[aria-invalid=true]{border-color:var(--bad)}
.in{position:relative;display:block;width:100%}.in .password-field{display:block;width:100%;height:51px;padding-right:70px}
.eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:0;color:var(--strong);font-weight:600;padding:6px 10px;cursor:pointer;border-radius:8px}
.hint{font-size:13px;color:var(--muted);font-weight:400}
.match{font-size:14px;font-weight:600;min-height:1.4em;margin:-8px 0 12px}
.match.ok{color:var(--good)}.match.no{color:var(--bad)}
.err{color:var(--bad);font-size:14px;font-weight:600;min-height:1.4em;margin:0 0 10px}
.err ul{margin:0;padding-left:18px}
.go{width:100%;border:0;background:var(--strong);color:#fff;font-weight:700;font-size:17px;padding:14px;border-radius:12px;cursor:pointer}
.go:hover{filter:brightness(1.1)}
.back{text-align:center;margin:22px 0 0;font-size:15px}.back a{color:var(--strong);font-weight:600}
@media(max-width:820px){.wrap{grid-template-columns:1fr}.hero{padding:22px 20px;min-height:0;gap:14px}.hero h2{font-size:30px;max-width:none}.hero p,.hero ol{display:none}.panel{align-items:flex-start;padding-top:28px}}
</style>
</head>
<body>
<div class="wrap">
  <aside class="hero">
    <div class="brand"><svg viewBox="0 0 40 40" fill="none" aria-hidden="true"><circle cx="20" cy="24" r="11" fill="#1E0F03"/><path d="M9 17C5 15 4 9 8 6c1 4 4 5 6 6M31 17c4-2 5-8 1-11-1 4-4 5-6 6" stroke="#1E0F03" stroke-width="3" stroke-linecap="round"/><circle cx="16" cy="22" r="2" fill="#F26B0F"/><circle cx="24" cy="22" r="2" fill="#F26B0F"/></svg>crabkidz</div>
    <div><h2>Join the Crabkidz team.</h2><p>Request a POS account. The store owner approves every new account before it works.</p></div>
    <ol><li>Fill in the form</li><li>Send your request</li><li>Sign in once approved</li></ol>
  </aside>
  <main class="panel">
    <div class="box">
      <h1>Create your account</h1>
      <p class="lead">Fill in your details to request access.</p>
      <form id="fm" method="post" action="create_account.php" novalidate>
        <label class="fl" for="nm">Full name
          <input id="nm" name="nm" type="text" placeholder="Full Name" autocomplete="name"
                 value="<?= htmlspecialchars($posted['nm'], ENT_QUOTES) ?>">
        </label>
        <label class="fl" for="em">Email
          <input id="em" name="em" type="email" autocomplete="email" inputmode="email"
                 placeholder="you@crabkidz.com"
                 value="<?= htmlspecialchars($posted['em'], ENT_QUOTES) ?>">
        </label>
        <label class="fl" for="pw">Password
          <span class="in">
            <input id="pw" class="password-field" name="pw" type="password" placeholder="************" autocomplete="new-password">
          </span>
          <span class="hint">At least 8 characters.</span>
        </label>
        <label class="fl" for="pw2">Re-enter password
          <span class="in">
            <input id="pw2" class="password-field" name="pw2" type="password" placeholder="Re-enter password" autocomplete="new-password">
            <button type="button" class="eye" id="eye" aria-label="Show password">Show</button>
          </span>
        </label>
        <?php if ($errors): ?>
          <div class="err" role="alert">
            <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
          </div>
        <?php else: ?>
          <p class="err" role="alert" id="err"></p>
        <?php endif; ?>
        <button class="go" id="go" type="submit">Create account</button>
      </form>
      <p class="back">Already have an account? <a href="index.php" target="_top">Back to sign in</a></p>
    </div>
  </main>
</div>
<script>
var $=function(i){return document.getElementById(i)};
$('eye').addEventListener('click',function(){var h=$('pw2').type==='password';$('pw2').type=h?'text':'password';this.textContent=h?'Hide':'Show';this.setAttribute('aria-label',h?'Hide password':'Show password')});
function match(){var a=$('pw').value,b=$('pw2').value,m=$('mt');if(!b){m.textContent='';m.className='match';return}
  var ok=a===b;m.textContent=ok?'Passwords match':'Passwords do not match';m.className='match '+(ok?'ok':'no')}
$('pw').addEventListener('input',match);$('pw2').addEventListener('input',match);
</script>
</body>
</html>