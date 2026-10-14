<?php
/** Database bootstrap + small helpers. Included by every admin page. */

$__cfg = __DIR__ . '/config.php';
if (!file_exists($__cfg)) {
    http_response_code(500);
    exit('Setup needed: copy admin/config.sample.php to admin/config.php and fill in your database details.');
}
require_once $__cfg;

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $ex) {
            http_response_code(500);
            exit('Database connection failed. Double-check the DB settings in admin/config.php.');
        }
    }
    return $pdo;
}

/** HTML-escape for safe output. */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Does the schema exist yet? */
function schema_ready(): bool {
    try { db()->query('SELECT 1 FROM admin_users LIMIT 1'); return true; }
    catch (Exception $ex) { return false; }
}

/** Read a setting value. */
function setting(string $key, $default = null) {
    try {
        $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Exception $ex) { return $default; }
}

/** Write a setting value. */
function set_setting(string $key, $value): void {
    $st = db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute([$key, $value]);
}

function money($n): string { return '$' . number_format((float)$n, 2); }
