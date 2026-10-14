<?php
/** Session + auth + CSRF + brute-force throttling. Included by every admin page. */
require_once __DIR__ . '/_db.php';

// --- Security headers (sent before any output) ---
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}

// Behind Cloudflare the connection to the app is HTTP, but the visitor is on HTTPS.
$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $__https,
    ]);
    session_name('MOHQSESS');
    session_start();
}

function current_user(): ?int { return $_SESSION['uid'] ?? null; }
function current_username(): string { return $_SESSION['uname'] ?? ''; }

function require_login(): void {
    if (!current_user()) { header('Location: login.php'); exit; }
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['uid']   = (int)$user['id'];
    $_SESSION['uname'] = $user['username'];
}

function logout_user(): void { $_SESSION = []; session_destroy(); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function check_csrf(): void {
    $ok = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
    if (!$ok) { http_response_code(400); exit('Your session expired or the form token was invalid. Go back and try again.'); }
}

/** Real client IP (prefers Cloudflare's header when present). */
function client_ip(): string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return substr((string)$ip, 0, 64);
}

/**
 * Brute-force status for an IP.
 * Returns [locked(bool), wait_seconds(int), fails(int)].
 * Policy: 5 failures in 15 min starts a lockout that grows with each
 * additional failure (1, 2, 4, 8 … minutes, capped at 15).
 */
function throttle_status(string $ip): array {
    try {
        $st = db()->prepare("SELECT COUNT(*) AS c, MAX(created_at) AS last
                             FROM login_attempts
                             WHERE ip = ? AND ok = 0 AND created_at > (NOW() - INTERVAL 15 MINUTE)");
        $st->execute([$ip]);
        $row = $st->fetch();
    } catch (Exception $ex) { return [false, 0, 0]; }

    $fails = (int)($row['c'] ?? 0);
    if ($fails < 5) return [false, 0, $fails];

    $last = strtotime($row['last'] ?? 'now');
    $lockSeconds = (int) min(900, 60 * (2 ** ($fails - 5))); // 1,2,4,8… min, max 15
    $wait = ($last + $lockSeconds) - time();
    return $wait > 0 ? [true, $wait, $fails] : [false, 0, $fails];
}

/** Record an attempt; on success, clear this IP's failures. Occasionally prune. */
function record_attempt(string $ip, string $user, bool $ok): void {
    try {
        db()->prepare("INSERT INTO login_attempts (ip, username, ok) VALUES (?,?,?)")
            ->execute([$ip, substr($user, 0, 80), $ok ? 1 : 0]);
        if ($ok) {
            db()->prepare("DELETE FROM login_attempts WHERE ip = ? AND ok = 0")->execute([$ip]);
        }
        if (random_int(1, 20) === 1) { // ~5% of the time, prune old rows
            db()->exec("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
        }
    } catch (Exception $ex) { /* non-fatal */ }
}

/** Human-friendly wait time. */
function fmt_wait(int $seconds): string {
    $m = (int)ceil($seconds / 60);
    return $m <= 1 ? 'about a minute' : "about {$m} minutes";
}
