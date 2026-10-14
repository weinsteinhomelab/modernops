<?php
/**
 * Quote data + the on-screen quote ("paper") shared by the HQ preview and the
 * public accept page. The PDF in _quote_pdf.php follows the same layout.
 */
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_billing.php';
require_once __DIR__ . '/_brand.php';

/** A quote with its business + contact, and its lines in order. */
function quote_load(int $id): ?array {
    $st = db()->prepare(
        'SELECT q.*, b.name AS biz_name, b.main_email AS biz_email,
                ct.first_name, ct.last_name, ct.email AS contact_email
         FROM quotes q
         JOIN businesses b ON b.id = q.business_id
         LEFT JOIN contacts ct ON ct.id = q.contact_id
         WHERE q.id = ?');
    $st->execute([$id]);
    $q = $st->fetch();
    if (!$q) return null;
    $q['c_business'] = $q['biz_name'];
    $q['c_name'] = trim(($q['first_name'] ?? '') . ' ' . ($q['last_name'] ?? ''));
    $q['c_email'] = $q['contact_email'] ?: $q['biz_email'];
    $it = db()->prepare('SELECT * FROM quote_items WHERE quote_id=? ORDER BY sort_order, id');
    $it->execute([$id]);
    return [$q, $it->fetchAll()];
}

function quote_id_for_token(string $token): int {
    if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) return 0;
    $st = db()->prepare('SELECT id FROM quotes WHERE public_token = ?');
    $st->execute([$token]);
    return (int) $st->fetchColumn();
}

/** The customer link for a quote ('' if online acceptance is off). Creates the token on first use. */
function quote_accept_url(int $id): string {
    $page = trim((string) client('quotes.accept_page', ''));
    if ($page === '') return '';
    $st = db()->prepare('SELECT public_token FROM quotes WHERE id = ?');
    $st->execute([$id]);
    $token = (string) $st->fetchColumn();
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        db()->prepare('UPDATE quotes SET public_token = ? WHERE id = ? AND public_token IS NULL')->execute([$token, $id]);
        $st->execute([$id]);
        $token = (string) $st->fetchColumn();
    }
    return $page . (strpos($page, '?') === false ? '?' : '&') . 't=' . $token;
}

/** Split lines and work out totals. Optional add-ons never count. */
function quote_numbers(array $q, array $items): array {
    $opt  = array_values(array_filter($items, fn($i) => !empty($i['is_optional'])));
    $main = array_values(array_filter($items, fn($i) => empty($i['is_optional'])));
    $one  = array_values(array_filter($main, fn($i) => billing_clean($i['billing']) === 'one_time'));
    $rec  = array_values(array_filter($main, fn($i) => billing_clean($i['billing']) !== 'one_time'));
    $oneSub = 0;
    foreach ($one as $r) $oneSub += (float)$r['quantity'] * (float)$r['unit_price'];
    $rate = (float) $q['tax_rate'];
    $tax = $oneSub * $rate / 100;
    return [
        'one' => $one, 'rec' => $rec, 'opt' => $opt,
        'recTotals' => billing_recurring_totals($main),
        'oneSub' => $oneSub, 'rate' => $rate, 'tax' => $tax, 'oneTotal' => $oneSub + $tax,
    ];
}

/** Terms & Conditions: [heading, text] pairs from the client file. */
function quote_terms(): array {
    return (array) client('quotes.terms', []);
}

/** "every quarter" style wording for the totals summary. */
function billing_every(string $b): string {
    return ['monthly' => 'per month', 'quarterly' => 'every quarter', 'semiannual' => 'every 6 months', 'annual' => 'per year'][billing_clean($b)] ?? '';
}

/** Absolute path of the client's quote logo, or '' if none. */
function quote_logo_path(): string {
    $f = trim((string) client('logo_file', ''));
    if ($f === '') return '';
    $p = __DIR__ . '/../../' . ltrim($f, '/');
    return is_file($p) ? $p : '';
}

/** The logo as a data: URI so the quote works on any site without loading files. */
function quote_logo_data_uri(): string {
    $p = quote_logo_path();
    if ($p === '') return '';
    $type = preg_match('/\.png$/i', $p) ? 'image/png' : 'image/jpeg';
    return 'data:' . $type . ';base64,' . base64_encode((string) file_get_contents($p));
}

function qty_label($q): string {
    return rtrim(rtrim(number_format((float)$q, 2), '0'), '.');
}

/**
 * The quote as the customer sees it. Self-contained HTML with inline styles
 * (it is also shown on the client's website). Never includes cost or margin.
 */
