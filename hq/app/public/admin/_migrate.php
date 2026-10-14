<?php
/** Idempotent schema migrations so a live database upgrades itself safely. */
require_once __DIR__ . '/_db.php';

const SCHEMA_VERSION = 2;

/** The standard Modern Ops service catalog (from the quote template). */
function catalog_defaults(): array {
    return [
        // title, description, price, billing
        ['Website Design & Setup', 'Custom website, hosting configuration, domain setup, SSL', 350.00, 'one_time'],
        ['Business Email Setup', 'Professional email address at your domain', 0.00, 'one_time'],
        ['Domain Registration', 'Annual: 1-year domain registration', 15.00, 'one_time'],
        ['Hosting', 'Annual: managed website hosting', 60.00, 'one_time'],
        ['Google Business Profile Setup', 'Create and optimize your Google Business listing', 0.00, 'one_time'],
        ['Social Media Profile Setup', 'Create and brand your social media pages', 0.00, 'one_time'],
        ['Additional Email Account Setup', 'Extra email addresses at your domain', 0.00, 'one_time'],
        ['Site Maintenance', 'Security patches, uptime monitoring, backups', 70.00, 'monthly'],
        ['Site Maintenance & Content Updates', 'Everything in maintenance plus text and photo updates', 120.00, 'monthly'],
    ];
}

function seed_catalog(): void {
    try {
        $n = (int) db()->query('SELECT COUNT(*) FROM catalog_items')->fetchColumn();
        if ($n > 0) return;
        $st = db()->prepare('INSERT INTO catalog_items (title, description, unit_price, billing, sort_order) VALUES (?,?,?,?,?)');
        $i = 0;
        foreach (catalog_defaults() as $row) {
            $st->execute([$row[0], $row[1], $row[2], $row[3], $i++]);
        }
    } catch (Exception $ex) { /* non-fatal */ }
}

function seed_defaults(): void {
    if (setting('next_quote_number') === null) set_setting('next_quote_number', '1001');
    if (setting('default_tax_rate') === null)  set_setting('default_tax_rate', '0');
    if (setting('default_tax_label') === null) set_setting('default_tax_label', 'Sales Tax');
}

/** Bring older databases (pre–Phase 2) up to the current schema. */
function run_phase2_migration(): void {
    $ddl = [
        "CREATE TABLE IF NOT EXISTS catalog_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            billing ENUM('one_time','monthly') NOT NULL DEFAULT 'one_time',
            active TINYINT NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "ALTER TABLE quote_items ADD COLUMN IF NOT EXISTS title VARCHAR(190) NOT NULL DEFAULT '' AFTER quote_id",
        "ALTER TABLE quote_items ADD COLUMN IF NOT EXISTS billing ENUM('one_time','monthly') NOT NULL DEFAULT 'one_time' AFTER unit_price",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS tax_label VARCHAR(80) NOT NULL DEFAULT 'Sales Tax' AFTER tax_rate",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS terms_included TINYINT NOT NULL DEFAULT 1 AFTER notes",
    ];
    foreach ($ddl as $sql) {
        try { db()->exec($sql); } catch (Exception $ex) { /* column/table may already exist */ }
    }
    seed_catalog();
    seed_defaults();
}

/** Called on authenticated pages; runs real work only once per version bump. */
function maybe_migrate(): void {
    try {
        $v = (int) (setting('schema_version', '1'));
        if ($v >= SCHEMA_VERSION) return;
        run_phase2_migration();
        set_setting('schema_version', (string) SCHEMA_VERSION);
    } catch (Exception $ex) { /* non-fatal; pages guard their own queries */ }
}
