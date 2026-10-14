<?php
/**
 * Minimal SMTP sender (no external libraries). Sends through an authenticated
 * mailbox (e.g. SiteGround's info@modern-ops.net) over SSL (465) or STARTTLS
 * (587). Best-effort: returns false on any problem and never throws, so a
 * failed notification can't break a form submission.
 *
 * Activates only when SMTP_PASS is set in the environment; otherwise no-ops.
 */
require_once __DIR__ . '/config.php';

function mime_header(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function send_mail(string $to, string $subject, string $body, string $replyTo = ''): bool {
    if (SMTP_PASS === '') return false; // not configured yet

    $host = SMTP_HOST; $port = SMTP_PORT; $secure = SMTP_SECURE;
    $transport = ($secure === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($transport, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return false;
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $code = fn($r) => (int)substr((string)$r, 0, 3);
    $cmd  = function (string $c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };

    try {
        if ($code($read()) !== 220) { fclose($fp); return false; }
        if ($code($cmd('EHLO modern-ops.net')) !== 250) { fclose($fp); return false; }

        if ($secure === 'tls') {
            if ($code($cmd('STARTTLS')) !== 220) { fclose($fp); return false; }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
            $cmd('EHLO modern-ops.net');
        }

        if ($code($cmd('AUTH LOGIN')) !== 334) { fclose($fp); return false; }
        if ($code($cmd(base64_encode(SMTP_USER))) !== 334) { fclose($fp); return false; }
        if ($code($cmd(base64_encode(SMTP_PASS))) !== 235) { fclose($fp); return false; }

        if ($code($cmd('MAIL FROM:<' . MAIL_FROM . '>')) !== 250) { fclose($fp); return false; }
        $rcpt = $code($cmd('RCPT TO:<' . $to . '>'));
        if ($rcpt !== 250 && $rcpt !== 251) { fclose($fp); return false; }
        if ($code($cmd('DATA')) !== 354) { fclose($fp); return false; }

        $headers  = 'From: ' . mime_header(MAIL_FROM_NAME) . ' <' . MAIL_FROM . ">\r\n";
        $headers .= 'To: <' . $to . ">\r\n";
        if ($replyTo !== '') $headers .= 'Reply-To: <' . $replyTo . ">\r\n";
        $headers .= 'Subject: ' . mime_header($subject) . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= 'Date: ' . date('r') . "\r\n";

        // Normalise newlines and dot-stuff per RFC 5321
        $b = str_replace("\r\n", "\n", $body);
        $b = preg_replace('/^\./m', '..', $b);
        $b = str_replace("\n", "\r\n", $b);

        if ($code($cmd($headers . "\r\n" . $b . "\r\n.")) !== 250) { fclose($fp); return false; }
        $cmd('QUIT');
        fclose($fp);
        return true;
    } catch (Throwable $ex) {
        @fclose($fp);
        return false;
    }
}
