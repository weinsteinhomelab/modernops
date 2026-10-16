<?php
/**
 * Public images for email signatures (people who get the email aren't logged in).
 *   ?k=<32-hex key>  an uploaded photo or logo
 *   ?logo=1          the company logo from the client file
 * Only images are served; the key is long and random.
 */
require_once __DIR__ . '/../admin/_signature.php';

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

if (!empty($_GET['logo'])) {
    $c = sig_company_logo();
    if (!$c) { http_response_code(404); exit; }
    header('Content-Type: ' . $c['mime']);
    header('Cache-Control: public, max-age=86400');
    readfile($c['path']);
    exit;
}

$k = (string)($_GET['k'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $k)) { http_response_code(404); exit; }
try {
    sig_ensure();
    $st = db()->prepare('SELECT mime, bytes FROM sig_images WHERE k = ?');
    $st->execute([$k]);
    $row = $st->fetch();
} catch (Throwable $e) { $row = null; }
if (!$row || !in_array($row['mime'], ['image/png', 'image/jpeg', 'image/gif'], true)) { http_response_code(404); exit; }
header('Content-Type: ' . $row['mime']);
header('Cache-Control: public, max-age=31536000, immutable');   // a new upload gets a new key
echo $row['bytes'];
