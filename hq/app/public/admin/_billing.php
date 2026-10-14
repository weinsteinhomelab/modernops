<?php
/** Billing periods for quote lines and catalog items. */

/** key => [label, price suffix, months per period (0 = one-time), total-box title] */
function billing_periods(): array {
    return [
        'one_time'   => ['One-time',      '',      0,  'ONE-TIME TOTAL'],
        'monthly'    => ['Monthly',       '/mo',   1,  'MONTHLY TOTAL'],
        'quarterly'  => ['Quarterly',     '/qtr',  3,  'QUARTERLY TOTAL'],
        'semiannual' => ['Semi-annually', '/6 mo', 6,  'SEMI-ANNUAL TOTAL'],
        'annual'     => ['Annually',      '/yr',   12, 'ANNUAL TOTAL'],
    ];
}

function billing_clean($b): string {
    return array_key_exists((string)$b, billing_periods()) ? (string)$b : 'one_time';
}
function billing_label($b): string  { return billing_periods()[billing_clean($b)][0]; }
function billing_suffix($b): string { return billing_periods()[billing_clean($b)][1]; }

function billing_options(string $current): string {
    $h = '';
    foreach (billing_periods() as $k => $p) {
        $h .= '<option value="' . $k . '"' . ($k === billing_clean($current) ? ' selected' : '') . '>' . $p[0] . '</option>';
    }
    return $h;
}

/** Recurring line totals grouped by period, in period order: ['monthly' => 70.0, ...]. */
function billing_recurring_totals(array $items): array {
    $out = [];
    foreach (array_keys(billing_periods()) as $k) {
        if ($k === 'one_time') continue;
        $t = 0; $any = false;
        foreach ($items as $r) if (empty($r['is_section']) && billing_clean($r['billing']) === $k) { $t += (float)$r['quantity'] * (float)$r['unit_price']; $any = true; }
        if ($any) $out[$k] = $t;
    }
    return $out;
}

/** Heading for the recurring section of a quote. */
function billing_band(array $recTotals): string {
    if (count($recTotals) !== 1) return 'RECURRING SERVICES';
    return str_replace(' TOTAL', '', billing_periods()[array_key_first($recTotals)][3]) . ' RECURRING';
}
