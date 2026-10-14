<?php
/**
 * Public endpoint for the customer's quote page (client website, e.g. modern-ops.net/quote/).
 *   GET  ?t=<token>  -> the quote as HTML + what the accept form needs
 *   POST {t, name, agree, optional:[ids]} -> accept the quote
 * The token is the only key, so it is long and random. No cost or margin ever leaves here.
 */
require_once __DIR__ . '/../admin/_db.php';
require_once __DIR__ . '/../admin/_mail.php';
require_once __DIR__ . '/../admin/_quote_render.php';

// --- CORS: the client's website (apex + www) and the accept page's own origin ---
$allowed = [];
foreach ([SITE_ORIGIN, (string) client('quotes.accept_page', '')] as $u) {
    if (!preg_match('#^(https?://[^/]+)#', $u, $mm)) continue;
    $o = $mm[1];
    $allowed[] = $o;
    $allowed[] = strpos($o, '://www.') !== false ? str_replace('://www.', '://', $o) : str_replace('://', '://www.', $o);
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed, true)) header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function out(int $code, array $data): void { http_response_code($code); echo json_encode($data); exit; }

if (!product_on('quotes') || trim((string) client('quotes.accept_page', '')) === '') out(404, ['ok' => false, 'error' => 'not enabled']);

try {
    require_once __DIR__ . '/../admin/_migrate.php';
    maybe_migrate();
} catch (Throwable $e) {}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = [];
if ($method === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
}
$token = (string) ($method === 'POST' ? ($in['t'] ?? '') : ($_GET['t'] ?? ''));
$id = quote_id_for_token($token);
if (!$id) out(404, ['ok' => false, 'error' => 'This quote link is not valid. Please check the link or contact us.']);

[$q, $items] = quote_load($id);
$expired = !empty($q['valid_until']) && strtotime($q['valid_until'] . ' 23:59:59') < time() && empty($q['accepted_at']);
$optional = [];
foreach ($items as $r) {
    if (empty($r['is_optional']) || !empty($r['is_section'])) continue;
    $optional[] = ['id' => (int) $r['id'], 'title' => $r['title'],
                   'price' => money((float)$r['quantity'] * (float)$r['unit_price']) . billing_suffix($r['billing'])];
}

if ($method === 'GET') {
    if (empty($q['viewed_at'])) db()->prepare('UPDATE quotes SET viewed_at = NOW() WHERE id = ? AND viewed_at IS NULL')->execute([$id]);
    out(200, [
        'ok' => true,
        'number' => $q['quote_number'],
        'business' => $q['c_business'],
        'status' => $q['status'],
        'expired' => $expired,
        'valid_until' => $q['valid_until'] ? date('F j, Y', strtotime($q['valid_until'])) : null,
        'accepted' => $q['accepted_at'] ? ['name' => $q['accepted_name'], 'at' => date('F j, Y \a\t g:ia', strtotime($q['accepted_at']))] : null,
        'terms' => (int) $q['terms_included'] === 1 ? quote_terms() : [],
        'optional' => $optional,
        'company' => ['name' => client('name'), 'email' => client('email.contact')],
        'html' => quote_paper_html($q, $items),
    ]);
}

if ($method !== 'POST') out(405, ['ok' => false, 'error' => 'method']);

// --- Accept ---
if (!empty($q['accepted_at'])) out(409, ['ok' => false, 'error' => 'This quote has already been accepted.']);
if ($q['status'] === 'declined') out(409, ['ok' => false, 'error' => 'This quote is no longer open. Please contact us.']);
if ($expired) out(409, ['ok' => false, 'error' => 'This quote has expired. Please contact us for an updated quote.']);
$name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')));
if (mb_strlen($name) < 2 || mb_strlen($name) > 160) out(422, ['ok' => false, 'error' => 'Please type your full name to sign.']);
if ((int) $q['terms_included'] === 1 && empty($in['agree'])) out(422, ['ok' => false, 'error' => 'Please tick the box to agree to the terms.']);
$validOpt = array_column($optional, 'id');
$chosen = array_values(array_intersect($validOpt, array_map('intval', (array) ($in['optional'] ?? []))));

$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$up = db()->prepare("UPDATE quotes SET status='accepted', accepted_at=NOW(), accepted_name=?, accepted_ip=?, accepted_ua=?, accepted_optional=?
                     WHERE id=? AND accepted_at IS NULL");
$up->execute([$name, mb_substr($ip, 0, 64), $ua, json_encode($chosen), $id]);
if ($up->rowCount() !== 1) out(409, ['ok' => false, 'error' => 'This quote has already been accepted.']);

// Tell the business (best effort; never block the customer on email)
$titles = [];
foreach ($optional as $o) if (in_array($o['id'], $chosen, true)) $titles[] = $o['title'] . ' (' . $o['price'] . ')';
$n = quote_numbers($q, $items);
$body = "Quote #{$q['quote_number']} for {$q['c_business']} was accepted online.\n\n"
      . "Signed by: $name\n"
      . 'Due on acceptance: ' . money($n['oneTotal']) . "\n"
      . ($titles ? "Optional add-ons they chose:\n- " . implode("\n- ", $titles) . "\n" : '')
      . "\nIP: $ip\n";
try { send_mail(NOTIFY_EMAIL, "Quote #{$q['quote_number']} accepted by $name", $body); } catch (Throwable $e) {}

[$q] = quote_load($id);
out(200, ['ok' => true, 'accepted' => ['name' => $q['accepted_name'], 'at' => date('F j, Y \a\t g:ia', strtotime($q['accepted_at']))]]);
