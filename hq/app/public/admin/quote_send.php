<?php
/** Email a quote PDF to the customer. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_mail.php';
require_once __DIR__ . '/_quote_pdf.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: quotes.php'); exit; }
check_csrf();

$id = (int)($_POST['id'] ?? 0);
$st = db()->prepare('SELECT q.*, c.name AS c_name, c.email AS c_email FROM quotes q JOIN customers c ON c.id=q.customer_id WHERE q.id=?');
$st->execute([$id]);
$q = $st->fetch();
if (!$q) { http_response_code(404); echo 'Quote not found.'; exit; }

if (empty($q['c_email'])) { header('Location: quote_view.php?id=' . $id . '&mailerr=1'); exit; }

$pdf = build_quote_pdf($id);
if ($pdf === null) { header('Location: quote_view.php?id=' . $id . '&mailerr=1'); exit; }

$subject = 'Your quote from Modern Ops (#' . $q['quote_number'] . ')';
$body = "Hi " . $q['c_name'] . ",\n\n"
      . "Thank you for your interest in Modern Ops. Please find your quote attached as a PDF.\n\n"
      . "If you have any questions or would like to move forward, just reply to this email.\n\n"
      . "Best regards,\nModern Ops\ninfo@modern-ops.net\nmodern-ops.net";
$name = 'Modern-Ops-Quote-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$q['quote_number']) . '.pdf';

$ok = send_mail_pdf($q['c_email'], $subject, $body, $pdf, $name, MAIL_FROM);
if ($ok) {
    if ($q['status'] === 'draft') db()->prepare("UPDATE quotes SET status='sent' WHERE id=?")->execute([$id]);
    header('Location: quote_view.php?id=' . $id . '&sent=1');
} else {
    header('Location: quote_view.php?id=' . $id . '&mailerr=1');
}
exit;
