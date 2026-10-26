<?php
/** Admin login — standalone themed page (dark/light, mobile). */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_brand.php';

// If not set up yet, send to setup.
if (!schema_ready()) { header('Location: setup.php'); exit; }
try {
    if ((int) db()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 0) {
        header('Location: setup.php'); exit;
    }
} catch (Exception $ex) { header('Location: setup.php'); exit; }

if (current_user()) { header('Location: index.php'); exit; }

$err = '';
$ip = client_ip();
if (isset($_GET['disabled'])) $err = 'Your account is turned off. Ask an admin if you need access.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    [$locked, $wait] = throttle_status($ip);
    if ($locked) {
        $err = 'Too many failed attempts. Please try again in ' . fmt_wait($wait) . '.';
    } else {
        $user = trim($_POST['username'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        $st = db()->prepare('SELECT * FROM admin_users WHERE username = ?');
        $st->execute([$user]);
        $row = $st->fetch();
        if ($row && password_verify($pass, $row['password_hash']) && (!array_key_exists('active', $row) || (int)$row['active'] === 1)) {
            record_attempt($ip, $user, true);
            login_user($row);
            try { db()->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$row['id']]); } catch (Exception $ex) {}
            log_activity('login', 'user', (int)$row['id']);
            header('Location: index.php');
            exit;
        }
        record_attempt($ip, $user, false);
        usleep(400000); // uniform small delay to blunt guessing
        [$nowLocked, $nowWait] = throttle_status($ip);
        $offButRight = $row && isset($row['active']) && (int)$row['active'] === 0 && password_verify($pass, $row['password_hash']);
        $err = $nowLocked
            ? 'Too many failed attempts. Please try again in ' . fmt_wait($nowWait) . '.'
            : ($offButRight ? 'Your account is turned off. Ask an admin if you need access.' : 'Incorrect username or password.');
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Log in · Ops Essentials</title>
<link rel="icon" href="<?= e(brand_favicon()) ?>">
<style>
  @font-face{font-family:'Inter';font-weight:400 700;font-display:swap;src:url(fonts/inter.woff2) format('woff2')}
  @font-face{font-family:'Inter Tight';font-weight:600 800;font-display:swap;src:url(fonts/inter-tight.woff2) format('woff2')}
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{
    --bg:#f3f4f1;--surface:#ffffff;--text:#16201b;--muted:#6f7c76;--border:#e3e6e0;
    --accent:#0f6e52;--accent-text:#ffffff;--field:#ffffff;--field-border:#d5dad3;--shadow:0 1px 2px rgba(16,26,22,.05),0 18px 48px -16px rgba(16,26,22,.2)
  }
  :root[data-theme="dark"]{
    --bg:#0e1311;--surface:#161d1a;--text:#e8eeea;--muted:#8a9993;--border:#26312c;
    --accent:#3cbf8f;--accent-text:#04130d;--field:#121916;--field-border:#33413a;--shadow:0 18px 48px -16px rgba(0,0,0,.6)
  }
  @media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
    --bg:#0e1311;--surface:#161d1a;--text:#e8eeea;--muted:#8a9993;--border:#26312c;
    --accent:#3cbf8f;--accent-text:#04130d;--field:#121916;--field-border:#33413a;--shadow:0 18px 48px -16px rgba(0,0,0,.6)
  }}
  body{font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.25rem;-webkit-font-smoothing:antialiased}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);width:100%;max-width:400px;padding:2.25rem 2rem}
  .mark{height:48px;min-width:48px;padding:0 11px;border-radius:12px;background:linear-gradient(140deg,#2fb383,#0f6e52);display:flex;width:max-content;align-items:center;justify-content:center;color:#fff;font:800 19px/1 'Inter Tight',Inter,sans-serif;letter-spacing:-.01em;margin:0 auto 1.1rem}
  .mk-ops{color:#000}
  h1{font:700 24px/1.2 'Inter Tight',Inter,sans-serif;letter-spacing:-.02em;text-align:center;margin-bottom:.3rem}
  .tag{text-align:center;color:var(--muted);font-size:14px;margin-bottom:1.6rem}
  label{display:block;font-size:12.5px;font-weight:600;color:var(--muted);margin:.9rem 0 .35rem}
  input{width:100%;padding:.65rem .8rem;border:1px solid var(--field-border);border-radius:9px;background:var(--field);color:var(--text);font:15px Inter,sans-serif;outline:none}
  input:focus{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent)}
  button{width:100%;margin-top:1.4rem;padding:.8rem;border:none;border-radius:9px;background:var(--accent);color:var(--accent-text);font:700 15px Inter,sans-serif;cursor:pointer}
  button:hover{filter:brightness(1.06)}
  .err{background:rgba(180,40,40,.1);color:#b42828;border:1px solid rgba(180,40,40,.25);padding:.6rem .8rem;border-radius:8px;font-size:14px;margin-bottom:1rem;text-align:center}
  :root[data-theme="dark"] .err{color:#f08a80}
  .foot{text-align:center;margin-top:1.4rem;font-size:12.5px;color:var(--muted)}
  .themebtn{position:fixed;top:1rem;right:1rem;background:var(--surface);border:1px solid var(--border);color:var(--text);width:40px;height:40px;border-radius:10px;cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center;margin:0;padding:0}
</style>
</head>
<body>
<button class="themebtn" id="themebtn" type="button" aria-label="Toggle theme">◐</button>
<div class="card">
  <span class="mark" aria-hidden="true"><span class="mk-ops">Ops</span>E</span>
  <h1>Ops Essentials</h1>
  <div class="tag">Log in to <?= e(client('name')) ?></div>
  <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label for="u">Username</label>
    <input id="u" type="text" name="username" autocomplete="username" required autofocus>
    <label for="p">Password</label>
    <input id="p" type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Log in</button>
  </form>
  <div class="foot">Ops Essentials by Modern Ops · authorized access only</div>
</div>
<script>
  (function(){
    var root=document.documentElement, btn=document.getElementById('themebtn');
    try{var s=localStorage.getItem('mohq-theme'); if(s) root.setAttribute('data-theme',s);}catch(e){}
    function cur(){return root.getAttribute('data-theme')|| (window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');}
    btn.addEventListener('click',function(){
      var next=cur()==='dark'?'light':'dark';
      root.setAttribute('data-theme',next);
      try{localStorage.setItem('mohq-theme',next);}catch(e){}
    });
  })();
</script>
</body>
</html>
