<?php
/**
 * Modern Ops HQ — configuration.
 *
 * All values are read from environment variables (set in hq/.env via
 * docker-compose). This file contains NO secrets, so it's safe in git.
 */
function envv(string $k, string $default = ''): string {
    $v = getenv($k);
    return ($v === false || $v === '') ? $default : $v;
}

// ---- Database (the "db" service in docker-compose) ----
define('DB_HOST', envv('DB_HOST', 'db'));
define('DB_NAME', envv('DB_NAME', 'modernops_hq'));
define('DB_USER', envv('DB_USER', 'hq'));
define('DB_PASS', envv('DB_PASS', ''));

// ---- New-lead notification ----
define('NOTIFY_EMAIL', envv('NOTIFY_EMAIL', 'info@modern-ops.net'));

// ---- Public origin allowed to post the website form (CORS) ----
define('SITE_ORIGIN', envv('SITE_ORIGIN', 'https://modern-ops.net'));

// ---- Outgoing email for sending quotes (Phase 2) ----
define('SMTP_HOST',   envv('SMTP_HOST', 'mail.modern-ops.net'));
define('SMTP_PORT',   (int) envv('SMTP_PORT', '465'));
define('SMTP_SECURE', envv('SMTP_SECURE', 'ssl'));
define('SMTP_USER',   envv('SMTP_USER', 'info@modern-ops.net'));
define('SMTP_PASS',   envv('SMTP_PASS', ''));
define('MAIL_FROM',   envv('MAIL_FROM', 'info@modern-ops.net'));
define('MAIL_FROM_NAME', envv('MAIL_FROM_NAME', 'Modern Ops'));

// Quotes are sent from a dedicated mailbox (same password as the others).
define('QUOTE_SMTP_USER', envv('QUOTE_SMTP_USER', 'quotes@modern-ops.net'));
define('QUOTE_MAIL_FROM', envv('QUOTE_MAIL_FROM', 'quotes@modern-ops.net'));
define('QUOTE_MAIL_FROM_NAME', envv('QUOTE_MAIL_FROM_NAME', 'Modern Ops Quotes'));

// ---- Reading mail (IMAP) ----
define('IMAP_HOST', envv('IMAP_HOST', 'mail.modern-ops.net'));
define('IMAP_PORT', (int) envv('IMAP_PORT', '993'));
define('IMAP_ENCRYPTION', envv('IMAP_ENCRYPTION', 'ssl')); // ssl | tls | ''
// The mailboxes shown in the Email tab (comma-separated). All share SMTP_PASS.
define('MAIL_ACCOUNTS', envv('MAIL_ACCOUNTS', 'peter@modern-ops.net,info@modern-ops.net,service@modern-ops.net,quotes@modern-ops.net'));

// ---- Business info shown on quotes ----
define('BIZ_NAME',     envv('BIZ_NAME', 'Modern Ops'));
define('BIZ_EMAIL',    envv('BIZ_EMAIL', 'info@modern-ops.net'));
define('BIZ_LOCATION', envv('BIZ_LOCATION', 'Delaware Valley, PA'));
define('BIZ_WEBSITE',  envv('BIZ_WEBSITE', 'modern-ops.net'));

// ---- App secret ----
define('APP_SECRET', envv('APP_SECRET', 'change-me'));
