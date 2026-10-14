<?php
/** Shared page chrome for the admin dashboard (dark/light, mobile). */

function layout_header(string $title, string $active = ''): void {
    $site = defined('SITE_ORIGIN') ? SITE_ORIGIN : 'https://modern-ops.net';
    $nav = [
        'index.php'     => 'Dashboard',
        'requests.php'  => 'Requests',
        'customers.php' => 'Customers',
        'quotes.php'    => 'Quotes',
        'catalog.php'   => 'Catalog',
        'email.php'     => 'Email',
        rtrim($site, '/') . '/demos/' => 'Previews',
    ];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Modern Ops HQ</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2032%2032'%3E%3Crect%20width='32'%20height='32'%20rx='7'%20fill='%231a6b4a'/%3E%3Ctext%20x='16'%20y='21.5'%20font-family='Georgia,serif'%20font-size='16'%20font-weight='700'%20text-anchor='middle'%3E%3Ctspan%20fill='%231a1a1a'%3EM%3C/tspan%3E%3Ctspan%20fill='%23ffffff'%3EO%3C/tspan%3E%3C/text%3E%3C/svg%3E">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{
    --bg:#f4f3ef;--surface:#fff;--surface-2:#faf9f6;--text:#1a1a1a;--muted:#5a5a5a;--border:#e2e2dd;
    --accent:#1a6b4a;--accent-text:#fff;--field:#fff;--field-border:#d7d7d1;--topbar:#1a1a1a;--topbar-text:#fff;--topbar-dim:#cfcfcf
  }
  :root[data-theme="dark"]{
    --bg:#11161a;--surface:#1b2328;--surface-2:#161d22;--text:#eef1ee;--muted:#9fb0aa;--border:#2a353b;
    --accent:#35b082;--accent-text:#06130d;--field:#131a1f;--field-border:#30404a;--topbar:#0c1114;--topbar-text:#eef1ee;--topbar-dim:#8fa29b
  }
  @media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
    --bg:#11161a;--surface:#1b2328;--surface-2:#161d22;--text:#eef1ee;--muted:#9fb0aa;--border:#2a353b;
    --accent:#35b082;--accent-text:#06130d;--field:#131a1f;--field-border:#30404a;--topbar:#0c1114;--topbar-text:#eef1ee;--topbar-dim:#8fa29b
  }}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);font-size:15px;line-height:1.55}
  a{color:var(--accent);text-decoration:none}
  a:hover{text-decoration:underline}
  header.topbar{background:var(--topbar);color:var(--topbar-text);padding:0 5%;display:flex;align-items:center;justify-content:space-between;min-height:56px;position:sticky;top:0;z-index:50;flex-wrap:wrap;gap:.5rem}
  .brand{font-family:Georgia,serif;font-weight:700;font-size:1.05rem;color:var(--topbar-text);text-decoration:none}
  .brand b{color:#5fcfa0}
  nav.main{display:flex;gap:.25rem;align-items:center;flex-wrap:wrap}
  nav.main a{color:var(--topbar-dim);padding:.5rem .9rem;border-radius:6px;font-size:.9rem;font-weight:500}
  nav.main a:hover{background:rgba(255,255,255,.08);color:#fff;text-decoration:none}
  nav.main a.active{background:var(--accent);color:var(--accent-text)}
  .topbar-right{display:flex;align-items:center;gap:1rem;font-size:.85rem;color:var(--topbar-dim)}
  .themebtn{background:transparent;border:1px solid rgba(255,255,255,.2);color:var(--topbar-text);width:34px;height:34px;border-radius:8px;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center}
  .wrap{max-width:1000px;margin:0 auto;padding:1.75rem 5% 4rem}
  h1.page{font-family:Georgia,serif;font-size:1.5rem;font-weight:700;margin-bottom:.25rem}
  .sub{color:var(--muted);margin-bottom:1.5rem;font-size:.9rem}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:1.25rem 1.4rem;margin-bottom:1.25rem}
  .stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:1.5rem}
  .stat{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:1.1rem 1.25rem}
  .stat .n{font-family:Georgia,serif;font-size:1.8rem;font-weight:700;color:var(--accent);line-height:1}
  .stat .l{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-top:.4rem}
  table{width:100%;border-collapse:collapse;font-size:.9rem}
  th{text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);padding:.6rem .6rem;border-bottom:2px solid var(--border)}
  td{padding:.7rem .6rem;border-bottom:1px solid var(--border);vertical-align:top}
  tr:last-child td{border-bottom:none}
  .badge{display:inline-block;font-size:.72rem;font-weight:700;padding:.18rem .55rem;border-radius:20px;text-transform:uppercase;letter-spacing:.03em}
  .badge.new{background:#e8f4ee;color:#1a6b4a}
  .badge.contacted{background:#fff3d6;color:#8a6d1a}
  .badge.quoted{background:#e0ecff;color:#2451b0}
  .badge.won{background:#1a6b4a;color:#fff}
  .badge.closed{background:#e6e6e6;color:#777}
  .badge.draft{background:#e6e6e6;color:#777}
  .badge.sent{background:#e0ecff;color:#2451b0}
  .badge.accepted{background:#1a6b4a;color:#fff}
  .badge.declined{background:#fde2e2;color:#b02424}
  .btn{display:inline-block;background:var(--accent);color:var(--accent-text);padding:.55rem 1.1rem;border-radius:7px;font-weight:600;font-size:.88rem;border:none;cursor:pointer;font-family:inherit}
  .btn:hover{opacity:.9;text-decoration:none}
  .btn.secondary{background:var(--surface);color:var(--text);border:1px solid var(--border)}
  .btn.small{padding:.35rem .7rem;font-size:.8rem}
  .btn.danger{background:var(--surface);color:#b02424;border:1px solid #f0cccc}
  input,select,textarea{font-family:inherit;font-size:.92rem;width:100%;padding:.6rem .7rem;border:1px solid var(--field-border);border-radius:7px;background:var(--field);color:var(--text);outline:none}
  input:focus,select:focus,textarea:focus{border-color:var(--accent)}
  label{display:block;font-size:.82rem;font-weight:600;color:var(--muted);margin:.9rem 0 .3rem}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 1rem}
  .row-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
  .flash{padding:.7rem 1rem;border-radius:8px;margin-bottom:1.25rem;font-size:.9rem}
  .flash.ok{background:rgba(26,107,74,.12);color:var(--accent);border:1px solid rgba(26,107,74,.3)}
  .flash.err{background:rgba(176,36,36,.12);color:#c0392b;border:1px solid rgba(176,36,36,.3)}
  .muted{color:var(--muted)}
  .empty{text-align:center;color:var(--muted);padding:2.5rem 1rem}
  @media(max-width:640px){.grid2{grid-template-columns:1fr}nav.main a{padding:.45rem .55rem;font-size:.82rem}.topbar-right .uname{display:none}.wrap{padding-top:1.25rem}}
</style>
</head>
<body>
<header class="topbar">
  <div style="display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap">
    <a class="brand" href="index.php">Modern Ops <b>HQ</b></a>
    <nav class="main">
      <?php foreach ($nav as $href => $label): $ext = (strncmp($href, 'http', 4) === 0); ?>
        <a href="<?= e($href) ?>"<?= $active === $href ? ' class="active"' : '' ?><?= $ext ? ' target="_blank" rel="noopener"' : '' ?>><?= $label ?><?= $ext ? ' <span aria-hidden="true" style="opacity:.6;font-size:.8em">&#8599;</span>' : '' ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <div class="topbar-right">
    <button class="themebtn" id="themebtn" type="button" aria-label="Toggle theme">◐</button>
    <span class="uname"><?= e(current_username()) ?></span>
    <a href="logout.php" style="color:var(--topbar-dim)">Log out</a>
  </div>
</header>
<div class="wrap">
<?php
}

function layout_footer(): void {
    ?>
</div>
<script>
  (function(){
    var root=document.documentElement, btn=document.getElementById('themebtn');
    try{var s=localStorage.getItem('mohq-theme'); if(s) root.setAttribute('data-theme',s);}catch(e){}
    function cur(){return root.getAttribute('data-theme')|| (window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');}
    if(btn){btn.addEventListener('click',function(){
      var next=cur()==='dark'?'light':'dark';
      root.setAttribute('data-theme',next);
      try{localStorage.setItem('mohq-theme',next);}catch(e){}
    });}
  })();
</script>
</body>
</html>
<?php
}

function flash(string $type, string $msg): void {
    echo '<div class="flash ' . e($type) . '">' . e($msg) . '</div>';
}
