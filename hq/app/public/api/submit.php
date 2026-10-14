<?php
/**
 * Public endpoint — receives the website contact form and stores it as a
 * request, then emails a notification. Called cross-origin from the site.
 */
require_once __DIR__ . '/../admin/_db.php';
require_once __DIR__ . '/../admin/_mail.php';

// --- CORS (allow the public website — apex and www — to post here) ---
$allowed = [SITE_ORIGIN];
if (preg_match('#^https?://#', SITE_ORIGIN)) {
    $allowed[] = (strpos(SITE_ORIGIN, '://www.') !== false)
        ? str_replace('://www.', '://', SITE_ORIGIN)   // add apex
        : str_replace('://', '://www.', SITE_ORIGIN);  // add www
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json');
// Website requests are part of Ops Customers.
if (!product_on('customers')) {
    http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not enabled']); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit;
}

// Accept form-encoded or JSON
$in = $_POST;
if (empty($in)) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) $in = $json;
}

// Honeypot: real users leave this empty
if (!empty($in['company_website'] ?? '')) { echo json_encode(['ok' => true]); exit; }

$name     = trim((string)($in['name'] ?? ''));
$business = trim((string)($in['business'] ?? ''));
$email    = trim((string)($in['email'] ?? ''));
$phone    = trim((string)($in['phone'] ?? ''));
$message  = trim((string)($in['message'] ?? ''));
$type     = (string)($in['type'] ?? 'quote');
if (!in_array($type, ['quote','service','general'], true)) $type = 'quote';

if ($name === '' || ($email === '' && $phone === '')) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please include your name and an email or phone.']);
    exit;
}
$name = mb_substr($name, 0, 160);
$email = mb_substr($email, 0, 190);

try {
    $st = db()->prepare(
        'INSERT INTO requests (name, business, email, phone, type, message, status, source)
         VALUES (?,?,?,?,?,?,\'new\',\'website\')'
    );
    $st->execute([$name, $business, $email, $phone, $type, $message]);
} catch (Exception $ex) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save. Please try again.']);
    exit;
}

// Notify (best effort — never block the visitor on email)
$subject = 'New ' . $type . ' request from ' . $name;
$body = "New website request\n\n"
      . "Name: $name\n"
      . "Business: $business\n"
      . "Email: $email\n"
      . "Phone: $phone\n"
      . "Type: $type\n\n"
      . "Message:\n$message\n";
send_mail(NOTIFY_EMAIL, $subject, $body, $email);

echo json_encode(['ok' => true]);
