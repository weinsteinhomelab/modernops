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

/** Deliver a fully-formed message (headers + blank line + body) to one recipient. */
function smtp_deliver(string $to, string $message): bool {
    if (SMTP_PASS === '') return false;
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
        if ($code($cmd('EHLO modern-ops.net')) !== 250) { fclose($fp); return false; }
        if (SMTP_SECURE === 'tls') {
            if ($code($cmd('STARTTLS')) !== 220) { fclose($fp); return false; }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
            $cmd('EHLO modern-ops.net');
        }
        if ($code($cmd('AUTH LOGIN')) !== 334) { fclose($fp); return false; }
        if ($code($cmd(base64_encode(SMTP_USER))) !== 334) { fclose($fp); return false; }
        if ($code($cmd(base64_encode(SMTP_PASS))) !== 235) { fclose($fp); return false; }
        if ($code($cmd('MAIL FROM:<' . MAIL_FROM . '>')) !== 250) { fclose($fp); return false; }
        $r = $code($cmd('RCPT TO:<' . $to . '>')); if ($r !== 250 && $r !== 251) { fclose($fp); return false; }
        if ($code($cmd('DATA')) !== 354) { fclose($fp); return false; }

        $b = str_replace("\r\n", "\n", $message);
        $b = preg_replace('/^\./m', '..', $b);           // dot-stuff
        $b = str_replace("\n", "\r\n", $b);
        if ($code($cmd($b . "\r\n.")) !== 250) { fclose($fp); return false; }
        $cmd('QUIT'); fclose($fp); return true;
    } catch (Throwable $ex) { @fclose($fp); return false; }
}

function base_headers(string $to, string $subject, string $replyTo): string {
    $h  = 'From: ' . mime_header(MAIL_FROM_NAME) . ' <' . MAIL_FROM . ">\r\n";
    $h .= 'To: <' . $to . ">\r\n";
    if ($replyTo !== '') $h .= 'Reply-To: <' . $replyTo . ">\r\n";
    $h .= 'Subject: ' . mime_header($subject) . "\r\n";
    $h .= 'Date: ' . date('r') . "\r\n";
    $h .= "MIME-Version: 1.0\r\n";
    return $h;
}

/** Plain-text email. */
function send_mail(string $to, string $subject, string $body, string $replyTo = ''): bool {
    $msg = base_headers($to, $subject, $replyTo)
         . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
         . $body;
    return smtp_deliver($to, $msg);
}

/** Email with a PDF attachment. */
function send_mail_pdf(string $to, string $subject, string $body, string $pdfBytes, string $pdfName, string $replyTo = ''): bool {
    $boundary = 'MOHQ-' . bin2hex(random_bytes(10));
    $msg  = base_headers($to, $subject, $replyTo);
    $msg .= 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n\r\n";
    $msg .= '--' . $boundary . "\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n" . $body . "\r\n";
    $msg .= '--' . $boundary . "\r\n";
    $msg .= 'Content-Type: application/pdf; name="' . $pdfName . "\"\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= 'Content-Disposition: attachment; filename="' . $pdfName . "\"\r\n\r\n";
    $msg .= chunk_split(base64_encode($pdfBytes)) . "\r\n";
    $msg .= '--' . $boundary . "--";
    return smtp_deliver($to, $msg);
}
