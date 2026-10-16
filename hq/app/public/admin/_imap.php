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

/* ------------------------------------------------------------------------
 * One combined mailbox: Inbox / Sent / Drafts / Spam / Deleted / Archive
 * across every account the person can use. Each account keeps its own
 * folders on the server; "boxes" map our names onto them.
 * ---------------------------------------------------------------------- */

const MAIL_BOXES = [
    'inbox'   => 'Inbox',
    'sent'    => 'Sent',
    'drafts'  => 'Drafts',
    'archive' => 'Archive',
    'spam'    => 'Spam',
    'deleted' => 'Deleted',
];

/** Which server folder(s) hold each box, by folder name (last part of the path). */
function imap_box_of(string $path): ?string {
    $name = strtolower(trim(preg_replace('#^.*[./]#', '', $path)));
    if (strtoupper($path) === 'INBOX') return 'inbox';
    if (in_array($name, ['sent', 'sent items', 'sent messages', 'sent mail'], true)) return 'sent';
    if (in_array($name, ['drafts', 'draft'], true)) return 'drafts';
    if (in_array($name, ['spam', 'junk', 'junk e-mail', 'junk email', 'bulk mail'], true)) return 'spam';
    if (in_array($name, ['trash', 'deleted', 'deleted messages', 'deleted items', 'bin'], true)) return 'deleted';
    if (in_array($name, ['archive', 'archives', 'all mail'], true)) return 'archive';
    return null;
}

/** ['inbox' => ['INBOX'], 'sent' => ['INBOX.Sent'], ...] for one connected client. */
function imap_folder_map($client): array {
    $map = array_fill_keys(array_keys(MAIL_BOXES), []);
    $map['inbox'] = ['INBOX'];
    foreach ($client->getFolders(false) as $f) {
        $b = imap_box_of((string) $f->path);
        if ($b && $b !== 'inbox') $map[$b][] = (string) $f->path;
    }
    return $map;
}

/** Folder to move things into for a box (first match), creating it if it's missing. */
function imap_box_target($client, string $box): ?string {
    $map = imap_folder_map($client);
    if (!empty($map[$box])) return $map[$box][0];
    if ($box === 'inbox') return 'INBOX';
    try { $client->createFolder('INBOX.' . MAIL_BOXES[$box], false); return 'INBOX.' . MAIL_BOXES[$box]; } catch (\Throwable $e) { return null; }
}

/** Short-lived cache so the combined list doesn't log in to every mailbox on each click. */
function mbox_cache_file(string $account, string $box, int $limit): string {
    return sys_get_temp_dir() . '/mohq-mbox-' . md5(APP_SECRET . '|' . $account . '|' . $box . '|' . $limit) . '.json';
}
function mbox_cache_clear(string $account = ''): void {
    // Keys are hashed, so clear every cached list; they refill on the next view.
    foreach (glob(sys_get_temp_dir() . '/mohq-mbox-*.json') ?: [] as $f) @unlink($f);
}

function imap_addr_list($attr): string {
    $out = [];
    try { foreach ($attr->toArray() as $a) { $m = (string)($a->mail ?? ''); if ($m !== '') $out[] = $m; } } catch (\Throwable $e) {}
    return implode(', ', $out);
}

/** Headers for one account + box, newest first. Each row knows its account and folder. */
function mbox_list(string $account, string $box, int $limit = 40, bool $fresh = false): array {
    $cache = mbox_cache_file($account, $box, $limit);
    if (!$fresh && is_file($cache) && filemtime($cache) > time() - 60) {
        $rows = json_decode((string) file_get_contents($cache), true);
        if (is_array($rows)) return $rows;
    }
    $client = imap_client($account);
    $rows = [];
    foreach (imap_folder_map($client)[$box] ?? [] as $path) {
        try {
            $folder = $client->getFolderByPath($path);
            if (!$folder) continue;
            $msgs = $folder->query()->all()->limit($limit)->setFetchOrder('desc')->setFetchBody(false)->leaveUnread()->get();
        } catch (\Throwable $e) { continue; }
        foreach ($msgs as $m) {
            $from = $m->getFrom();
            $f = ($from && $from->count()) ? $from->first() : null;
            $seen = false; $flagged = false;
            foreach ($m->getFlags() as $flag) {
                if (stripos((string)$flag, 'seen') !== false) $seen = true;
                if (stripos((string)$flag, 'flagged') !== false) $flagged = true;
            }
            $date = $m->getDate()->count() ? $m->getDate()->toDate() : null;
            $rows[] = [
                'account'   => $account,
                'folder'    => $path,
                'uid'       => (int) $m->getUid(),
                'from_name' => $f ? ((string)($f->personal ?: $f->mail)) : '(unknown)',
                'from_mail' => $f ? (string) $f->mail : '',
                'to'        => imap_addr_list($m->getTo()),
                'subject'   => (string) $m->getSubject(),
                'date'      => $date ? $date->toDateTimeString() : '',
                'ts'        => $date ? $date->getTimestamp() : 0,
                'seen'      => $seen,
                'flagged'   => $flagged,
            ];
        }
    }
    $client->disconnect();
    usort($rows, fn($a, $b) => $b['ts'] <=> $a['ts']);
    @file_put_contents($cache, json_encode($rows));
    return $rows;
}

