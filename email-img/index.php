<?php
/**
 * Relays email-signature images from Modern Ops HQ so emails show pictures from modern-ops.net
 * rather than HQ's own address. Uploaded images never change (a new upload gets a new key), so
 * they're cached on disk for good; the company logo is re-fetched once a day.
 */
const HQ_IMAGES = 'https://hq.kobylab.dev/api/sig_image.php';
const TYPES = ['image/png', 'image/jpeg', 'image/gif'];

$logo = !empty($_GET['logo']);
$k = (string)($_GET['k'] ?? '');
if (!$logo && !preg_match('/^[a-f0-9]{32}$/', $k)) { http_response_code(404); exit; }

$cacheDir = dirname(__DIR__, 2) . '/email-img-cache';   // outside public_html
@mkdir($cacheDir, 0750, true);
$name = $logo ? 'logo' : $k;
$file = $cacheDir . '/' . $name . '.bin';
$meta = $cacheDir . '/' . $name . '.type';
$fresh = is_file($file) && is_file($meta) && (!$logo || filemtime($file) > time() - 86400);

if (!$fresh) {
    $ch = curl_init(HQ_IMAGES . ($logo ? '?logo=1' : '?k=' . $k));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
    curl_close($ch);
    if ($code === 200 && $body !== false && in_array($type, TYPES, true)) {
        file_put_contents($file, $body);
        file_put_contents($meta, $type);
    } elseif (!is_file($file)) {
        http_response_code(404); exit;      // HQ unreachable and nothing cached yet
    }
}

$type = trim((string) @file_get_contents($meta));
if (!in_array($type, TYPES, true)) { http_response_code(404); exit; }
header('Content-Type: ' . $type);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=' . ($logo ? 86400 : 31536000));
readfile($file);
