<?php
/** Build the quote PDF (FPDF). Name, colors, terms and wording come from the client file. */
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_billing.php';
require_once __DIR__ . '/_brand.php';
require_once __DIR__ . '/_quote_render.php';
require_once __DIR__ . '/../../lib/fpdf.php';

/** UTF-8 -> cp1252 for FPDF core fonts. */
function tx($s): string {
    $s = (string)$s;
    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    return $out === false ? $s : $out;
}

/** Quote colors as RGB for FPDF: deep (headings, dark total box) and tint (bands). */
function quote_deep(): array { return hex_rgb(brand_color('deep', '#0F3D2A')); }
function quote_tint(): array { return hex_rgb(brand_color('tint', '#E6F4EC')); }

/** PDF file name, e.g. Modern-Ops-Quote-1001.pdf */
function quote_file_name($number): string {
    $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string) client('quotes.file_prefix', 'Quote')) ?: 'Quote';
    return $prefix . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $number) . '.pdf';
}


/** FPDF with our page footer (business name, page X of Y). */
class QuotePDF extends FPDF {
    public string $footLeft = '';
    public function Footer(): void {
        $this->SetY(-12);
        $this->SetDrawColor(225, 225, 225); $this->SetLineWidth(0.2);
        $this->Line($this->lMargin, $this->GetY() - 1, $this->w - $this->rMargin, $this->GetY() - 1);
        $this->SetFont('Arial', '', 7.5); $this->SetTextColor(140, 140, 140);
        $this->Cell(0, 5, tx($this->footLeft), 0, 0, 'L');
        $this->Cell(0, 5, tx('Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'R');
    }
}

function build_quote_pdf(int $id): ?string {
    $loaded = quote_load($id);
    if (!$loaded) return null;
    [$q, $items] = $loaded;
    $url = quote_accept_url($id);              // creates the customer link the first time
    [$q, $items] = quote_load($id);            // pick up the token / acceptance fields
    return render_quote_pdf($q, $items, $url);
}

function render_quote_pdf(array $q, array $items, string $acceptUrl = ''): string {
    $n = quote_numbers($q, $items);
    $m = fn($v) => '$' . number_format((float)$v, 2);
    $DEEP = quote_deep(); $TINT = quote_tint(); $GREY = [119, 119, 119]; $TEXT = [26, 26, 26]; $LINE = [225, 225, 225];
    $L = 18; $R = 18; $pageW = 215.9; $W = $pageW - $L - $R; $priceW = 38; $descW = $W - $priceW;

    $pdf = new QuotePDF('P', 'mm', 'Letter');
    $pdf->footLeft = implode('  ·  ', array_filter([client('name'), client('domain'), client('email.contact')]));
    $pdf->AliasNbPages();
    $pdf->SetMargins($L, 16, $R);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    // ---- Header: logo (or name) left, QUOTE + number right
    $top = $pdf->GetY();
    $logo = quote_logo_path();
    if ($logo !== '') {
        $pdf->Image($logo, $L, $top, 0, 13);
    } else {
        $pdf->SetFont('Arial', 'B', 18); $pdf->SetTextColor(...$DEEP);
        $pdf->SetXY($L, $top + 2); $pdf->Cell($W / 2, 9, tx(mb_strtoupper((string) client('name'))), 0, 0);
    }
    $pdf->SetXY($L + $W / 2, $top);
    $pdf->SetFont('Arial', 'B', 22); $pdf->SetTextColor(...$DEEP);
    $pdf->Cell($W / 2, 9, tx('QUOTE'), 0, 2, 'R');
    $pdf->SetFont('Arial', '', 9); $pdf->SetTextColor(...$GREY);
    $pdf->Cell($W / 2, 5, tx('#' . $q['quote_number']), 0, 1, 'R');
    $y = $top + 16; $pdf->SetDrawColor(...$DEEP); $pdf->SetLineWidth(0.6); $pdf->Line($L, $y, $pageW - $R, $y);

    // ---- Prepared for / From / Quote details
    $y += 5; $colW = $W / 3;
    $colHead = function ($x, $label) use ($pdf, $y, $GREY) {
        $pdf->SetXY($x, $y); $pdf->SetFont('Arial', 'B', 7); $pdf->SetTextColor(...$GREY);
        $pdf->Cell(0, 4, tx($label), 0, 2);
    };
    $colLine = function ($x, $text, $bold = false, $grey = false) use ($pdf, $colW, $GREY, $TEXT) {
        if ($text === '' || $text === null) return;
        $pdf->SetX($x); $pdf->SetFont('Arial', $bold ? 'B' : '', 9.5); $pdf->SetTextColor(...($grey ? $GREY : $TEXT));
        $pdf->Cell($colW - 3, 4.8, tx($text), 0, 2);
    };
    $colHead($L, 'PREPARED FOR');
    if ($q['c_name'] !== '') { $colLine($L, $q['c_name'], true); $colLine($L, $q['c_business']); }
    else $colLine($L, $q['c_business'], true);
    $colLine($L, (string) $q['c_email'], false, true);
    $yEnd = $pdf->GetY();

    $x2 = $L + $colW;
    $colHead($x2, 'FROM');
    $colLine($x2, (string) client('name'), true); $colLine($x2, (string) client('email.contact'), false, true); $colLine($x2, (string) client('domain'), false, true);
    $yEnd = max($yEnd, $pdf->GetY());

    $x3 = $L + 2 * $colW;
    $colHead($x3, 'QUOTE DETAILS');
    $detail = function ($label, $val) use ($pdf, $x3, $GREY, $TEXT) {
        $pdf->SetX($x3); $pdf->SetFont('Arial', '', 9.5); $pdf->SetTextColor(...$GREY); $pdf->Cell(22, 4.8, tx($label), 0, 0);
        $pdf->SetFont('Arial', 'B', 9.5); $pdf->SetTextColor(...$TEXT); $pdf->Cell(0, 4.8, tx($val), 0, 2);
    };
    $detail('Quote #', $q['quote_number']);
    $detail('Date', date('M j, Y', strtotime($q['issue_date'])));
    if ($q['valid_until']) $detail('Valid until', date('M j, Y', strtotime($q['valid_until'])));
    $yEnd = max($yEnd, $pdf->GetY());
    $pdf->SetXY($L, $yEnd + 3);

    $band = function ($label) use ($pdf, $TINT, $DEEP) {
        if ($pdf->GetY() > 245) $pdf->AddPage();
        $pdf->Ln(3);
        $pdf->SetFillColor(...$TINT); $pdf->SetTextColor(...$DEEP); $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(0, 7, tx('  ' . $label), 0, 1, 'L', true); $pdf->Ln(1.5);
    };

    if (trim((string)($q['scope_of_work'] ?? '')) !== '') {
        $band('SCOPE OF WORK');
        $pdf->SetFont('Arial', '', 9.5); $pdf->SetTextColor(51, 51, 51);
        $pdf->SetX($L + 2); $pdf->MultiCell($W - 4, 4.8, tx($q['scope_of_work']), 0, 'L');
        $pdf->Ln(1);
    }

    $renderRows = function ($rows) use ($pdf, $descW, $priceW, $LINE, $GREY, $TEXT, $DEEP, $m, $L, $W) {
        foreach ($rows as $r) {
            if ($pdf->GetY() > 250) $pdf->AddPage();
            if (!empty($r['is_section'])) {
                $pdf->Ln(1.5); $pdf->SetX($L + 2); $pdf->SetFont('Arial', 'B', 10); $pdf->SetTextColor(...$DEEP);
                $pdf->Cell($W - 2, 6, tx($r['title']), 0, 1, 'L');
                $pdf->SetDrawColor(...$DEEP); $pdf->SetLineWidth(0.4);
                $pdf->Line($L, $pdf->GetY(), $L + $W, $pdf->GetY()); $pdf->Ln(1.5);
                continue;
            }
            $lt = (float)$r['quantity'] * (float)$r['unit_price'];
            $pdf->Ln(1);
            $pdf->SetX($L + 2); $pdf->SetFont('Arial', 'B', 10.5); $pdf->SetTextColor(...$TEXT);
            $pdf->Cell($descW - 2, 5.5, tx($r['title']), 0, 0, 'L');
            $pdf->Cell($priceW - 2, 5.5, tx($m($lt) . billing_suffix($r['billing'])), 0, 1, 'R');
            if (trim((string)$r['description']) !== '') {
                $pdf->SetX($L + 2); $pdf->SetFont('Arial', '', 8.5); $pdf->SetTextColor(102, 102, 102);
                $pdf->MultiCell($descW - 6, 4.3, tx($r['description']), 0, 'L');
            }
            if ((float)$r['quantity'] != 1.0) {
                $pdf->SetX($L + 2); $pdf->SetFont('Arial', '', 8.5); $pdf->SetTextColor(...$GREY);
                $pdf->Cell($descW - 2, 4.3, tx(qty_label($r['quantity']) . ' x ' . $m($r['unit_price'])), 0, 1, 'L');
            }
            $pdf->Ln(1.5); $pdf->SetDrawColor(...$LINE); $pdf->SetLineWidth(0.2);
            $pdf->Line($L, $pdf->GetY(), $L + $W, $pdf->GetY());
        }
    };

    if ($n['one']) { $band('ONE-TIME SERVICES'); $renderRows($n['one']); }
    if ($n['rec']) { $band(billing_band($n['recTotals'])); $renderRows($n['rec']); }

    // ---- Totals summary panel (right side)
    $hasOne = $n['one'] || $n['rate'] > 0;
    $lines = ($hasOne ? 1 + ($n['rate'] > 0 ? 1 : 0) : 0) + count($n['recTotals']) + ($n['recTotals'] ? 1 : 0);
    $panelW = 82; $px = $L + $W - $panelW;
    $panelH = 5 + $lines * 5.5 + ($hasOne ? 10 : 0);
    $pdf->Ln(5);
    if ($pdf->GetY() + $panelH > 255) $pdf->AddPage();
    $py = $pdf->GetY();
    $pdf->SetFillColor(...$TINT); $pdf->Rect($px, $py, $panelW, $panelH, 'F');
    $pdf->SetXY($px + 4, $py + 3);
    $row = function ($label, $val, $boldLabel = false) use ($pdf, $px, $panelW, $GREY, $TEXT) {
        $pdf->SetX($px + 4);
        $pdf->SetFont('Arial', $boldLabel ? 'B' : '', 9); $pdf->SetTextColor(...($boldLabel ? $TEXT : $GREY));
        $pdf->Cell($panelW - 36, 5.5, tx($label), 0, 0, 'L');
        $pdf->SetFont('Arial', 'B', 9.5); $pdf->SetTextColor(...$TEXT);
        $pdf->Cell(28, 5.5, tx($val), 0, 1, 'R');
    };
    if ($hasOne) {
        $row('Subtotal', $m($n['oneSub']));
        if ($n['rate'] > 0) $row($q['tax_label'] . ' (' . rtrim(rtrim(number_format($n['rate'], 3), '0'), '.') . '%)', $m($n['tax']));
        $pdf->Ln(1.5);
        $pdf->SetFillColor(...$DEEP); $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('Arial', 'B', 10.5);
        $pdf->SetX($px + 2);
        $pdf->Cell($panelW - 34, 8, tx('  Due on acceptance'), 0, 0, 'L', true);
        $pdf->Cell(30, 8, tx($m($n['oneTotal']) . '  '), 0, 1, 'R', true);
        $pdf->Ln(1.5);
    }
    if ($n['recTotals']) {
        $pdf->SetX($px + 4); $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetTextColor(...$DEEP);
        $pdf->Cell($panelW - 8, 5.5, tx($hasOne ? 'THEN' : 'RECURRING'), 0, 1, 'L');
        foreach ($n['recTotals'] as $k => $t) $row(ucfirst(billing_every($k)), $m($t) . billing_suffix($k));
    }
    $pdf->SetXY($L, $py + $panelH + 2);

    if ($n['opt']) {
        $band('OPTIONAL ADD-ONS');
        $pdf->SetX($L + 2); $pdf->SetFont('Arial', 'I', 8.5); $pdf->SetTextColor(...$GREY);
        $pdf->MultiCell($W - 4, 4.5, tx('Not included in the totals above. Let us know if you would like to add any of these.'), 0, 'L');
        $renderRows($n['opt']);
    }

    // ---- Next steps + payment
    $steps = array_values(array_filter((array) client('quotes.next_steps', [])));
    $pay = trim((string) client('quotes.payment', ''));
    if ($steps || $pay) {
        $band('NEXT STEPS');
        $pdf->SetFont('Arial', '', 9.5); $pdf->SetTextColor(...$TEXT);
        foreach ($steps as $i => $s) {
            $pdf->SetX($L + 2); $pdf->Cell(6, 5, tx(($i + 1) . '.'), 0, 0);
            $pdf->MultiCell($W - 10, 5, tx($s), 0, 'L');
        }
        if ($pay !== '') {
            $pdf->Ln(1); $pdf->SetLeftMargin($L + 2); $pdf->SetX($L + 2);   // wrapped lines line up with the first
            $pdf->SetFont('Arial', 'B', 9.5); $pdf->Write(5, tx('Payment: '));
            $pdf->SetFont('Arial', '', 9.5); $pdf->SetTextColor(68, 68, 68);
            $pdf->Write(5, tx($pay)); $pdf->Ln(6);
            $pdf->SetLeftMargin($L);
        }
    }

    // ---- Accept online (or the record of acceptance)
    if (!empty($q['accepted_at'])) {
        $pdf->Ln(3); $pdf->SetFillColor(...$TINT); $pdf->SetTextColor(...$DEEP); $pdf->SetFont('Arial', 'B', 9.5);
        $pdf->Cell(0, 8, tx('  Accepted online by ' . $q['accepted_name'] . ' on ' . date('M j, Y \a\t g:ia', strtotime($q['accepted_at']))), 0, 1, 'L', true);
    } elseif ($acceptUrl !== '') {
        $pdf->Ln(3);
        if ($pdf->GetY() > 245) $pdf->AddPage();
        $by = $pdf->GetY();
        $pdf->SetDrawColor(...$DEEP); $pdf->SetLineWidth(0.4); $pdf->Rect($L, $by, $W, 13);
        $pdf->SetXY($L + 4, $by + 2);
        $pdf->SetFont('Arial', 'B', 9.5); $pdf->SetTextColor(...$DEEP); $pdf->Cell(0, 4.5, tx('Ready to go ahead? Accept this quote online:'), 0, 2);
        $pdf->SetFont('Arial', 'U', 8.5); $pdf->Cell(0, 4.5, tx($acceptUrl), 0, 1, 'L', false, $acceptUrl);
        $pdf->SetXY($L, $by + 15);
    }

    if (trim((string)$q['notes']) !== '') {
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 8); $pdf->SetTextColor(...$DEEP); $pdf->Cell(0, 5, tx('NOTES'), 0, 1);
        $pdf->SetFont('Arial', '', 9); $pdf->SetTextColor(51, 51, 51);
        $pdf->MultiCell(0, 4.6, tx($q['notes']), 0, 'L');
    }

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'I', 8.5); $pdf->SetTextColor(...$GREY);
    $pdf->MultiCell(0, 4.5, tx(client('quotes.thank_you') . "\nQuestions? Contact us at " . client('email.contact')), 0, 'C');

