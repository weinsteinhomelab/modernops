<?php
/**
 * Client demo gate — modern-ops.net/demos/<slug>/
 *
 * Demo sites are NOT in this public repo. They live next to public_html, in ../../demos-private/<slug>/:
 *   site/            the demo files (index.html, img/, ...)
 *   password.hash    password_hash() of that company's own password
 *   name.txt         company name shown on the sign-in page
 * plus _secret (signs the sign-in cookie), _attempts/ (wrong passwords per IP) and _views.log (who opened what).
 * Add a demo with demos-private/demo-admin.php over SSH.
 *
 * Every page served here: no indexing, no framing, no caching, a "Made by Modern Ops · not for production use"
 * watermark with a viewer code (so a leaked screenshot can be traced), and copy/save deterrents.
 */
declare(strict_types=1);

const MAX_FAILS = 5;            // wrong passwords from one IP address...
const LOCK_SECONDS = 3600;      // ...lock that address out for an hour
const SESSION_DAYS = 7;
$PRIV = dirname(__DIR__, 2) . '/demos-private';

$NOINDEX = 'noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai';
header('X-Robots-Tag: ' . $NOINDEX);
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rel = ltrim((string) preg_replace('#^/demos/?#', '', rawurldecode($path)), '/');
[$slug, $rest] = array_pad(explode('/', $rel, 2), 2, null);

if ($slug === '' || $slug === 'index.php' || !preg_match('/^[a-z0-9][a-z0-9-]{2,60}$/', (string) $slug)) not_found();
if ($rest === null) { header('Location: /demos/' . $slug . '/', true, 301); exit; }

$dir = "$PRIV/$slug";
$exists = is_dir("$dir/site") && is_file("$dir/password.hash");   // unknown names get the same sign-in page (no guessing which exist)
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$cookie = 'mo_demo_' . substr(hash('sha256', $slug), 0, 10);

if (!($exists && signed_in($slug, $cookie))) {
    $error = '';
    $lock = lock_left($ip);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lock === 0) {
        $pw = (string) ($_POST['password'] ?? '');
        if ($exists && $pw !== '' && password_verify($pw, trim((string) file_get_contents("$dir/password.hash")))) {
            clear_fails($ip);
            $exp = time() + SESSION_DAYS * 86400;
            setcookie($cookie, $exp . '.' . sign("$slug|$exp"), ['expires' => $exp, 'path' => "/demos/$slug/", 'secure' => https(), 'httponly' => true, 'samesite' => 'Lax']);
            view_log($slug, $ip, 'signed in');
            header('Location: /demos/' . $slug . '/', true, 303);
            exit;
        }
        usleep(800000);
        $left = add_fail($ip);
        $lock = lock_left($ip);
        $error = $lock ? '' : 'That password isn’t right. ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left before this connection is locked for an hour.';
    }
    sign_in_page($exists ? trim((string) @file_get_contents("$dir/name.txt")) : '', $error, $lock);
}

// ---- signed in: serve the file ----
$base = realpath("$dir/site");
$want = ($rest === '' || str_ends_with($rest, '/')) ? $rest . 'index.html' : $rest;
$file = realpath("$base/$want");
if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) not_found();
$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg',
          'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
          'woff2' => 'font/woff2', 'woff' => 'font/woff', 'mp4' => 'video/mp4'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!isset($types[$ext])) not_found();
header('Content-Type: ' . $types[$ext]);

if ($ext === 'html') {
    header('Cache-Control: private, no-store');
    echo protect((string) file_get_contents($file), $slug, viewer_code($slug, $ip));
} else {
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($file));
    readfile($file);
}
exit;

// ================= helpers =================

function secret(): string {
    global $PRIV;
    $f = "$PRIV/_secret";
    if (!is_file($f)) { @mkdir($PRIV, 0750, true); file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX); @chmod($f, 0600); }
    return trim((string) file_get_contents($f));
}
function https(): bool { $h = strtolower((string) ($_SERVER['HTTPS'] ?? '')); return $h !== '' && $h !== 'off'; }
function sign(string $s): string { return hash_hmac('sha256', $s, secret()); }

function signed_in(string $slug, string $cookie): bool {
    $v = (string) ($_COOKIE[$cookie] ?? '');
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $v, $m) || (int) $m[1] < time()) return false;
    return hash_equals(sign($slug . '|' . $m[1]), $m[2]);
}