function quote_paper_html(array $q, array $items, string $acceptUrl = ''): string {
    $n = quote_numbers($q, $items);
    $deep = e(brand_color('deep', '#0F3D2A'));
    $tint = e(brand_color('tint', '#E6F4EC'));
    $date = fn($d) => $d ? date('M j, Y', strtotime($d)) : '';
    $band = fn($t) => '<div style="background:' . $tint . ';color:' . $deep . ';font-weight:700;font-size:11px;letter-spacing:.08em;padding:7px 10px;margin:22px 0 4px;border-radius:4px">' . e($t) . '</div>';

    $rows = function (array $rows) use ($deep) {
        $h = '<table style="width:100%;border-collapse:collapse;font-size:13px">';
        foreach ($rows as $r) {
            if (!empty($r['is_section'])) {
                $h .= '<tr><td colspan="2" style="padding:14px 10px 5px;font-weight:700;color:' . $deep . ';font-size:13px;border-bottom:1.5px solid ' . $deep . '">' . e($r['title']) . '</td></tr>';
                continue;
            }
            $lt = (float)$r['quantity'] * (float)$r['unit_price'];
            $qty = (float)$r['quantity'] != 1.0 ? '<div style="color:#777;font-size:11.5px;margin-top:2px">' . qty_label($r['quantity']) . ' × ' . money($r['unit_price']) . '</div>' : '';
            $h .= '<tr><td style="padding:10px 10px;border-bottom:1px solid #e5e5e5;vertical-align:top">'
                . '<div style="font-weight:700;font-size:14px">' . e($r['title']) . '</div>'
                . ($r['description'] !== null && trim((string)$r['description']) !== '' ? '<div style="color:#666;font-size:12px;margin-top:2px">' . e($r['description']) . '</div>' : '')
                . $qty . '</td>'
                . '<td style="padding:10px 10px;border-bottom:1px solid #e5e5e5;text-align:right;font-weight:700;white-space:nowrap;vertical-align:top;font-size:14px">' . money($lt) . e(billing_suffix($r['billing'])) . '</td></tr>';
        }
        return $h . '</table>';
    };

    $logo = quote_logo_data_uri();
    $h  = '<div style="background:#fff;color:#1a1a1a;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.45">';

    // Header: logo or name on the left, QUOTE + number on the right
    $h .= '<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;padding-bottom:14px;border-bottom:2px solid ' . $deep . '">';
    $h .= $logo !== ''
        ? '<img src="' . $logo . '" alt="' . e(client('name')) . '" style="height:52px;width:auto;display:block">'
        : '<div style="font-weight:800;letter-spacing:.12em;color:' . $deep . ';font-size:22px">' . e(mb_strtoupper((string) client('name'))) . '</div>';
    $h .= '<div style="text-align:right"><div style="font-size:26px;font-weight:800;letter-spacing:.06em;color:' . $deep . ';line-height:1">QUOTE</div>'
        . '<div style="color:#777;font-size:12px;margin-top:4px">#' . e($q['quote_number']) . '</div></div></div>';

    // Prepared for / From / Details
    $col = fn($label, $body) => '<div style="flex:1;min-width:170px"><div style="font-size:10.5px;font-weight:700;letter-spacing:.08em;color:#888;margin-bottom:4px">' . $label . '</div>' . $body . '</div>';
    $for  = ($q['c_name'] !== '' ? '<div style="font-weight:700">' . e($q['c_name']) . '</div>' : '')
          . '<div' . ($q['c_name'] === '' ? ' style="font-weight:700"' : '') . '>' . e($q['c_business']) . '</div>'
          . ($q['c_email'] ? '<div style="color:#666">' . e($q['c_email']) . '</div>' : '');
    $from = '<div style="font-weight:700">' . e(client('name')) . '</div><div style="color:#666">' . e(client('email.contact')) . '</div><div style="color:#666">' . e(client('domain')) . '</div>';
    $det  = '<table style="border-collapse:collapse;font-size:13px;width:auto">'
          . '<tr><td style="color:#666;padding:0 12px 1px 0;border:none;vertical-align:top">Quote #</td><td style="font-weight:700;padding:0 0 1px;border:none">' . e($q['quote_number']) . '</td></tr>'
          . '<tr><td style="color:#666;padding:0 12px 1px 0;border:none;vertical-align:top">Date</td><td style="padding:0 0 1px;border:none">' . e($date($q['issue_date'])) . '</td></tr>'
          . ($q['valid_until'] ? '<tr><td style="color:#666;padding:0 12px 1px 0;border:none;vertical-align:top">Valid until</td><td style="padding:0 0 1px;border:none">' . e($date($q['valid_until'])) . '</td></tr>' : '')
          . '</table>';
    $h .= '<div style="display:flex;gap:20px;flex-wrap:wrap;margin:18px 0 6px">' . $col('PREPARED FOR', $for) . $col('FROM', $from) . $col('QUOTE DETAILS', $det) . '</div>';

    if (trim((string)$q['scope_of_work']) !== '') {
        $h .= $band('SCOPE OF WORK') . '<div style="color:#333;padding:6px 10px;white-space:pre-wrap">' . e($q['scope_of_work']) . '</div>';
    }
    if ($n['one']) $h .= $band('ONE-TIME SERVICES') . $rows($n['one']);
    if ($n['rec']) $h .= $band(billing_band($n['recTotals'])) . $rows($n['rec']);

    // Totals summary
    $h .= '<div style="display:flex;justify-content:flex-end;margin-top:16px"><div style="min-width:280px;max-width:100%;background:' . $tint . ';border-radius:6px;padding:12px 14px">';
    $line = fn($l, $v, $style = '') => '<div style="display:flex;justify-content:space-between;gap:24px;padding:2px 0;' . $style . '"><span>' . $l . '</span><span style="font-weight:700;white-space:nowrap">' . $v . '</span></div>';
    if ($n['one'] || $n['rate'] > 0) {
        $h .= $line('<span style="color:#555">Subtotal</span>', money($n['oneSub']));
        if ($n['rate'] > 0) $h .= $line('<span style="color:#555">' . e($q['tax_label']) . ' (' . rtrim(rtrim(number_format($n['rate'], 3), '0'), '.') . '%)</span>', money($n['tax']));
        $h .= '<div style="display:flex;justify-content:space-between;gap:24px;background:' . $deep . ';color:#fff;border-radius:4px;padding:8px 10px;margin:8px -4px 0;font-weight:700"><span>Due on acceptance</span><span>' . money($n['oneTotal']) . '</span></div>';
    }
    if ($n['recTotals']) {
        $h .= '<div style="margin-top:' . ($n['one'] ? '10' : '0') . 'px;font-size:11px;font-weight:700;letter-spacing:.06em;color:' . $deep . '">' . ($n['one'] ? 'THEN' : 'RECURRING') . '</div>';
        foreach ($n['recTotals'] as $k => $t) $h .= $line(e(ucfirst(billing_every($k))), money($t) . e(billing_suffix($k)));
    }
    $h .= '</div></div>';

    if ($n['opt']) {
        $h .= $band('OPTIONAL ADD-ONS') . '<div style="color:#777;font-style:italic;font-size:12px;padding:2px 10px 0">Not included in the totals above. Let us know if you would like to add any of these.</div>' . $rows($n['opt']);
    }

    // Next steps + payment
    $steps = array_values(array_filter((array) client('quotes.next_steps', [])));
    $pay = trim((string) client('quotes.payment', ''));
    if ($steps || $pay) {
        $h .= $band('NEXT STEPS') . '<div style="padding:4px 10px">';
        if ($steps) {
            $h .= '<ol style="margin:0 0 0 18px;padding:0">';
            foreach ($steps as $s) $h .= '<li style="margin:3px 0">' . e($s) . '</li>';
            $h .= '</ol>';
        }
        if ($pay) $h .= '<div style="margin-top:8px;color:#444"><strong>Payment:</strong> ' . e($pay) . '</div>';
        $h .= '</div>';
    }
    if ($acceptUrl !== '' && empty($q['accepted_at'])) {
        $h .= '<div style="margin-top:14px;border:1.5px solid ' . $deep . ';border-radius:6px;padding:10px 12px"><strong style="color:' . $deep . '">Ready to go ahead?</strong> Accept this quote online: <a href="' . e($acceptUrl) . '" style="color:' . $deep . ';word-break:break-all">' . e($acceptUrl) . '</a></div>';
    }
    if (!empty($q['accepted_at'])) {
        $h .= '<div style="margin-top:14px;background:' . $tint . ';border-radius:6px;padding:10px 12px;color:' . $deep . '"><strong>Accepted online</strong> by ' . e($q['accepted_name']) . ' on ' . e(date('M j, Y \a\t g:ia', strtotime($q['accepted_at']))) . '</div>';
    }

    if (trim((string)$q['notes']) !== '') {
        $h .= '<div style="margin-top:20px"><div style="color:' . $deep . ';font-weight:700;font-size:11px;letter-spacing:.08em;margin-bottom:4px">NOTES</div><div style="white-space:pre-wrap;color:#333">' . e($q['notes']) . '</div></div>';
    }
    if ($q['terms_included']) {
        $h .= '<div style="margin-top:10px;font-size:12px;color:#777">Terms &amp; Conditions are part of this quote.</div>';
    }
    $h .= '<div style="text-align:center;color:#777;font-style:italic;font-size:12px;margin-top:22px;border-top:1px solid #eee;padding-top:14px">'
        . e(client('quotes.thank_you')) . '<br>Questions? Contact us at ' . e(client('email.contact')) . '</div>';
    return $h . '</div>';
}
