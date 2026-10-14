<?php
/** Build the quote PDF (FPDF). Name, colors, terms and wording come from the client file. */
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_billing.php';
require_once __DIR__ . '/_brand.php';
require_once __DIR__ . '/../../lib/fpdf.php';

/** UTF-8 -> cp1252 for FPDF core fonts. */
function tx($s): string {
    $s = (string)$s;
    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    return $out === false ? $s : $out;
}

/** Terms & Conditions: [heading, text] pairs from the client file. */
function quote_terms(): array {
    return (array) client('quotes.terms', []);
}

/** Quote colors as RGB for FPDF: deep (headings, dark total box) and tint (bands). */
function quote_deep(): array { return hex_rgb(brand_color('deep', '#0F3D2A')); }
function quote_tint(): array { return hex_rgb(brand_color('tint', '#E6F4EC')); }

/** PDF file name, e.g. Modern-Ops-Quote-1001.pdf */
function quote_file_name($number): string {
    $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string) client('quotes.file_prefix', 'Quote')) ?: 'Quote';
    return $prefix . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $number) . '.pdf';
}

function build_quote_pdf(int $id): ?string {
    $st = db()->prepare(
        'SELECT q.*, b.name AS biz_name, ct.first_name, ct.last_name
         FROM quotes q
         JOIN businesses b ON b.id = q.business_id
         LEFT JOIN contacts ct ON ct.id = q.contact_id
         WHERE q.id = ?');
    $st->execute([$id]);
    $q = $st->fetch();
    if (!$q) return null;
    $q['c_business'] = $q['biz_name'];
    $q['c_name'] = trim(($q['first_name'] ?? '') . ' ' . ($q['last_name'] ?? ''));
    $it = db()->prepare('SELECT * FROM quote_items WHERE quote_id=? ORDER BY sort_order, id');
    $it->execute([$id]);
    $items = $it->fetchAll();
    return render_quote_pdf($q, $items);
}

function render_quote_pdf(array $q, array $items): string {
    // Optional add-ons are listed after the totals and never counted in them.
    $optItems = array_values(array_filter($items, fn($i) => !empty($i['is_optional'])));
    $items = array_values(array_filter($items, fn($i) => empty($i['is_optional'])));
    $one = array_values(array_filter($items, fn($i) => billing_clean($i['billing']) === 'one_time'));
    $mo  = array_values(array_filter($items, fn($i) => billing_clean($i['billing']) !== 'one_time'));
    $recTotals = billing_recurring_totals($items);
    $sum = function ($rows) { $t = 0; foreach ($rows as $r) $t += (float)$r['quantity'] * (float)$r['unit_price']; return $t; };
    $oneSub = $sum($one); $moSub = $sum($mo);
    $rate = (float)$q['tax_rate']; $tax = $oneSub * $rate / 100; $oneTotal = $oneSub + $tax;
    $m = fn($n) => '$' . number_format((float)$n, 2);

    $GREEN = quote_deep(); $BAND = quote_tint(); $GREY = [119, 119, 119]; $LINE = [221, 221, 221];
    $L = 18; $R = 18; $pageW = 215.9; $contentW = $pageW - $L - $R; $priceW = 34; $descW = $contentW - $priceW;

    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->SetMargins($L, 16, $R);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AddPage();

    // Header
    $pdf->SetFont('Arial', 'B', 18); $pdf->SetTextColor(...$GREEN);
    $pdf->Cell(0, 9, tx(mb_strtoupper((string) client('name'))), 0, 1);
    $pdf->SetFont('Arial', '', 8); $pdf->SetTextColor(...$GREY);
    $pdf->Cell(0, 5, tx(implode('   |   ', array_filter([client('tagline'), client('domain'), client('location')]))), 0, 1);
    $y = $pdf->GetY() + 1; $pdf->SetDrawColor(...$GREEN); $pdf->SetLineWidth(0.5); $pdf->Line($L, $y, $pageW - $R, $y);
    $pdf->Ln(5);

    // QUOTE + fields
    $pdf->SetFont('Arial', 'B', 13); $pdf->SetTextColor(...$GREEN); $pdf->Cell(0, 7, tx('QUOTE'), 0, 1);
    $pdf->Ln(1);
    $field = function ($label, $val) use ($pdf, $GREEN) {
        $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(...$GREEN); $pdf->Cell(28, 6, tx($label), 0, 0);
        $pdf->SetFont('Arial', '', 9); $pdf->SetTextColor(26, 26, 26); $pdf->Cell(0, 6, tx($val), 0, 1);
    };
    if (trim((string)$q['c_name']) !== '') $field('Prepared for:', $q['c_name']);
    if ($q['c_business']) $field('Business:', $q['c_business']);
    $field('Quote date:', date('M j, Y', strtotime($q['issue_date'])));
    if ($q['valid_until']) $field('Valid until:', date('M j, Y', strtotime($q['valid_until'])));
    $field('Quote #:', $q['quote_number']);
    $pdf->Ln(3);

    $band = function ($label) use ($pdf, $BAND, $GREEN) {
        $pdf->SetFillColor(...$BAND); $pdf->SetTextColor(...$GREEN); $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(0, 6, tx('  ' . $label), 0, 1, 'L', true); $pdf->Ln(1);
    };

    // Scope of work
    if (trim((string)($q['scope_of_work'] ?? '')) !== '') {
        $band('SCOPE OF WORK');
        $pdf->SetFont('Arial', '', 9); $pdf->SetTextColor(51, 51, 51);
        $pdf->MultiCell(0, 4.6, tx($q['scope_of_work']), 0, 'L');
        $pdf->Ln(3);
    }
    $renderRows = function ($rows) use ($pdf, $descW, $priceW, $LINE, $GREY, $m) {
        foreach ($rows as $r) {
            if (!empty($r['is_section'])) {
                $pdf->Ln(1); $pdf->SetFont('Arial', 'B', 9.5); $pdf->SetTextColor(...quote_deep());
                $pdf->Cell($descW + $priceW, 5.5, tx($r['title']), 0, 1, 'L');
                $pdf->SetDrawColor(...quote_deep()); $pdf->SetLineWidth(0.4);
                $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + $descW + $priceW, $pdf->GetY()); $pdf->Ln(2);
                continue;
            }
            $lt = (float)$r['quantity'] * (float)$r['unit_price'];
            $title = $r['title']; if ((float)$r['quantity'] > 1) $title .= ' x' . rtrim(rtrim(number_format((float)$r['quantity'], 2), '0'), '.');
            $pdf->SetFont('Arial', 'B', 10); $pdf->SetTextColor(26, 26, 26);
            $pdf->Cell($descW, 5, tx($title), 0, 0, 'L');
            $pdf->Cell($priceW, 5, tx($m($lt) . billing_suffix($r['billing'])), 0, 1, 'R');
            if (trim((string)$r['description']) !== '') {
                $pdf->SetFont('Arial', '', 8); $pdf->SetTextColor(...$GREY);
                $pdf->MultiCell($descW, 4, tx($r['description']), 0, 'L');
            }
            $pdf->Ln(1); $pdf->SetDrawColor(...$LINE); $pdf->SetLineWidth(0.2);
            $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + $descW + $priceW, $pdf->GetY()); $pdf->Ln(1.5);
        }
    };

    if ($one) { $band('ONE-TIME SERVICES'); $renderRows($one); }
    if ($mo)  { $pdf->Ln(2); $band(billing_band($recTotals)); $renderRows($mo); }

    // Subtotal / tax
    $pdf->Ln(2);
    $rowRight = function ($label, $val, $boldLabel = false) use ($pdf, $descW, $priceW, $GREY) {
        $pdf->SetFont('Arial', $boldLabel ? 'B' : '', 9); $pdf->SetTextColor(...$GREY);
        $pdf->Cell($descW, 5, tx($label), 0, 0, 'R');
        $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(26, 26, 26);
        $pdf->Cell($priceW, 5, tx($val), 0, 1, 'R');
    };
    if ($one || $rate > 0) $rowRight('Subtotal', $m($oneSub));
    if ($rate > 0) $rowRight($q['tax_label'] . ' (' . rtrim(rtrim(number_format($rate, 3), '0'), '.') . '%)', $m($tax));
    $pdf->Ln(2);

    // Total boxes
    $nBox = max(1, ($one || $rate > 0 ? 1 : 0) + count($recTotals));
    $boxW = min(52, $contentW / $nBox); $boxH = 12; $startX = $pageW - $R - $boxW * $nBox;
    $yBox = $pdf->GetY(); $x = $startX;
    $drawBox = function ($x, $y, $title, $val, $bg, $fg) use ($pdf, $boxW, $boxH) {
        $pdf->SetXY($x, $y); $pdf->SetFillColor(...$bg); $pdf->SetTextColor(...$fg);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->MultiCell($boxW, $boxH / 2, tx($title) . "\n" . tx($val), 0, 'C', true);
    };
    if ($one || $rate > 0) { $drawBox($x, $yBox, 'ONE-TIME TOTAL', $m($oneTotal), quote_tint(), quote_deep()); $x += $boxW; }
    foreach ($recTotals as $bk => $bt) { $drawBox($x, $yBox, billing_periods()[$bk][3], $m($bt) . billing_suffix($bk), quote_deep(), [255,255,255]); $x += $boxW; }
    $pdf->SetXY($L, $yBox + $boxH + 4);

    if ($optItems) {
        $band('OPTIONAL ADD-ONS');
        $pdf->SetFont('Arial', 'I', 8); $pdf->SetTextColor(...$GREY);
        $pdf->MultiCell(0, 4.5, tx('Not included in the totals above. Let us know if you would like to add any of these.'), 0, 'L');
        $pdf->Ln(1.5);
        $renderRows($optItems);
        $pdf->Ln(3);
    }

    // Notes
    if (trim((string)$q['notes']) !== '') {
        $pdf->SetFont('Arial', 'B', 8); $pdf->SetTextColor(...quote_deep()); $pdf->Cell(0, 5, tx('NOTES'), 0, 1);
        $pdf->SetFont('Arial', '', 8); $pdf->SetTextColor(51, 51, 51);
        $pdf->MultiCell(0, 4.5, tx($q['notes']), 0, 'L'); $pdf->Ln(2);
    }

    // Footer line (page 1)
    $pdf->SetFont('Arial', 'I', 8); $pdf->SetTextColor(...$GREY);
    $pdf->Ln(2);
    $pdf->MultiCell(0, 4.5, tx(client('quotes.thank_you') . "\nQuestions? Contact us at " . client('email.contact')), 0, 'C');

    // Terms & signatures
    if ((int)$q['terms_included'] === 1) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 12); $pdf->SetTextColor(...quote_deep()); $pdf->Cell(0, 8, tx('TERMS & CONDITIONS'), 0, 1); $pdf->Ln(1);
        $n = 1;
        foreach (quote_terms() as [$h, $body]) {
            $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(26, 26, 26);
            $pdf->MultiCell(0, 4.6, tx($n . '. ' . $h), 0, 'L');
            $pdf->SetFont('Arial', '', 8.5); $pdf->SetTextColor(60, 60, 60);
            $pdf->MultiCell(0, 4.3, tx($body), 0, 'L'); $pdf->Ln(1.5); $n++;
        }
        $pdf->Ln(3);
        $pdf->SetFont('Arial', 'B', 11); $pdf->SetTextColor(...quote_deep()); $pdf->Cell(0, 7, tx('SIGNATURES'), 0, 1);
        $pdf->SetFont('Arial', '', 9); $pdf->SetTextColor(26, 26, 26);
        $pdf->MultiCell(0, 5, tx('By signing below, both parties agree to the terms outlined in this Agreement.')); $pdf->Ln(4);
        $sig = function ($label) use ($pdf) {
            $pdf->SetFont('Arial', '', 9); $pdf->SetTextColor(26, 26, 26);
            $pdf->Cell(0, 6, tx($label . ':  ______________________________          Date: ____________'), 0, 1); $pdf->Ln(3);
        };
        $sig((string) client('name')); $sig('Client'); $sig('Printed Name'); $sig('Title');
    }

    return $pdf->Output('S');
}
