<?php
/** Output a quote as a PDF. */
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_quote_pdf.php';
require_login();
require_product('quotes');

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT quote_number FROM quotes WHERE id = ?');
$st->execute([$id]);
$num = $st->fetchColumn();
$pdf = build_quote_pdf($id);
if ($pdf === null) { http_response_code(404); echo 'Quote not found.'; exit; }

$name = quote_file_name($num);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
