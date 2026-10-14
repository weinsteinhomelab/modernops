<?php
/**
 * HQ configuration.
 *
 * Two sources, on purpose:
 *  - clients/<CLIENT>.php  — everything that differs per client (name, colors,
 *    domain, email addresses, tax, quote terms, products, starting pricebook).
 *    No secrets, safe in git. Pick the file with the CLIENT env var.
 *  - environment (.env)    — secrets and infrastructure only: database,
 *    mailbox password, APP_SECRET.
 * The code is identical for every client; only these two change.
 */
function envv(string $k, string $default = ''): string {
    static $file = null;
    $v = getenv($k);
    if ($v !== false && $v !== '') return $v;
    // No Docker (e.g. SiteGround): read KEY=VALUE lines from the .env file at the
    // app root, one level above the web root, so it is never served.
    if ($file === null) {
        $file = [];
        $path = __DIR__ . '/../../.env';
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line[0] === '#' || strpos($line, '=') === false) continue;
                [$name, $val] = explode('=', $line, 2);
                $file[trim($name)] = trim(trim($val), "\"'");
            }
        }
    }
    return (isset($file[$k]) && $file[$k] !== '') ? $file[$k] : $default;
}

/** The client config array, or one value from it by dotted path: client('email.contact'). */
function client(?string $path = null, $default = null) {
    static $c = null;
    if ($c === null) {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(envv('CLIENT', 'modernops')));
        $file = __DIR__ . '/../../clients/' . $slug . '.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('HQ is not configured: missing clients/' . $slug . '.php');
        }
        $c = require $file;
    }
    if ($path === null) return $c;
    $v = $c;
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $default;
        $v = $v[$k];
    }
    return $v;
}

/** Is this Ops Essentials product turned on for the client? customers | quotes | inbox */
function product_on(string $p): bool {
    return (bool) client('products.' . $p, false);
}

// ---- Time zone: every date HQ shows or saves is in the client's local time ----
date_default_timezone_set((string) client('timezone', 'America/New_York') ?: 'America/New_York');

// ---- Database (the "db" service in docker-compose) ----
define('DB_HOST', envv('DB_HOST', 'db'));
define('DB_NAME', envv('DB_NAME', 'hq'));
define('DB_USER', envv('DB_USER', 'hq'));
define('DB_PASS', envv('DB_PASS', ''));

// ---- Secrets ----
define('SMTP_PASS',  envv('SMTP_PASS', ''));   // shared password for every mailbox below
define('APP_SECRET', envv('APP_SECRET', 'change-me'));

// ---- From the client file ----
define('NOTIFY_EMAIL', client('email.notify'));
define('SITE_ORIGIN',  client('site_origin'));

define('SMTP_HOST',   client('email.smtp_host'));
define('SMTP_PORT',   (int) client('email.smtp_port', 465));
define('SMTP_SECURE', client('email.smtp_secure', 'ssl'));
define('SMTP_USER',   client('email.from'));
define('MAIL_FROM',   client('email.from'));
define('MAIL_FROM_NAME', client('email.from_name'));

define('QUOTE_SMTP_USER', client('email.quote_from'));
define('QUOTE_MAIL_FROM', client('email.quote_from'));
define('QUOTE_MAIL_FROM_NAME', client('email.quote_from_name'));

define('IMAP_HOST', client('email.imap_host'));
define('IMAP_PORT', (int) client('email.imap_port', 993));
define('IMAP_ENCRYPTION', client('email.imap_encryption', 'ssl')); // ssl | tls | ''
define('MAIL_ACCOUNTS', implode(',', (array) client('email.accounts', [])));

define('BIZ_NAME',     client('name'));
define('BIZ_EMAIL',    client('email.contact'));
define('BIZ_LOCATION', client('location'));
define('BIZ_WEBSITE',  client('domain'));
