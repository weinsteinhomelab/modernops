<?php
/**
 * Minimal SMTP sender (no external libraries). Sends through an authenticated
 * mailbox over SSL (465) or STARTTLS (587). Best-effort: returns false on any
 * problem and never throws. Activates only when SMTP_PASS is set.
 */
require_once __DIR__ . '/config.php';

function mime_header(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** Deliver a fully-formed message (headers + blank line + body) to one recipient.
 *  $authUser / $mailFrom override the default mailbox (same shared password). */
function smtp_deliver(string $to, string $message, ?string $authUser = null, ?string $mailFrom = null): bool {
    if (SMTP_PASS === '') return false;
    $authUser = $authUser ?: SMTP_USER;
    $mailFrom = $mailFrom ?: MAIL_FROM;
    $transport = (SMTP_SECURE === 'ssl') ? 'ssl://' . SMTP_HOST . ':' . SMTP_PORT : 'tcp://' . SMTP_HOST . ':' . SMTP_PORT;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($transport, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return false;
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) { $data .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
        return $data;
    };
    $code = fn($r) => (int)substr((string)$r, 0, 3);
    $cmd  = function (string $c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };

    try {
        if ($code($read()) !== 220) { fclose($fp); return false; }
        if ($code($cmd('EHLO ' . client('domain'))) !== 250) { fclose($fp); return false; }
        if (SMTP_SECURE === 'tls') {
            if ($code($cmd('STARTTLS')) !== 220) { fclose($fp); return false; }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
            $cmd('EHLO ' . client('domain'));
        }
        if ($code($cmd('AUTH LOGIN')) !== 334) { fclose($fp); return false; }
        if ($code($cmd(base64_encode($authUser))) !== 334) { fclose($fp); return false; }
        if ($code($cmd(base64_encode(SMTP_PASS))) !== 235) { fclose($fp); return false; }
        if ($code($cmd('MAIL FROM:<' . $mailFrom . '>')) !== 250) { fclose($fp); return false; }
        $r = $code($cmd('RCPT TO:<' . $to . '>')); if ($r !== 250 && $r !== 251) { fclose($fp); return false; }
        if ($code($cmd('DATA')) !== 354) { fclose($fp); return false; }

        $b = str_replace("\r\n", "\n", $message);
        $b = preg_replace('/^\./m', '..', $b);           // dot-stuff
        $b = str_replace("\n", "\r\n", $b);
        if ($code($cmd($b . "\r\n.")) !== 250) { fclose($fp); return false; }
        $cmd('QUIT'); fclose($fp);
        $GLOBALS['MOHQ_LAST_SENT'] = $message;   // so the Inbox can file a copy in Sent
        return true;
    } catch (Throwable $ex) { @fclose($fp); return false; }
}

/** Quoted-printable: how normal mail apps send text (readable, short lines). Base64 text parts score worse with spam filters. */
function qp(string $s): string {
    return quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $s));
}

function base_headers(string $to, string $subject, string $replyTo, ?string $fromAddr = null, ?string $fromName = null, array $extra = []): string {
    $fromAddr = $fromAddr ?: MAIL_FROM;
    $fromName = $fromName ?: MAIL_FROM_NAME;
    $h  = 'From: ' . mime_header($fromName) . ' <' . $fromAddr . ">\r\n";
    $h .= 'To: <' . $to . ">\r\n";
    if ($replyTo !== '') $h .= 'Reply-To: <' . $replyTo . ">\r\n";
    $h .= 'Subject: ' . mime_header($subject) . "\r\n";
    $h .= 'Date: ' . date('r') . "\r\n";
    // A Message-ID is expected on every email; spam filters score its absence.
    $h .= 'Message-ID: <' . bin2hex(random_bytes(12)) . '.' . time() . '@' . (substr((string) strrchr($fromAddr, '@'), 1) ?: 'localhost') . ">\r\n";
    foreach ($extra as $k => $v) {
        if (preg_match('/^[A-Za-z-]+$/', (string) $k) && !preg_match('/[\r\n]/', (string) $v)) $h .= $k . ': ' . $v . "\r\n";
    }
    $h .= "MIME-Version: 1.0\r\n";
    return $h;
}

/** Plain-text email. */
function send_mail(string $to, string $subject, string $body, string $replyTo = ''): bool {
    $msg = base_headers($to, $subject, $replyTo)
         . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
         . qp($body);
    return smtp_deliver($to, $msg);
}