/** Full message from any folder; marks it seen. */
function mbox_open(string $account, string $folderPath, int $uid): ?array {
    $client = imap_client($account);
    $folder = $client->getFolderByPath($folderPath);
    $m = $folder ? $folder->query()->getMessageByUid($uid) : null;
    if (!$m) { $client->disconnect(); return null; }
    $from = $m->getFrom();
    $f = ($from && $from->count()) ? $from->first() : null;
    $html = $m->hasHTMLBody() ? (string) $m->getHTMLBody() : '';
    $text = (string) $m->getTextBody();
    if (trim($text) === '' && $html !== '') $text = html_to_text($html);
    $atts = [];
    try {
        foreach ($m->getAttachments() as $a) {
            $cid = trim((string)($a->id ?? ''), '<>');
            $mime = (string)($a->content_type ?? $a->getMimeType() ?? '');
            if ($html !== '' && $cid !== '' && stripos($html, 'cid:' . $cid) !== false && stripos($mime, 'image/') === 0) {
                $html = str_ireplace('cid:' . $cid, 'data:' . $mime . ';base64,' . base64_encode((string) $a->getContent()), $html);
            } else {
                $atts[] = (string) $a->getName();
            }
        }
    } catch (\Throwable $e) {}
    $wasSeen = false;
    foreach ($m->getFlags() as $flag) if (stripos((string)$flag, 'seen') !== false) $wasSeen = true;
    if (!$wasSeen) { try { $m->setFlag('Seen'); } catch (\Throwable $e) {} }
    $mid = '';
    try { $mid = (string) $m->getMessageId(); } catch (\Throwable $e) {}
    $res = [
        'account'    => $account,
        'folder'     => $folderPath,
        'box'        => imap_box_of($folderPath),
        'uid'        => $uid,
        'from_name'  => $f ? ((string)($f->personal ?: $f->mail)) : '',
        'from_mail'  => $f ? (string) $f->mail : '',
        'to'         => imap_addr_list($m->getTo()),
        'cc'         => imap_addr_list($m->getCc()),
        'subject'    => (string) $m->getSubject(),
        'date'       => $m->getDate()->count() ? $m->getDate()->toDate()->toDateTimeString() : '',
        'message_id' => trim($mid, '<> '),
        'body'       => $text,
        'html'       => $html,
        'attachments'=> $atts,
    ];
    $client->disconnect();
    if (!$wasSeen) mbox_cache_clear($account);
    return $res;
}

/** Move a message to another box (Deleted, Spam, Archive, back to Inbox). */
function mbox_move(string $account, string $folderPath, int $uid, string $toBox): bool {
    try {
        $client = imap_client($account);
        $folder = $client->getFolderByPath($folderPath);
        $m = $folder ? $folder->query()->getMessageByUid($uid) : null;
        $target = imap_box_target($client, $toBox);
        $ok = $m && $target && $target !== $folderPath && $m->move($target, true) !== null;
        $client->disconnect();
        mbox_cache_clear($account);
        return (bool) $ok;
    } catch (\Throwable $e) { return false; }
}

/** Gone for good (only offered inside Deleted and Spam). */
function mbox_delete_forever(string $account, string $folderPath, int $uid): bool {
    try {
        $client = imap_client($account);
        $folder = $client->getFolderByPath($folderPath);
        $m = $folder ? $folder->query()->getMessageByUid($uid) : null;
        if ($m) $m->delete(true);
        $client->disconnect();
        mbox_cache_clear($account);
        return (bool) $m;
    } catch (\Throwable $e) { return false; }
}

function mbox_set_seen(string $account, string $folderPath, int $uid, bool $seen): bool {
    try {
        $client = imap_client($account);
        $folder = $client->getFolderByPath($folderPath);
        $m = $folder ? $folder->query()->getMessageByUid($uid) : null;
        if ($m) { $seen ? $m->setFlag('Seen') : $m->unsetFlag('Seen'); }
        $client->disconnect();
        mbox_cache_clear($account);
        return (bool) $m;
    } catch (\Throwable $e) { return false; }
}

/** Keep a copy of something we sent in that mailbox's Sent folder. */
function mbox_save_sent(string $account, string $raw): bool {
    try {
        $client = imap_client($account);
        $target = imap_box_target($client, 'sent');
        $ok = false;
        if ($target && ($folder = $client->getFolderByPath($target))) {
            $folder->appendMessage(str_replace(["\r\n", "\n"], ["\n", "\r\n"], $raw), ['\\Seen']);
            $ok = true;
        }
        $client->disconnect();
        mbox_cache_clear($account);
        return $ok;
    } catch (\Throwable $e) { return false; }
}

/** Colour for each mailbox, so you can tell at a glance where an email came in. */
function mailbox_color(string $account): string {
    $set = (array) client('email.colors', []);
    if (!empty($set[$account])) return (string) $set[$account];
    $palette = ['#2f6fdb', '#1f9d55', '#e07b1a', '#8e44c9', '#d63c7a', '#139c9c', '#b8860b', '#5a6b7b'];
    $i = array_search($account, imap_accounts(), true);
    if ($i === false) $i = abs(crc32($account));
    return $palette[$i % count($palette)];
}
