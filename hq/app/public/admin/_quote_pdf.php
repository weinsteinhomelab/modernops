<?php
/** Build a Modern Ops quote PDF (FPDF) that mirrors the .docx template. */
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_billing.php';
require_once __DIR__ . '/../../lib/fpdf.php';

/** UTF-8 -> cp1252 for FPDF core fonts. */
function tx($s): string {
    $s = (string)$s;
    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    return $out === false ? $s : $out;
}

function quote_terms(): array {
    return [
        ['Client Approval & Acceptance', 'The Client is responsible for reviewing all deliverables and communicating any concerns in writing prior to final approval. Once the Client approves a deliverable in writing (including via email), that item is considered complete and accepted as-is. Modern Ops shall not be held liable for any dissatisfaction arising from approved work. Requests to redo approved work will be treated as new work and billed accordingly.'],
        ['Payment Terms', 'All one-time fees are due upon acceptance of the quote unless otherwise agreed in writing. Recurring fees are due on the 1st of each billing period (monthly, quarterly, semi-annually or annually, as listed on the quote). Invoices not paid within 15 days of the due date may result in suspension of services. All fees paid are non-refundable once work has commenced. Modern Ops accepts payment via check, bank transfer, or any agreed-upon payment platform.'],
        ['Website Ownership & Intellectual Property', 'Upon receipt of full payment, the Client owns the website content and design created specifically for them. Third-party tools, themes, plugins, and software remain subject to their respective licenses. The Client represents that they own or have rights to all content they provide. Modern Ops is not liable for any intellectual property claims arising from Client-provided content. Modern Ops retains the right to display completed work in its portfolio unless the Client requests otherwise in writing.'],
        ['Hosting, Domain & Third-Party Services', 'Hosting and domain fees are billed annually and are non-refundable once paid. Modern Ops is not liable for outages, data loss, or service interruptions caused by third-party hosting providers, domain registrars, or any other external services. The Client is responsible for maintaining their domain registration. Modern Ops does not guarantee uninterrupted availability of any website.'],
        ['Monthly Maintenance & Support', 'Monthly maintenance covers only the tasks explicitly described in the quote. Modern Ops is not liable for security breaches, data loss, or site issues caused by third-party plugins, external attacks, or Client actions. Monthly services may be cancelled with 30 days written notice. No refunds are issued for partial months.'],
        ['Limitation of Liability', 'To the maximum extent permitted by law, Modern Ops shall not be liable for any indirect, incidental, special, or consequential damages, including loss of revenue, data, business, or reputation, arising out of or related to the services provided. Modern Ops total liability for any claim shall not exceed the total amount paid by the Client for the specific service giving rise to the claim.'],
        ['Dispute Resolution', 'Both parties agree to first attempt resolution through good-faith written communication. If a resolution cannot be reached within 30 days, both parties agree to pursue mediation before initiating any legal action. This Agreement shall be governed by the laws of the Commonwealth of Pennsylvania. Any legal proceedings shall take place in Delaware County, Pennsylvania.'],
        ['No Warranty', 'Modern Ops provides all services on an as-is basis and makes no warranties, express or implied, regarding fitness for a particular purpose, search engine rankings, business results, or any specific outcomes.'],
        ['Confidentiality', 'Both parties agree to keep confidential any proprietary or sensitive information shared during the engagement and not to disclose it to third parties without prior written consent.'],
        ['Entire Agreement', 'This Agreement, together with the accompanying quote, constitutes the entire agreement between the parties and supersedes all prior discussions. Any modifications must be made in writing and signed by both parties.'],
    ];
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
    $one = array_values(array_filter($items, fn($i) => billing_clean($i['billing']) === 'one_time'));
    $mo  = array_values(array_filter($items, fn($i) => billing_clean($i['billing']) !== 'one_time'));
    $recTotals = billing_recurring_totals($items);
    $sum = function ($rows) { $t = 0; foreach ($rows as $r) $t += (float)$r['quantity'] * (float)$r['unit_price']; return $t; };
    $oneSub = $sum($one); $moSub = $sum($mo);
    $rate = (float)$q['tax_rate']; $tax = $oneSub * $rate / 100; $oneTotal = $oneSub + $tax;
    $m = fn($n) => '$' . number_format((float)$n, 2);

    $GREEN = [15, 61, 42]; $BAND = [230, 244, 236]; $GREY = [119, 119, 119]; $LINE = [221, 221, 221];
    $L = 18; $R = 18; $pageW = 215.9; $contentW = $pageW - $L - $R; $priceW = 34; $descW = $contentW - $priceW;

    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->SetMargins($L, 16, $R);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AddPage();

    // Header
    $pdf->SetFont('Arial', 'B', 18); $pdf->SetTextColor(...$GREEN);
    $pdf->Cell(0, 9, tx('MODERN OPS'), 0, 1);
    $pdf->SetFont('Arial', '', 8); $pdf->SetTextColor(...$GREY);
    $pdf->Cell(0, 5, tx('Web & business operations for small businesses   |   modern-ops.net   |   Delaware Valley, PA'), 0, 1);
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
                $pdf->Ln(1); $pdf->SetFont('Arial', 'B', 9.5); $pdf->SetTextColor(15, 61, 42);
                $pdf->Cell($descW + $priceW, 5.5, tx($r['title']), 0, 1, 'L');
                $pdf->SetDrawColor(15, 61, 42); $pdf->SetLineWidth(0.4);
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
    if ($one || $rate > 0) { $drawBox($x, $yBox, 'ONE-TIME TOTAL', $m($oneTotal), [230,244,236], [15,61,42]); $x += $boxW; }
    foreach ($recTotals as $bk => $bt) { $drawBox($x, $yBox, billing_periods()[$bk][3], $m($bt) . billing_suffix($bk), [15,61,42], [255,255,255]); $x += $boxW; }
    $pdf->SetXY($L, $yBox + $boxH + 4);

    // Notes
    if (trim((string)$q['notes']) !== '') {
        $pdf->SetFont('Arial', 'B', 8); $pdf->SetTextColor(15, 61, 42); $pdf->Cell(0, 5, tx('NOTES'), 0, 1);
        $pdf->SetFont('Arial', '', 8); $pdf->SetTextColor(51, 51, 51);
        $pdf->MultiCell(0, 4.5, tx($q['notes']), 0, 'L'); $pdf->Ln(2);
    }

    // Footer line (page 1)
    $pdf->SetFont('Arial', 'I', 8); $pdf->SetTextColor(...$GREY);
    $pdf->Ln(2);
    $pdf->MultiCell(0, 4.5, tx("Thank you for choosing Modern Ops. We look forward to working with you.\nQuestions? Contact us at info@modern-ops.net"), 0, 'C');

    // Terms & signatures
    if ((int)$q['terms_included'] === 1) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 12); $pdf->SetTextColor(15, 61, 42); $pdf->Cell(0, 8, tx('TERMS & CONDITIONS'), 0, 1); $pdf->Ln(1);
        $n = 1;
        foreach (quote_terms() as [$h, $body]) {
            $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(26, 26, 26);
            $pdf->MultiCell(0, 4.6, tx($n . '. ' . $h), 0, 'L');
            $pdf->SetFont('Arial', '', 8.5); $pdf->SetTextColor(60, 60, 60);
            $pdf->MultiCell(0, 4.3, tx($body), 0, 'L'); $pdf->Ln(1.5); $n++;
        }
        $pdf->Ln(3);
        $pdf->SetFont('Arial', 'B', 11); $pdf->SetTextColor(15, 61, 42); $pdf->Cell(0, 7, tx('SIGNATURES'), 0, 1);
        $pdf->SetFont('Arial', '', 9); $pdf->SetTextColor(26, 26, 26);
        $pdf->MultiCell(0, 5, tx('By signing below, both parties agree to the terms outlined in this Agreement.')); $pdf->Ln(4);
        $sig = function ($label) use ($pdf) {
            $pdf->SetFont('Arial', '', 9); $pdf->SetTextColor(26, 26, 26);
            $pdf->Cell(0, 6, tx($label . ':  ______________________________          Date: ____________'), 0, 1); $pdf->Ln(3);
        };
        $sig('Modern Ops'); $sig('Client'); $sig('Printed Name'); $sig('Title');
    }

    return $pdf->Output('S');
}
