<?php
/** Email a quote PDF to the customer, from the client's quote mailbox (email.quote_from). */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_mail.php';
require_once __DIR__ . '/_quote_pdf.php';
require_login();
require_product('quotes');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: quotes.php'); exit; }
check_csrf();

$id = (int)($_POST['id'] ?? 0);
$st = db()->prepare(
    'SELECT q.*, b.name AS biz_name, b.main_email AS biz_email,
            ct.first_name, ct.email AS contact_email
     FROM quotes q
     JOIN businesses b ON b.id = q.business_id
     LEFT JOIN contacts ct ON ct.id = q.contact_id
     WHERE q.id = ?');
$st->execute([$id]);
$q = $st->fetch();
if (!$q) { http_response_code(404); echo 'Quote not found.'; exit; }
require_perm('quotes.send');
if (!can_see_quote($q)) deny('This quote belongs to someone else.');
// Pricing that differs from the pricebook has to be approved first. Quotes always go out from the quote mailbox.
if (!quote_cleared($q) && !is_admin()) { header('Location: quote_view.php?id=' . $id . '&blocked=1'); exit; }

$toEmail = $q['contact_email'] ?: $q['biz_email'];
if (empty($toEmail)) { header('Location: quote_view.php?id=' . $id . '&mailerr=1'); exit; }

$pdf = build_quote_pdf($id);
if ($pdf === null) { header('Location: quote_view.php?id=' . $id . '&mailerr=1'); exit; }

$greet = $q['first_name'] ?: $q['biz_name'];
// Wording comes from the client file; {name}, {number} and {link} are filled in here.
$link = quote_accept_url($id);
$fill = ['{name}' => $greet, '{number}' => $q['quote_number'], '{link}' => $link];
$subject = strtr((string) client('quotes.email_subject', 'Your quote (#{number})'), $fill);
$tpl = (string) client('quotes.email_body', "Hi {name},\n\nYour quote is attached as a PDF.");
if ($link === '') $tpl = preg_replace('/[^\n]*\n?\{link\}\n?/', '', $tpl);   // no accept page: drop the link lines
elseif (strpos($tpl, '{link}') === false) $tpl .= "\n\nView and accept your quote online:\n{link}";
$body = strtr($tpl, $fill);
$name = quote_file_name($q['quote_number']);

$ok = send_mail_pdf($toEmail, $subject, $body, $pdf, $name, QUOTE_MAIL_FROM, QUOTE_SMTP_USER, QUOTE_MAIL_FROM, QUOTE_MAIL_FROM_NAME);
if ($ok) {
    log_activity('quote.sent', 'quote', $id, 'Quote #' . $q['quote_number'] . ' to ' . $toEmail);
    if ($q['status'] === 'draft') db()->prepare("UPDATE quotes SET status='sent' WHERE id=?")->execute([$id]);
    header('Location: quote_view.php?id=' . $id . '&sent=1');
} else {
    header('Location: quote_view.php?id=' . $id . '&mailerr=1');
}
exit;
