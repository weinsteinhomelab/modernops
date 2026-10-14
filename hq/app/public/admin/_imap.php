<?php
/** IMAP helpers for the Email tab (uses webklex/php-imap). */
require_once __DIR__ . '/config.php';

function imap_ready(): bool {
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!is_file($autoload)) return false;
    require_once $autoload;
    return class_exists('Webklex\\PHPIMAP\\ClientManager') && SMTP_PASS !== '';
}

function imap_accounts(): array {
    return array_values(array_filter(array_map('trim', explode(',', MAIL_ACCOUNTS))));
}

/** @return \Webklex\PHPIMAP\Client */
function imap_client(string $account) {
    // No ext-imap in this image, so decode subjects with iconv instead of imap_utf8()
    $cm = new \Webklex\PHPIMAP\ClientManager(['options' => ['decoder' => ['message' => 'iconv', 'attachment' => 'iconv']]]);
    $client = $cm->make([
        'host'          => IMAP_HOST,
        'port'          => IMAP_PORT,
        'encryption'    => IMAP_ENCRYPTION ?: false,
        'validate_cert' => true,
        'username'      => $account,
        'password'      => SMTP_PASS,
        'protocol'      => 'imap',
    ]);
    $client->connect();
    return $client;
}

/** Inbox headers (no bodies) for an account, newest first. */
function imap_list(string $account, int $limit = 25): array {
    $client = imap_client($account);
    $folder = $client->getFolder('INBOX');
    $messages = $folder->query()->all()->limit($limit)->setFetchOrder('desc')->setFetchBody(false)->leaveUnread()->get();
    $out = [];
    foreach ($messages as $m) {
        $from = $m->getFrom();
        $f = ($from && $from->count()) ? $from->first() : null;
        $seen = false;
        foreach ($m->getFlags() as $flag) { if (stripos((string)$flag, 'seen') !== false) { $seen = true; break; } }
        $out[] = [
            'uid'       => (int)$m->getUid(),
            'from_name' => $f ? ((string)($f->personal ?: $f->mail)) : '(unknown)',
            'from_mail' => $f ? (string)$f->mail : '',
            'subject'   => (string)$m->getSubject(),
            'date'      => $m->getDate()->count() ? $m->getDate()->toDate()->toDateTimeString() : '',
            'seen'      => $seen,
        ];
    }
    $client->disconnect();
    usort($out, fn($a, $b) => $b['uid'] <=> $a['uid']); // newest first
    return $out;
}

/** Full message; marks it seen. */
function imap_open(string $account, int $uid): ?array {
    $client = imap_client($account);
    $folder = $client->getFolder('INBOX');
    $m = $folder->query()->getMessageByUid($uid);
    if (!$m) { $client->disconnect(); return null; }

    $from = $m->getFrom();
    $f = ($from && $from->count()) ? $from->first() : null;
    $toStr = '';
    try { foreach ($m->getTo()->toArray() as $a) { $toStr .= ($toStr ? ', ' : '') . ((string)($a->mail ?? '')); } } catch (\Throwable $e) {}

    $html = $m->hasHTMLBody() ? (string)$m->getHTMLBody() : '';
    $text = (string)$m->getTextBody();
    if (trim($text) === '' && $html !== '') $text = html_to_text($html);

    // Inline images (cid:) become data: URIs; everything else is a real attachment.
    $atts = [];
    try {
        foreach ($m->getAttachments() as $a) {
            $cid = trim((string)($a->id ?? ''), '<>');
            $mime = (string)($a->content_type ?? $a->getMimeType() ?? '');
            if ($html !== '' && $cid !== '' && stripos($html, 'cid:' . $cid) !== false && stripos($mime, 'image/') === 0) {
                $html = str_ireplace('cid:' . $cid, 'data:' . $mime . ';base64,' . base64_encode((string)$a->getContent()), $html);
            } else {
                $atts[] = (string)$a->getName();
            }
        }
    } catch (\Throwable $e) {}

    try { $m->setFlag('Seen'); } catch (\Throwable $e) {}

    $res = [
        'uid'        => $uid,
        'from_name'  => $f ? ((string)($f->personal ?: $f->mail)) : '',
        'from_mail'  => $f ? (string)$f->mail : '',
        'to'         => $toStr,
        'subject'    => (string)$m->getSubject(),
        'date'       => $m->getDate()->count() ? $m->getDate()->toDate()->toDateTimeString() : '',
        'body'       => $text,
        'html'       => $html,
        'attachments'=> $atts,
    ];
    $client->disconnect();
    return $res;
}

/** Readable plain text from an HTML email (used for replies and HTML-only messages). */
function html_to_text(string $html): string {
    $h = preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1>#is', '', $html);
    $h = preg_replace('#<br\s*/?>#i', "\n", $h);
    $h = preg_replace('#</(p|div|tr|li|h[1-6]|table|blockquote)>#i', "\n", $h);
    $h = preg_replace('#<li\b[^>]*>#i', '• ', $h);
    $h = preg_replace('#<a\b[^>]*href="(https?://[^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $h);
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace("\u{00A0}", ' ', $t);
    $t = preg_replace("/[ \t]+/", ' ', $t);
    $t = preg_replace("/ *\n */", "\n", $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim($t);
}

/**
 * Wrap an email's HTML for display inside a sandboxed iframe. Scripts never run;
 * remote images (often tracking pixels) are blocked unless $images is true.
 */
function email_html_doc(string $html, bool $images): string {
    $img = $images ? "data: https: http:" : "data:";
    $csp = "default-src 'none'; style-src 'unsafe-inline' https:; font-src https: data:; img-src $img";
    $head = '<meta charset="UTF-8"><meta http-equiv="Content-Security-Policy" content="' . $csp . '">'
          . '<base target="_blank">'
          . '<style>html,body{margin:0;padding:0;background:#fff;color:#1a1a1a}body{padding:12px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}</style>';
    // Drop anything executable or that could pull in other pages.
    $html = preg_replace('#<(script|iframe|object|embed|form|meta|base|link)\b[^>]*>(.*?</\1>)?#is', '', $html);
    $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
    $html = preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1="#"', $html);
    if (stripos($html, '<head') !== false) {
        return preg_replace('#<head\b[^>]*>#i', '$0' . $head, $html, 1);
    }
    return '<!DOCTYPE html><html><head>' . $head . '</head><body>' . $html . '</body></html>';
}

function imap_delete(string $account, int $uid): bool {
    try {
        $client = imap_client($account);
        $folder = $client->getFolder('INBOX');
        $m = $folder->query()->getMessageByUid($uid);
        if ($m) $m->delete();
        $client->disconnect();
        return true;
    } catch (\Throwable $e) { return false; }
}
