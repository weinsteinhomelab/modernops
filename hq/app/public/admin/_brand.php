<?php
/** Brand helpers: turn the client config into names, colors and the favicon. */
require_once __DIR__ . '/config.php';

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

/** Favicon: the Ops Essentials mark, a green tile with "Ops" in black and "E" in white. */
function brand_favicon(): string {
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0' stop-color='#2fb383'/><stop offset='1' stop-color='#0f6e52'/></linearGradient></defs>"
         . "<rect width='32' height='32' rx='7' fill='url(#g)'/>"
         . "<text x='16' y='20.5' font-family='Arial,Helvetica,sans-serif' font-size='11.5' font-weight='800' text-anchor='middle' letter-spacing='-.4'>"
         . "<tspan fill='#000000'>Ops</tspan><tspan fill='#ffffff'>E</tspan></text></svg>";
    return 'data:image/svg+xml,' . rawurlencode($svg);
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