    // ---- Terms & signatures
    if ((int)$q['terms_included'] === 1) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 13); $pdf->SetTextColor(...$DEEP); $pdf->Cell(0, 8, tx('TERMS & CONDITIONS'), 0, 1);
        $pdf->SetDrawColor(...$DEEP); $pdf->SetLineWidth(0.5); $pdf->Line($L, $pdf->GetY(), $L + $W, $pdf->GetY()); $pdf->Ln(3);
        $i = 1;
        foreach (quote_terms() as [$h, $body]) {
            $pdf->SetFont('Arial', 'B', 9.5); $pdf->SetTextColor(...$TEXT);
            $pdf->MultiCell(0, 4.8, tx($i . '. ' . $h), 0, 'L');
            $pdf->SetFont('Arial', '', 8.8); $pdf->SetTextColor(68, 68, 68);
            $pdf->MultiCell(0, 4.4, tx($body), 0, 'L'); $pdf->Ln(2); $i++;
        }
        if ($pdf->GetY() > 215) $pdf->AddPage();
        $pdf->Ln(3);
        $pdf->SetFont('Arial', 'B', 11); $pdf->SetTextColor(...$DEEP); $pdf->Cell(0, 7, tx('SIGNATURES'), 0, 1);
        if (!empty($q['accepted_at'])) {
            $pdf->SetFont('Arial', '', 9.5); $pdf->SetTextColor(...$TEXT);
            $pdf->MultiCell(0, 5, tx('Accepted online by ' . $q['accepted_name'] . ' on ' . date('M j, Y \a\t g:ia', strtotime($q['accepted_at'])) . ($q['accepted_ip'] ? ' (IP ' . $q['accepted_ip'] . ')' : '') . '. By accepting, the client agreed to these terms.'));
        } else {
            $pdf->SetFont('Arial', '', 9.5); $pdf->SetTextColor(...$TEXT);
            $pdf->MultiCell(0, 5, tx('By signing below, both parties agree to the terms outlined in this Agreement.')); $pdf->Ln(5);
            $sig = function ($label, $withDate = true) use ($pdf, $L, $GREY, $TEXT) {
                $y = $pdf->GetY() + 8;
                $pdf->SetDrawColor(150, 150, 150); $pdf->SetLineWidth(0.3);
                $pdf->Line($L, $y, $L + 95, $y); if ($withDate) $pdf->Line($L + 110, $y, $L + 160, $y);
                $pdf->SetXY($L, $y + 1); $pdf->SetFont('Arial', '', 8); $pdf->SetTextColor(...$GREY);
                $pdf->Cell(110, 4, tx($label), 0, 0); $pdf->Cell(50, 4, tx($withDate ? 'Date' : ''), 0, 1);
                $pdf->Ln(3);
            };
            $sig((string) client('name')); $sig('Client signature'); $sig('Printed name', false); $sig('Title', false);
        }
    }

    return $pdf->Output('S');
}