/** Wrong-password counter for one IP address: [fails, first fail time, locked until]. */
function attempts_file(string $ip): string {
    global $PRIV;
    @mkdir("$PRIV/_attempts", 0750, true);
    return "$PRIV/_attempts/" . hash('sha256', $ip) . '.json';
}
function read_attempts(string $ip): array {
    $a = json_decode((string) @file_get_contents(attempts_file($ip)), true);
    return is_array($a) ? $a + ['f' => 0, 't' => 0, 'until' => 0] : ['f' => 0, 't' => 0, 'until' => 0];
}
function lock_left(string $ip): int {
    $a = read_attempts($ip);
    return max(0, (int) $a['until'] - time());
}
function add_fail(string $ip): int {
    $f = attempts_file($ip);
    $h = fopen($f, 'c+');
    flock($h, LOCK_EX);
    $a = json_decode((string) stream_get_contents($h), true);
    $a = is_array($a) ? $a : [];
    $now = time();
    if (empty($a['t']) || $now - (int) $a['t'] > LOCK_SECONDS) $a = ['f' => 0, 't' => $now, 'until' => 0];   // count within a rolling hour
    $a['f'] = (int) $a['f'] + 1;
    if ($a['f'] >= MAX_FAILS) { $a['until'] = $now + LOCK_SECONDS; $a['f'] = 0; $a['t'] = 0; }
    ftruncate($h, 0); rewind($h); fwrite($h, json_encode($a));
    flock($h, LOCK_UN); fclose($h);
    return $a['until'] ? 0 : MAX_FAILS - (int) $a['f'];
}
function clear_fails(string $ip): void { @unlink(attempts_file($ip)); }