/** Plain-text email sent from a specific mailbox (same shared password). */
function send_plain(string $to, string $subject, string $body, string $fromUser, string $fromAddr, string $fromName = '', string $replyTo = '', array $extra = []): bool {
    $msg = base_headers($to, $subject, $replyTo, $fromAddr, $fromName, $extra)
         . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
         . qp($body);
    return smtp_deliver($to, $msg, $fromUser, $fromAddr);
}

/** Text + HTML email (e.g. with an HTML signature) sent from a specific mailbox. */
function send_alt(string $to, string $subject, string $text, string $html, string $fromUser, string $fromAddr, string $fromName = '', string $replyTo = '', array $extra = []): bool {
    $boundary = 'MOHQ-' . bin2hex(random_bytes(10));
    $msg  = base_headers($to, $subject, $replyTo, $fromAddr, $fromName, $extra);
    $msg .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n\r\n";
    $msg .= '--' . $boundary . "\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . qp($text) . "\r\n";
    $msg .= '--' . $boundary . "\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . qp($html) . "\r\n";
    $msg .= '--' . $boundary . "--";
    return smtp_deliver($to, $msg, $fromUser, $fromAddr);
}

/** Email with a PDF attachment, optionally from a specific mailbox. */
function send_mail_pdf(string $to, string $subject, string $body, string $pdfBytes, string $pdfName, string $replyTo = '', ?string $fromUser = null, ?string $fromAddr = null, ?string $fromName = null): bool {
    $boundary = 'MOHQ-' . bin2hex(random_bytes(10));
    $msg  = base_headers($to, $subject, $replyTo, $fromAddr, $fromName);
    $msg .= 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n\r\n";
    $msg .= '--' . $boundary . "\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . qp($body) . "\r\n";
    $msg .= '--' . $boundary . "\r\n";
    $msg .= 'Content-Type: application/pdf; name="' . $pdfName . "\"\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= 'Content-Disposition: attachment; filename="' . $pdfName . "\"\r\n\r\n";
    $msg .= chunk_split(base64_encode($pdfBytes)) . "\r\n";
    $msg .= '--' . $boundary . "--";
    return smtp_deliver($to, $msg, $fromUser, $fromAddr);
}

/** A MIME-safe attachment filename parameter (RFC 2231 for non-ASCII names). */
function mime_filename(string $name): string {
    $name = trim(preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', '', $name)) ?: 'attachment';
    if (preg_match('/[^\x20-\x7E]/', $name)) return "filename*=UTF-8''" . rawurlencode($name);
    return 'filename="' . $name . '"';
}

/** Email with files attached: $files = [['name' => ..., 'mime' => ..., 'data' => bytes], ...].
 *  $html = null sends plain text; otherwise text + HTML (multipart/alternative) inside multipart/mixed. */
function send_mixed(string $to, string $subject, string $text, ?string $html, array $files, string $fromUser, string $fromAddr, string $fromName = '', string $replyTo = '', array $extra = []): bool {
    $outer = 'MOHQ-' . bin2hex(random_bytes(10));
    $msg  = base_headers($to, $subject, $replyTo, $fromAddr, $fromName, $extra);
    $msg .= 'Content-Type: multipart/mixed; boundary="' . $outer . "\"\r\n\r\n";
    $msg .= '--' . $outer . "\r\n";
    if ($html !== null) {
        $inner = 'MOHQ-' . bin2hex(random_bytes(10));
        $msg .= 'Content-Type: multipart/alternative; boundary="' . $inner . "\"\r\n\r\n";
        $msg .= '--' . $inner . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . qp($text) . "\r\n";
        $msg .= '--' . $inner . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . qp($html) . "\r\n";
        $msg .= '--' . $inner . "--\r\n";
    } else {
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . qp($text) . "\r\n";
    }
    foreach ($files as $f) {
        $mime = preg_match('#^[\w.+-]+/[\w.+-]+$#', (string)($f['mime'] ?? '')) ? $f['mime'] : 'application/octet-stream';
        $fn = mime_filename((string)($f['name'] ?? 'attachment'));
        $msg .= '--' . $outer . "\r\n";
        $msg .= 'Content-Type: ' . $mime . "\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; " . $fn . "\r\n\r\n";
        $msg .= chunk_split(base64_encode((string)$f['data'])) . "\r\n";
    }
    $msg .= '--' . $outer . '--';
    return smtp_deliver($to, $msg, $fromUser, $fromAddr);
}
