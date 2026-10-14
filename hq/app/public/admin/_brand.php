<?php
/** Brand helpers: turn the client config into names, colors and the favicon. */
require_once __DIR__ . '/config.php';

/** "Modern Ops HQ" — client name plus the portal word. */
function brand_title(): string {
    return trim(client('name') . ' ' . client('portal', ''));
}

/** '#1a6b4a' -> [26, 107, 74] (for FPDF). */
function hex_rgb(string $hex): array {
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

/** A safe color value from the config (falls back if it isn't a hex color). */
function brand_color(string $key, string $fallback): string {
    $v = (string) client('colors.' . $key, $fallback);
    return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $v) ? $v : $fallback;
}

/** Favicon: rounded square in the accent color, monogram in serif (first letter dark, rest white). */
function brand_favicon(): string {
    $mono = (string) client('monogram', 'HQ');
    $first = htmlspecialchars(mb_substr($mono, 0, 1), ENT_XML1);
    $rest  = htmlspecialchars(mb_substr($mono, 1), ENT_XML1);
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='" . brand_color('accent', '#1a6b4a') . "'/>"
         . "<text x='16' y='21.5' font-family='Georgia,serif' font-size='16' font-weight='700' text-anchor='middle'>"
         . "<tspan fill='#1a1a1a'>$first</tspan><tspan fill='#ffffff'>$rest</tspan></text></svg>";
    return 'data:image/svg+xml,' . rawurlencode($svg);
}

/** Logo mark for the login page: the client's image if set, else the monogram tile. */
function brand_mark_html(): string {
    $logo = (string) client('logo', '');
    if ($logo !== '') return '<img src="' . e($logo) . '" alt="' . e(client('name')) . '" style="max-height:56px;max-width:220px;display:block;margin:0 auto 1.1rem">';
    $mono = (string) client('monogram', 'HQ');
    return '<div class="logo"><span class="m">' . e(mb_substr($mono, 0, 1)) . '</span><span class="o">' . e(mb_substr($mono, 1)) . '</span></div>';
}

/** Ops Essentials product names. Use these exact names in labels, docs and the pricebook. */
function product_name(string $p): string {
    return ['customers' => 'Ops Customers', 'quotes' => 'Ops Quotes', 'inbox' => 'Ops Inbox'][$p] ?? 'Ops Essentials';
}

/** Stop here with a friendly page if the client doesn't have this product. */
function require_product(string ...$any): void {
    foreach ($any as $p) if (product_on($p)) return;
    http_response_code(404);
    layout_header('Not included', 'index.php');
    echo '<h1 class="page">Not included</h1><div class="card">' . e(product_name($any[0])) . ' isn\'t part of this account. <a href="index.php">Back to the dashboard</a></div>';
    layout_footer();
    exit;
}

/** Mailboxes shown in Ops Inbox (from email.accounts). */
function imap_accounts_list(): array {
    return array_values(array_filter(array_map('trim', (array) client('email.accounts', []))));
}
