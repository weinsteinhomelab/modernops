<?php
/** One website picture (?id=), for people who can see its customer. ?dl=1 downloads it. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_sites.php';
require_login();
require_product('customers', 'quotes');

$pic = site_pic_get((int)($_GET['id'] ?? 0));
if (!$pic || !isset(SITE_PIC_TYPES[$pic['mime']])) { http_response_code(404); exit('Picture not found.'); }

$name = preg_replace('/[^\w .()-]+/', '_', (string)$pic['name']) ?: 'picture.' . SITE_PIC_TYPES[$pic['mime']];
header('Content-Type: ' . $pic['mime']);
header('Content-Length: ' . strlen($pic['data']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('Cache-Control: private, max-age=86400');
echo $pic['data'];
