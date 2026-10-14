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
    $cm = new \Webklex\PHPIMAP\ClientManager();
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
    $messages = $folder->query()->limit($limit)->setFetchOrder('desc')->setFetchBody(false)->leaveUnread()->get();
    $out = [];
    foreach ($messages as $m) {
        $from = $m->getFrom();
        $f = ($from && count($from)) ? $from[0] : null;
        $seen = false;
        foreach ($m->getFlags() as $flag) { if (stripos((string)$flag, 'seen') !== false) { $seen = true; break; } }
        $out[] = [
            'uid'       => (int)$m->getUid(),
            'from_name' => $f ? ((string)($f->personal ?: $f->mail)) : '(unknown)',
            'from_mail' => $f ? (string)$f->mail : '',
            'subject'   => (string)$m->getSubject(),
            'date'      => $m->getDate() ? $m->getDate()->toDateTimeString() : '',
            'seen'      => $seen,
        ];
    }
    $client->disconnect();
    return $out;
}

/** Full message; marks it seen. */
function imap_open(string $account, int $uid): ?array {
    $client = imap_client($account);
    $folder = $client->getFolder('INBOX');
    $m = $folder->query()->getMessageByUid($uid);
    if (!$m) { $client->disconnect(); return null; }

    $from = $m->getFrom();
    $f = ($from && count($from)) ? $from[0] : null;
    $toStr = '';
    try { foreach ($m->getTo() as $a) { $toStr .= ($toStr ? ', ' : '') . ((string)($a->mail ?? '')); } } catch (\Throwable $e) {}

    $text = (string)$m->getTextBody();
    if (trim($text) === '') {
        $html = (string)$m->getHTMLBody();
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }
    $atts = [];
    try { foreach ($m->getAttachments() as $a) { $atts[] = (string)$a->getName(); } } catch (\Throwable $e) {}

    try { $m->setFlag('Seen'); } catch (\Throwable $e) {}

    $res = [
        'uid'        => $uid,
        'from_name'  => $f ? ((string)($f->personal ?: $f->mail)) : '',
        'from_mail'  => $f ? (string)$f->mail : '',
        'to'         => $toStr,
        'subject'    => (string)$m->getSubject(),
        'date'       => $m->getDate() ? $m->getDate()->toDateTimeString() : '',
        'body'       => $text,
        'attachments'=> $atts,
    ];
    $client->disconnect();
    return $res;
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
