<?php
/** Email a quote PDF to the customer, from quotes@modern-ops.net. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_mail.php';
require_once __DIR__ . '/_quote_pdf.php';
require_login();

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

$toEmail = $q['contact_email'] ?: $q['biz_email'];
if (empty($toEmail)) { header('Location: quote_view.php?id=' . $id . '&mailerr=1'); exit; }

$pdf = build_quote_pdf($id);
if ($pdf === null) { header('Location: quote_view.php?id=' . $id . '&mailerr=1'); exit; }

$greet = $q['first_name'] ?: $q['biz_name'];
$subject = 'Your quote from Modern Ops (#' . $q['quote_number'] . ')';
$body = "Hi " . $greet . ",\n\n"
      . "Thank you for your interest in Modern Ops. Please find your quote attached as a PDF.\n\n"
      . "If you have any questions or would like to move forward, just reply to this email.\n\n"
      . "Best regards,\nModern Ops\nquotes@modern-ops.net\nmodern-ops.net";
$name = 'Modern-Ops-Quote-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$q['quote_number']) . '.pdf';

$ok = send_mail_pdf($toEmail, $subject, $body, $pdf, $name, QUOTE_MAIL_FROM, QUOTE_SMTP_USER, QUOTE_MAIL_FROM, QUOTE_MAIL_FROM_NAME);
if ($ok) {
    if ($q['status'] === 'draft') db()->prepare("UPDATE quotes SET status='sent' WHERE id=?")->execute([$id]);
    header('Location: quote_view.php?id=' . $id . '&sent=1');
} else {
    header('Location: quote_view.php?id=' . $id . '&mailerr=1');
}
exit;
