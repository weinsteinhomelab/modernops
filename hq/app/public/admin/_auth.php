<?php
/** Session + auth + CSRF helpers. Included by every admin page. */
require_once __DIR__ . '/_db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function current_user(): ?int { return $_SESSION['uid'] ?? null; }
function current_username(): string { return $_SESSION['uname'] ?? ''; }

function require_login(): void {
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['uid']   = (int)$user['id'];
    $_SESSION['uname'] = $user['username'];
}

function logout_user(): void {
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void {
    $ok = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
    if (!$ok) {
        http_response_code(400);
        exit('Your session expired or the form token was invalid. Go back and try again.');
    }
}