/** Short code shown in the watermark; the same viewer gets the same code that day, so a leak can be traced. */
function viewer_code(string $slug, string $ip): string {
    return strtoupper(substr(hash_hmac('sha256', "$slug|$ip|" . gmdate('Y-m-d'), secret()), 0, 6));
}
function view_log(string $slug, string $ip, string $what): void {
    global $PRIV;
    $ua = substr(preg_replace('/[\r\n\t]+/', ' ', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 160);
    @file_put_contents("$PRIV/_views.log", gmdate('c') . "\t$slug\t$ip\t" . viewer_code($slug, $ip) . "\t$what\t$ua\n", FILE_APPEND | LOCK_EX);
}

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function not_found(): void {
    http_response_code(404);
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
       . '<title>Not found</title><body style="margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.5 system-ui,sans-serif;background:#f4f5f2;color:#1d2420">'
       . '<p>There’s nothing here. <a href="https://modern-ops.net/" style="color:#1a6b4a">Go to modern-ops.net</a></p>';
    exit;
}

function sign_in_page(string $company, string $error, int $lock): void {
    header('Cache-Control: no-store');
    if ($lock) http_response_code(429);
    $mins = (int) ceil($lock / 60);
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?= $company !== '' ? esc($company) . ' — website preview' : 'Website preview' ?></title>
<style>
  :root{--bg:#f4f5f2;--card:#fff;--ink:#1d2420;--muted:#5d6762;--line:#d9ddd8;--accent:#1a6b4a;--err:#a8322a;color-scheme:light}
  @media (prefers-color-scheme:dark){:root{--bg:#131715;--card:#1b201d;--ink:#eef1ee;--muted:#a3aca7;--line:#2e3532;--accent:#35b082;--err:#ff8a7d;color-scheme:dark}}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px max(16px,env(safe-area-inset-left))}
  main{width:100%;max-width:400px;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:28px 26px}
  .by{font-size:.78rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:0 0 6px}
  h1{font-size:1.45rem;line-height:1.25;margin:0 0 8px}
  p{margin:0 0 18px;color:var(--muted)}
  label{display:block;font-weight:600;font-size:.9rem;margin-bottom:6px}
  input{width:100%;height:46px;border:1px solid var(--line);border-radius:8px;padding:0 12px;font:inherit;background:var(--bg);color:var(--ink)}
  input:focus{outline:2px solid var(--accent);outline-offset:1px}
  button{margin-top:14px;width:100%;height:46px;border:0;border-radius:8px;background:var(--accent);color:#fff;font:inherit;font-weight:600;cursor:pointer}
  .err{color:var(--err);font-size:.92rem;margin:12px 0 0}
  .lock{background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:12px 14px;color:var(--ink)}
  footer{margin-top:18px;font-size:.8rem;color:var(--muted)}
  footer a{color:var(--accent)}
</style></head>
<body><main>
  <p class="by">Website preview by Modern Ops</p>
  <h1><?= $company !== '' ? 'A new website for ' . esc($company) : 'Private website preview' ?></h1>
  <?php if ($lock): ?>
    <p class="lock">Too many wrong passwords from your connection. Try again in <?= $mins ?> minute<?= $mins === 1 ? '' : 's' ?>, or ask Modern Ops for help.</p>
  <?php else: ?>
    <p>Enter the password Modern Ops sent you to see the preview.</p>
    <form method="post" autocomplete="off">
      <label for="pw">Password</label>
      <input id="pw" name="password" type="password" required autofocus autocapitalize="off" autocorrect="off" spellcheck="false">
      <button type="submit">View preview</button>
      <?php if ($error): ?><p class="err" role="alert"><?= esc($error) ?></p><?php endif; ?>
    </form>
  <?php endif; ?>
  <footer>Questions? <a href="mailto:info@modern-ops.net">info@modern-ops.net</a></footer>
</main></body></html><?php
    exit;
}

/** Add the noindex tag, the watermark, and copy/save deterrents to a demo page. */
function protect(string $html, string $slug, string $code): string {
    global $NOINDEX;
    $host = json_encode((string) ($_SERVER['HTTP_HOST'] ?? 'modern-ops.net'));
    $label = 'Made by Modern Ops · not for production use';
    $tile = rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="460" height="260"><g transform="rotate(-24 230 130)" font-family="Arial,Helvetica,sans-serif" font-size="17" font-weight="700" text-anchor="middle">'
          . '<text x="230" y="120" fill="#000" fill-opacity=".09" stroke="#fff" stroke-opacity=".10" stroke-width="2.4" paint-order="stroke">' . $label . '</text>'
          . '<text x="230" y="146" fill="#000" fill-opacity=".07" stroke="#fff" stroke-opacity=".08" stroke-width="2" paint-order="stroke" font-size="12">Preview ' . $code . ' · © Modern Ops</text></g></svg>');
    $head = '<meta name="robots" content="' . $NOINDEX . '"><meta name="googlebot" content="' . $NOINDEX . '">'
      . '<meta name="copyright" content="© Modern Ops. Design not licensed for use.">'
      . '<style id="mo-guard">'
      . '#mo-wm{position:fixed;inset:0;z-index:2147483646;pointer-events:none;background:url("data:image/svg+xml,' . $tile . '") repeat}'
      . '#mo-badge{position:fixed;left:max(12px,env(safe-area-inset-left,0px));bottom:max(12px,env(safe-area-inset-bottom,0px));z-index:2147483647;pointer-events:none;'
      . 'font:600 12px/1.2 Arial,Helvetica,sans-serif;letter-spacing:.02em;color:#fff;background:rgba(17,22,19,.82);padding:7px 10px;border-radius:6px;box-shadow:0 2px 10px rgba(0,0,0,.25)}'
      . '#mo-badge b{color:#9fe0c3}'
      . '@media (max-width:880px){#mo-badge{bottom:calc(84px + env(safe-area-inset-bottom,0px));font-size:11px}}'
      . 'html,body{-webkit-user-select:none;user-select:none;-webkit-touch-callout:none}input,textarea,select{-webkit-user-select:text;user-select:text}img{-webkit-user-drag:none;user-drag:none}'
      . '#mo-print{display:none}@media print{body>*:not(#mo-print){display:none!important}#mo-print{display:block!important;font:16px Arial,sans-serif;padding:40px}}'
      . '</style>';
    $body = '<div id="mo-wm" aria-hidden="true"></div>'
      . '<div id="mo-badge" role="note">' . $label . ' · <b>' . $code . '</b></div>'
      . '<div id="mo-print">This preview by Modern Ops can’t be printed. Ask info@modern-ops.net for a copy.</div>'
      . '<script>(function(){'
      . 'var host=' . $host . ';'
      // a saved copy opened anywhere else shows only a notice
      . 'if(location.host!==host){document.documentElement.innerHTML="<body style=\'font:16px Arial,sans-serif;padding:40px\'>This design belongs to Modern Ops and is shown only at modern-ops.net. It is not licensed for use.</body>";return;}'
      . 'var typing=function(e){var t=e.target;return t&&(t.isContentEditable||/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));};'
      . '["contextmenu","dragstart","copy","cut"].forEach(function(ev){document.addEventListener(ev,function(e){if(!typing(e))e.preventDefault();},true);});'
      . 'document.addEventListener("keydown",function(e){var k=(e.key||"").toLowerCase(),m=e.ctrlKey||e.metaKey;'
      . 'if((m&&"supa".indexOf(k)>-1&&!typing(e))||(m&&e.shiftKey&&"ijc".indexOf(k)>-1)||k==="f12"){e.preventDefault();}},true);'
      // put the watermark back if someone removes or hides it
      . 'var css=document.getElementById("mo-guard").outerHTML,wm=document.getElementById("mo-wm").outerHTML,badge=document.getElementById("mo-badge").outerHTML;'
      . 'function heal(){if(!document.getElementById("mo-guard"))document.head.insertAdjacentHTML("beforeend",css);'
      . '["mo-wm","mo-badge"].forEach(function(id,i){var el=document.getElementById(id);if(!el||el.style.cssText||el.hidden||el.parentNode!==document.body){if(el)el.remove();document.body.insertAdjacentHTML("afterbegin",i?badge:wm);}});}'
      . 'new MutationObserver(heal).observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:["style","class","hidden","id"]});setInterval(heal,1500);'
      . '})();</script>';
    $html = preg_replace('/<head(\s[^>]*)?>/i', '<head$1>' . $head, $html, 1, $n1);
    $html = preg_replace('/<body(\s[^>]*)?>/i', '<body$1>' . $body, $html, 1, $n2);
    if (!$n1 || !$n2) $html = $head . $body . $html;
    return "<!-- © Modern Ops. This design is a private preview made for one company and is not licensed for use, copying or resale. Viewer $code. -->\n" . $html;
}
