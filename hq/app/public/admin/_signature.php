<?php
/**
 * Email signatures: one per person, built on signature.php, pasted into Gmail /
 * Outlook / Apple Mail, and added to messages sent from Ops Inbox.
 *
 * The HTML is email-safe on purpose: tables, inline styles, web-safe fonts, PNG/JPG
 * images with fixed sizes. Images are served by /api/sig_image.php so they load
 * for whoever receives the email (the rest of HQ is behind a login).
 */
require_once __DIR__ . '/_db.php';

const SIG_FONTS = [
    'Arial'     => 'Arial, Helvetica, sans-serif',
    'Helvetica' => 'Helvetica, Arial, sans-serif',
    'Georgia'   => 'Georgia, \'Times New Roman\', serif',
    'Verdana'   => 'Verdana, Geneva, sans-serif',
    'Trebuchet' => '\'Trebuchet MS\', Arial, sans-serif',
    'Tahoma'    => 'Tahoma, Verdana, sans-serif',
];
const SIG_LAYOUTS = [
    'side'    => 'Side by side',
    'stacked' => 'Stacked',
    'compact' => 'Compact',
];
const SIG_SOCIALS = [
    'linkedin'  => 'LinkedIn',
    'facebook'  => 'Facebook',
    'instagram' => 'Instagram',
    'x'         => 'X',
    'youtube'   => 'YouTube',
    'google'    => 'Google reviews',
];
const SIG_IMAGE_MAX = 1572864;   // 1.5 MB

/** Tables live here (not in the numbered migrations) so the page works on any schema version. */
function sig_ensure(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS email_signatures (
        user_id INT PRIMARY KEY,
        data TEXT NOT NULL,
        add_to_inbox TINYINT NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS sig_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        k CHAR(32) NOT NULL UNIQUE,
        user_id INT NOT NULL,
        mime VARCHAR(40) NOT NULL,
        width INT NOT NULL DEFAULT 0,
        height INT NOT NULL DEFAULT 0,
        bytes MEDIUMBLOB NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Where people outside HQ can reach it (for image links inside emails). */
function sig_public_base(): string {
    $u = rtrim(envv('HQ_PUBLIC_URL', (string) client('public_url', '')), '/');
    if ($u !== '') return $u;
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** The company logo from the client file (logo_file), if it is a PNG/JPG/GIF. */
function sig_company_logo(): ?array {
    $f = (string) client('logo_file', '');
    if ($f === '') return null;
    $path = __DIR__ . '/../../' . ltrim($f, '/');
    if (!is_file($path)) return null;
    $info = @getimagesize($path);
    if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/gif'], true)) return null;
    return ['path' => $path, 'mime' => $info['mime'], 'width' => $info[0], 'height' => $info[1]];
}

function sig_image(string $k): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $k)) return null;
    sig_ensure();
    $st = db()->prepare('SELECT k, user_id, mime, width, height FROM sig_images WHERE k = ?');
    $st->execute([$k]);
    return $st->fetch() ?: null;
}

/** Store an uploaded image; returns its key or an error message. */
function sig_store_upload(array $file, int $uid): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['err' => 'The upload didn\'t come through. Try again.'];
    if ($file['size'] > SIG_IMAGE_MAX) return ['err' => 'That image is over 1.5 MB. Use a smaller one (a few hundred pixels wide is plenty).'];
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/gif'], true)) {
        return ['err' => 'Use a PNG, JPG or GIF. Email apps don\'t show SVG or WebP images.'];
    }
    sig_ensure();
    $k = bin2hex(random_bytes(16));
    db()->prepare('INSERT INTO sig_images (k, user_id, mime, width, height, bytes) VALUES (?,?,?,?,?,?)')
        ->execute([$k, $uid, $info['mime'], $info[0], $info[1], file_get_contents($file['tmp_name'])]);
    return ['k' => $k, 'width' => $info[0], 'height' => $info[1]];
}

function sig_defaults(array $user): array {
    return [
        'name'       => trim((string)($user['name'] ?? '')) ?: (string)($user['username'] ?? ''),
        'title'      => '',
        'company'    => (string) client('name', ''),
        'email'      => (string)($user['email'] ?? ''),
        'phone'      => '',
        'mobile'     => '',
        'website'    => (string) client('domain', ''),
        'address'    => '',
        'tagline'    => '',
        'cta_text'   => '',
        'cta_url'    => '',
        'disclaimer' => '',
        'layout'     => 'side',
        'font'       => 'Arial',
        'color'      => (string) client('colors.accent', '#1a6b4a'),
        'logo'       => 'company',   // company | custom | none
        'logo_key'   => '',
        'logo_width' => 140,
        'photo_key'  => '',
        'social'     => array_fill_keys(array_keys(SIG_SOCIALS), ''),
    ];
}

