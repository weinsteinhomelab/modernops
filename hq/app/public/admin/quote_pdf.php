<?php
/** Output a quote as a PDF. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_quote_pdf.php';
require_login();
require_product('quotes');

$id = (int)($_GET['id'] ?? 0);
require_perm('quotes.send');
$st = db()->prepare('SELECT * FROM quotes WHERE id = ?');
$st->execute([$id]);
$qrow = $st->fetch();
if (!$qrow) { http_response_code(404); echo 'Quote not found.'; exit; }
if (!can_see_quote($qrow)) deny('This quote belongs to someone else.');
if (!quote_cleared($qrow) && !is_admin()) { header('Location: quote_view.php?id=' . $id . '&blocked=1'); exit; }
$num = $qrow['quote_number'];
$pdf = build_quote_pdf($id);
if ($pdf === null) { http_response_code(404); echo 'Quote not found.'; exit; }

$name = quote_file_name($num);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
