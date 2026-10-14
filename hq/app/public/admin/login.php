<?php
/** Admin login — standalone themed page (dark/light, mobile). */
require_once __DIR__ . '/_auth.php';

// If not set up yet, send to setup.
if (!schema_ready()) { header('Location: setup.php'); exit; }
try {
    if ((int) db()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 0) {
        header('Location: setup.php'); exit;
    }
} catch (Exception $ex) { header('Location: setup.php'); exit; }

if (current_user()) { header('Location: index.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $user = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $st = db()->prepare('SELECT * FROM admin_users WHERE username = ?');
    $st->execute([$user]);
    $row = $st->fetch();
    if ($row && password_verify($pass, $row['password_hash'])) {
        login_user($row);
        header('Location: index.php');
        exit;
    }
    // Uniform small delay to blunt guessing
    usleep(300000);
    $err = 'Incorrect username or password.';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Log in · Modern Ops HQ</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2032%2032'%3E%3Crect%20width='32'%20height='32'%20rx='7'%20fill='%231a6b4a'/%3E%3Ctext%20x='16'%20y='21.5'%20font-family='Georgia,serif'%20font-size='16'%20font-weight='700'%20text-anchor='middle'%3E%3Ctspan%20fill='%231a1a1a'%3EM%3C/tspan%3E%3Ctspan%20fill='%23ffffff'%3EO%3C/tspan%3E%3C/text%3E%3C/svg%3E">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{
    --bg:#eef0ec;--surface:#ffffff;--text:#1a1a1a;--muted:#5a5a5a;--border:#e2e2dd;
    --accent:#1a6b4a;--accent-text:#ffffff;--field:#ffffff;--field-border:#d7d7d1;--shadow:0 10px 40px rgba(0,0,0,.08)
  }
  :root[data-theme="dark"]{
    --bg:#11161a;--surface:#1b2328;--text:#eef1ee;--muted:#9fb0aa;--border:#2a353b;
    --accent:#35b082;--accent-text:#06130d;--field:#131a1f;--field-border:#30404a;--shadow:0 10px 40px rgba(0,0,0,.45)
  }
  @media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
    --bg:#11161a;--surface:#1b2328;--text:#eef1ee;--muted:#9fb0aa;--border:#2a353b;
    --accent:#35b082;--accent-text:#06130d;--field:#131a1f;--field-border:#30404a;--shadow:0 10px 40px rgba(0,0,0,.45)
  }}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.25rem}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);width:100%;max-width:400px;padding:2.25rem 2rem}
  .logo{width:52px;height:52px;border-radius:12px;background:var(--accent);display:flex;align-items:center;justify-content:center;font-family:Georgia,serif;font-weight:700;font-size:1.5rem;margin:0 auto 1.1rem}
  .logo .m{color:#1a1a1a}.logo .o{color:#fff}
  h1{font-family:Georgia,serif;font-size:1.4rem;text-align:center;margin-bottom:.3rem}
  .tag{text-align:center;color:var(--muted);font-size:.85rem;margin-bottom:1.6rem}
  label{display:block;font-size:.8rem;font-weight:600;color:var(--muted);margin:.9rem 0 .35rem}
  input{width:100%;padding:.7rem .8rem;border:1px solid var(--field-border);border-radius:9px;background:var(--field);color:var(--text);font-size:.95rem;outline:none}
  input:focus{border-color:var(--accent)}
  button{width:100%;margin-top:1.4rem;padding:.8rem;border:none;border-radius:9px;background:var(--accent);color:var(--accent-text);font-weight:700;font-size:.95rem;cursor:pointer}
  button:hover{opacity:.92}
  .err{background:rgba(176,36,36,.12);color:#c0392b;border:1px solid rgba(176,36,36,.3);padding:.6rem .8rem;border-radius:8px;font-size:.85rem;margin-bottom:1rem;text-align:center}
  :root[data-theme="dark"] .err{color:#ff9b9b}
  .foot{text-align:center;margin-top:1.4rem;font-size:.75rem;color:var(--muted)}
  .themebtn{position:fixed;top:1rem;right:1rem;background:var(--surface);border:1px solid var(--border);color:var(--text);width:40px;height:40px;border-radius:10px;cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center}
</style>
</head>
<body>
<button class="themebtn" id="themebtn" type="button" aria-label="Toggle theme">◐</button>
<div class="card">
  <div class="logo"><span class="m">M</span><span class="o">O</span></div>
  <h1>Modern Ops HQ</h1>
  <div class="tag">Private dashboard — authorized access only</div>
  <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label for="u">Username</label>
    <input id="u" type="text" name="username" autocomplete="username" required autofocus>
    <label for="p">Password</label>
    <input id="p" type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Log in</button>
  </form>
  <div class="foot">Modern Ops · Delaware Valley, PA</div>
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