/** Clean form input (or stored JSON) into a signature array. */
function sig_clean(array $in, array $user): array {
    $d = sig_defaults($user);
    foreach (['name', 'title', 'company', 'email', 'phone', 'mobile', 'website', 'address', 'tagline', 'cta_text', 'cta_url'] as $f) {
        if (array_key_exists($f, $in)) $d[$f] = mb_substr(trim((string) $in[$f]), 0, 200);
    }
    if (array_key_exists('disclaimer', $in)) $d['disclaimer'] = mb_substr(trim((string) $in['disclaimer']), 0, 1000);
    if (isset($in['layout']) && isset(SIG_LAYOUTS[$in['layout']])) $d['layout'] = $in['layout'];
    if (isset($in['font']) && isset(SIG_FONTS[$in['font']])) $d['font'] = $in['font'];
    if (isset($in['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $in['color'])) $d['color'] = strtolower($in['color']);
    if (isset($in['logo']) && in_array($in['logo'], ['company', 'custom', 'none'], true)) $d['logo'] = $in['logo'];
    foreach (['logo_key', 'photo_key'] as $f) {
        if (isset($in[$f])) $d[$f] = preg_match('/^[a-f0-9]{32}$/', (string) $in[$f]) ? (string) $in[$f] : '';
    }
    if (isset($in['logo_width'])) $d['logo_width'] = max(60, min(260, (int) $in['logo_width']));
    $soc = (array)($in['social'] ?? []);
    foreach (SIG_SOCIALS as $k => $_) $d['social'][$k] = mb_substr(trim((string)($soc[$k] ?? $d['social'][$k])), 0, 300);
    if ($d['logo'] === 'custom' && $d['logo_key'] === '') $d['logo'] = 'company';
    return $d;
}

function sig_load(int $uid, array $user): ?array {
    sig_ensure();
    $st = db()->prepare('SELECT data, add_to_inbox FROM email_signatures WHERE user_id = ?');
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row) return null;
    $d = sig_clean((array) json_decode((string) $row['data'], true), $user);
    $d['add_to_inbox'] = (int) $row['add_to_inbox'];
    return $d;
}

function sig_save(int $uid, array $d, bool $addToInbox): void {
    sig_ensure();
    unset($d['add_to_inbox']);
    db()->prepare('INSERT INTO email_signatures (user_id, data, add_to_inbox) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE data = VALUES(data), add_to_inbox = VALUES(add_to_inbox)')
        ->execute([$uid, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $addToInbox ? 1 : 0]);
}

/** "modern-ops.net" -> https://modern-ops.net ; only http(s) links survive. */
function sig_link(string $u): string {
    $u = trim($u);
    if ($u === '') return '';
    if (!preg_match('#^https?://#i', $u)) $u = 'https://' . ltrim($u, '/');
    return filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u) ? $u : '';
}
function sig_tel(string $p): string { return 'tel:' . preg_replace('/[^0-9+]/', '', $p); }
function sig_display_url(string $u): string { return preg_replace('#^https?://(www\.)?#i', '', rtrim($u, '/')); }

/** Image facts for the signature: [url, width, height] or null. */
function sig_logo_img(array $d): ?array {
    $base = sig_public_base() . '/api/sig_image.php';
    if ($d['logo'] === 'none') return null;
    if ($d['logo'] === 'custom' && ($img = sig_image($d['logo_key']))) {
        [$w, $h] = [(int) $img['width'], (int) $img['height']];
        $url = $base . '?k=' . $img['k'];
    } elseif ($d['logo'] === 'company' && ($c = sig_company_logo())) {
        [$w, $h] = [$c['width'], $c['height']];
        $url = $base . '?logo=1';
    } else return null;
    $dw = (int) $d['logo_width'];
    return [$url, $dw, $w ? max(1, (int) round($h * $dw / $w)) : $dw];
}
function sig_photo_img(array $d): ?array {
    if ($d['photo_key'] === '' || !($img = sig_image($d['photo_key']))) return null;
    return [sig_public_base() . '/api/sig_image.php?k=' . $img['k'], 84, 84];
}

/** The signature as email-safe HTML. */
function sig_html(array $d): string {
    $font = SIG_FONTS[$d['font']] ?? SIG_FONTS['Arial'];
    $c = $d['color'];
    $txt = '#333333'; $mut = '#777777';
    $base = 'font-family:' . $font . ';';
    $a = function (string $href, string $label, string $color) use ($base) {
        return '<a href="' . e($href) . '" style="' . $base . 'color:' . $color . ';text-decoration:none">' . e($label) . '</a>';
    };

    $nameLine = $d['name'] !== '' ? '<div style="' . $base . 'font-size:16px;font-weight:bold;color:#111111;line-height:20px">' . e($d['name']) . '</div>' : '';
    $roleBits = array_values(array_filter([$d['title'], $d['company']], 'strlen'));
    $roleLine = $roleBits ? '<div style="' . $base . 'font-size:13px;color:' . $c . ';line-height:18px;padding-top:2px">' . e(implode(' · ', $roleBits)) . '</div>' : '';
    $tagLine  = $d['tagline'] !== '' ? '<div style="' . $base . 'font-size:12px;color:' . $mut . ';font-style:italic;line-height:16px;padding-top:2px">' . e($d['tagline']) . '</div>' : '';

    // Contact rows: small coloured label, then the value.
    $rows = [];
    $lab = function (string $l) use ($base, $c) { return '<span style="' . $base . 'color:' . $c . ';font-weight:bold">' . $l . '</span>&nbsp;'; };
    if ($d['phone'] !== '')  $rows[] = $lab('P') . $a(sig_tel($d['phone']), $d['phone'], $txt);
    if ($d['mobile'] !== '') $rows[] = $lab('M') . $a(sig_tel($d['mobile']), $d['mobile'], $txt);
    if ($d['email'] !== '' && filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $rows[] = $lab('E') . $a('mailto:' . $d['email'], $d['email'], $txt);
    if (($w = sig_link($d['website'])) !== '') $rows[] = $lab('W') . $a($w, sig_display_url($w), $txt);
    if ($d['address'] !== '') $rows[] = $lab('A') . '<span style="' . $base . 'color:' . $txt . '">' . e($d['address']) . '</span>';

    $socials = [];
    foreach (SIG_SOCIALS as $k => $label) {
        if (($u = sig_link($d['social'][$k] ?? '')) !== '') $socials[] = $a($u, $label, $c);
    }
    $socialLine = $socials ? '<div style="' . $base . 'font-size:12px;line-height:18px;padding-top:6px">' . implode('<span style="color:#bbbbbb">&nbsp;&nbsp;|&nbsp;&nbsp;</span>', $socials) . '</div>' : '';

    $cta = '';
    if ($d['cta_text'] !== '' && ($cu = sig_link($d['cta_url'])) !== '') {
        $cta = '<table cellpadding="0" cellspacing="0" border="0" style="width:auto;border-collapse:collapse;margin-top:10px"><tr><td style="background:' . $c . ';border-radius:4px;padding:7px 14px">'
             . '<a href="' . e($cu) . '" style="' . $base . 'font-size:12px;font-weight:bold;color:#ffffff;text-decoration:none;display:inline-block">' . e($d['cta_text']) . '</a></td></tr></table>';
    }

    $logo = sig_logo_img($d);
    $photo = sig_photo_img($d);
    $img = function (?array $i, string $alt, bool $round = false) {
        if (!$i) return '';
        [$u, $w, $h] = $i;
        return '<img src="' . e($u) . '" width="' . $w . '" height="' . $h . '" alt="' . e($alt) . '" style="display:block;border:0;width:' . $w . 'px;height:' . $h . 'px;' . ($round ? 'border-radius:50%;' : '') . '">';
    };
    $logoLink = function (string $html) use ($d) {
        $w = sig_link($d['website']);
        return ($html !== '' && $w !== '') ? '<a href="' . e($w) . '" style="text-decoration:none">' . $html . '</a>' : $html;
    };

    $disc = $d['disclaimer'] !== '' ? '<div style="' . $base . 'font-size:10px;line-height:14px;color:#999999;padding-top:12px;max-width:520px">' . nl2br(e($d['disclaimer'])) . '</div>' : '';
    $contact = $rows ? '<div style="' . $base . 'font-size:12px;line-height:19px;color:' . $txt . ';padding-top:8px">' . implode('<br>', $rows) . '</div>' : '';

    if ($d['layout'] === 'compact') {
        $inline = [];
        if ($d['phone'] !== '') $inline[] = $a(sig_tel($d['phone']), $d['phone'], $txt);
        if ($d['mobile'] !== '') $inline[] = $a(sig_tel($d['mobile']), $d['mobile'], $txt);
        if ($d['email'] !== '' && filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $inline[] = $a('mailto:' . $d['email'], $d['email'], $txt);
        if (($w = sig_link($d['website'])) !== '') $inline[] = $a($w, sig_display_url($w), $c);
        $sep = '<span style="color:#bbbbbb">&nbsp;&nbsp;|&nbsp;&nbsp;</span>';
        $first = '<span style="' . $base . 'font-size:14px;font-weight:bold;color:#111111">' . e($d['name']) . '</span>'
               . ($roleBits ? '<span style="' . $base . 'font-size:13px;color:' . $c . '">&nbsp;&nbsp;' . e(implode(' · ', $roleBits)) . '</span>' : '');
        $inner = '<div style="' . $base . 'line-height:20px">' . $first . '</div>'
               . ($inline ? '<div style="' . $base . 'font-size:12px;line-height:18px;color:' . $txt . '">' . implode($sep, $inline) . '</div>' : '')
               . $socialLine . $cta;
        $html = '<table cellpadding="0" cellspacing="0" border="0" style="width:auto;border-collapse:collapse"><tr>'
              . '<td style="border-left:3px solid ' . $c . ';padding:2px 0 2px 10px;vertical-align:top">' . $inner . '</td></tr></table>' . $disc;
    } elseif ($d['layout'] === 'stacked') {
        $inner = ($photo ? '<div style="padding-bottom:10px">' . $img($photo, $d['name'], true) . '</div>' : '')
               . $nameLine . $roleLine . $tagLine
               . '<div style="border-top:2px solid ' . $c . ';width:40px;margin:10px 0 0;font-size:0;line-height:0">&nbsp;</div>'
               . $contact . $socialLine . $cta
               . ($logo ? '<div style="padding-top:14px">' . $logoLink($img($logo, $d['company'])) . '</div>' : '');
        $html = '<table cellpadding="0" cellspacing="0" border="0" style="width:auto;border-collapse:collapse"><tr><td style="vertical-align:top">' . $inner . '</td></tr></table>' . $disc;
    } else {   // side by side
        $left = '';
        if ($photo) $left .= $img($photo, $d['name'], true);
        if ($logo)  $left .= ($photo ? '<div style="height:10px;line-height:10px;font-size:0">&nbsp;</div>' : '') . $logoLink($img($logo, $d['company']));
        $right = $nameLine . $roleLine . $tagLine . $contact . $socialLine . $cta;
        $html = '<table cellpadding="0" cellspacing="0" border="0" style="width:auto;border-collapse:collapse"><tr>'
              . ($left !== '' ? '<td style="vertical-align:top;padding-right:16px">' . $left . '</td><td style="width:2px;background:' . $c . ';font-size:0;line-height:0">&nbsp;</td>' : '')
              . '<td style="vertical-align:top;padding-left:' . ($left !== '' ? '16' : '0') . 'px">' . $right . '</td></tr></table>' . $disc;
    }
    return '<div style="' . $base . 'font-size:12px;color:' . $txt . '">' . $html . '</div>';
}

/** Plain-text twin for the text part of an email. */
function sig_text(array $d): string {
    $l = [];
    if ($d['name'] !== '') $l[] = $d['name'];
    $role = implode(' · ', array_filter([$d['title'], $d['company']], 'strlen'));
    if ($role !== '') $l[] = $role;
    if ($d['phone'] !== '')  $l[] = 'P ' . $d['phone'];
    if ($d['mobile'] !== '') $l[] = 'M ' . $d['mobile'];
    if ($d['email'] !== '')  $l[] = 'E ' . $d['email'];
    if (($w = sig_link($d['website'])) !== '') $l[] = 'W ' . sig_display_url($w);
    if ($d['address'] !== '') $l[] = $d['address'];
    if ($d['cta_text'] !== '' && ($cu = sig_link($d['cta_url'])) !== '') $l[] = $d['cta_text'] . ': ' . $cu;
    if ($d['disclaimer'] !== '') $l[] = "\n" . $d['disclaimer'];
    return implode("\n", $l);
}
