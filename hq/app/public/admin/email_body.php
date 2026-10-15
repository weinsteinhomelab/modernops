<?php
/** The HTML body of one email, shown inside a sandboxed iframe on the Email tab. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_imap.php';
require_login();
require_product('inbox');

$images = !empty($_GET['img']);
$img = $images ? "data: https: http:" : "data:";
// Replace the dashboard's headers: this page may be framed by the dashboard only,
// runs no scripts, and can't load anything but styles/fonts (and images if allowed).
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: sandbox allow-popups allow-popups-to-escape-sandbox allow-same-origin; default-src 'none'; style-src 'unsafe-inline' https:; font-src https: data:; img-src $img; frame-ancestors 'self'");
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store');

if (!imap_ready()) { http_response_code(503); exit; }
$accounts = my_mailboxes();   // admins: all; users: the ones an admin gave them
if (!$accounts) deny('No mailboxes have been shared with you yet.');
$ai = (int)($_GET['acct'] ?? 0);
if ($ai < 0 || $ai >= count($accounts)) $ai = 0;

try { $msg = imap_open($accounts[$ai], (int)($_GET['uid'] ?? 0)); }
catch (\Throwable $e) { $msg = null; }
if (!$msg || $msg['html'] === '') { http_response_code(404); echo 'Message not found.'; exit; }

echo email_html_doc($msg['html'], $images);
