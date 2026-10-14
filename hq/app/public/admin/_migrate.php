<?php
/** Idempotent schema migrations so a live database upgrades itself safely. */
require_once __DIR__ . '/_db.php';

const SCHEMA_VERSION = 5;

/** The standard Modern Ops service catalog (from the quote template). */
function catalog_defaults(): array {
    return [
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
        if ((int) db()->query('SELECT COUNT(*) FROM catalog_items')->fetchColumn() > 0) return;
        $st = db()->prepare('INSERT INTO catalog_items (title, description, unit_price, billing, sort_order) VALUES (?,?,?,?,?)');
        $i = 0;
        foreach (catalog_defaults() as $row) $st->execute([$row[0], $row[1], $row[2], $row[3], $i++]);
    } catch (Exception $ex) {}
}

function seed_defaults(): void {
    if (setting('next_quote_number') === null) set_setting('next_quote_number', '1001');
    if (setting('default_tax_rate') === null)  set_setting('default_tax_rate', '0');
    if (setting('default_tax_label') === null) set_setting('default_tax_label', 'Sales Tax');
}

function run_phase2_migration(): void {
    $ddl = [
        "CREATE TABLE IF NOT EXISTS catalog_items (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(190) NOT NULL, description VARCHAR(255) DEFAULT NULL, unit_price DECIMAL(10,2) NOT NULL DEFAULT 0, billing ENUM('one_time','monthly') NOT NULL DEFAULT 'one_time', active TINYINT NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "ALTER TABLE quote_items ADD COLUMN IF NOT EXISTS title VARCHAR(190) NOT NULL DEFAULT '' AFTER quote_id",
        "ALTER TABLE quote_items ADD COLUMN IF NOT EXISTS billing ENUM('one_time','monthly') NOT NULL DEFAULT 'one_time' AFTER unit_price",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS tax_label VARCHAR(80) NOT NULL DEFAULT 'Sales Tax' AFTER tax_rate",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS terms_included TINYINT NOT NULL DEFAULT 1 AFTER notes",
    ];
    foreach ($ddl as $sql) { try { db()->exec($sql); } catch (Exception $ex) {} }
    seed_catalog();
    seed_defaults();
}

/** Phase 3: businesses + contacts + touch points; link requests/quotes; scope of work. */
function run_phase3_migration(): void {
    $ddl = [
        "CREATE TABLE IF NOT EXISTS businesses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            vertical VARCHAR(120) DEFAULT NULL,
            main_phone VARCHAR(60) DEFAULT NULL,
            main_email VARCHAR(190) DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            address2 VARCHAR(255) DEFAULT NULL,
            city VARCHAR(120) DEFAULT NULL,
            state VARCHAR(80) DEFAULT NULL,
            country VARCHAR(80) DEFAULT NULL,
            zip VARCHAR(30) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            legacy_customer_id INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (name)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS contacts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            first_name VARCHAR(120) DEFAULT NULL,
            last_name VARCHAR(120) DEFAULT NULL,
            email VARCHAR(190) DEFAULT NULL,
            phone VARCHAR(60) DEFAULT NULL,
            title VARCHAR(120) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (business_id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS touchpoints (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            tp_date DATE NOT NULL,
            tp_type ENUM('Call','Email','Text','Voicemail','Meeting','Other') NOT NULL DEFAULT 'Call',
            note TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (business_id), INDEX (tp_date)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "ALTER TABLE requests ADD COLUMN IF NOT EXISTS business_id INT DEFAULT NULL",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS business_id INT DEFAULT NULL",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS contact_id INT DEFAULT NULL",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS scope_of_work TEXT DEFAULT NULL",
        // old NOT NULL customer_id would block new business-based inserts
        "ALTER TABLE quotes MODIFY customer_id INT NULL",
        "ALTER TABLE requests MODIFY customer_id INT NULL",
    ];
    foreach ($ddl as $sql) { try { db()->exec($sql); } catch (Exception $ex) {} }

    // Migrate legacy `customers` rows into businesses + a primary contact (once).
    try {
        $hasBiz = (int) db()->query('SELECT COUNT(*) FROM businesses')->fetchColumn();
        $custExists = db()->query("SHOW TABLES LIKE 'customers'")->fetch();
        if ($hasBiz === 0 && $custExists) {
            db()->exec("INSERT INTO businesses (name, main_email, main_phone, address, notes, legacy_customer_id, created_at)
                        SELECT COALESCE(NULLIF(TRIM(business),''), name, 'Customer'), email, phone, address, notes, id, created_at FROM customers");
            db()->exec("INSERT INTO contacts (business_id, first_name, last_name, email, phone)
                        SELECT b.id, SUBSTRING_INDEX(c.name,' ',1),
                               NULLIF(TRIM(SUBSTRING(c.name, LENGTH(SUBSTRING_INDEX(c.name,' ',1))+1)),''),
                               c.email, c.phone
                        FROM customers c JOIN businesses b ON b.legacy_customer_id=c.id
                        WHERE c.name IS NOT NULL AND TRIM(c.name) <> ''");
            db()->exec("UPDATE requests r JOIN businesses b ON b.legacy_customer_id=r.customer_id SET r.business_id=b.id");
            db()->exec("UPDATE quotes q JOIN businesses b ON b.legacy_customer_id=q.customer_id SET q.business_id=b.id");
        }
    } catch (Exception $ex) {}
}

/** Phase 4: demo + live websites tied to a business. */
function run_phase4_migration(): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS sites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            kind ENUM('demo','live') NOT NULL DEFAULT 'demo',
            label VARCHAR(190) DEFAULT NULL,
            url VARCHAR(500) NOT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (business_id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $ex) {}
}

/** Phase 5: quarterly, semi-annual and annual billing periods; internal cost + margin per quote line. */
function run_phase5_migration(): void {
    $enum = "ENUM('one_time','monthly','quarterly','semiannual','annual') NOT NULL DEFAULT 'one_time'";
    foreach (['catalog_items', 'quote_items'] as $t) {
        try { db()->exec("ALTER TABLE $t MODIFY billing $enum"); } catch (Exception $ex) {}
    }
    foreach (["ALTER TABLE quote_items ADD COLUMN IF NOT EXISTS unit_cost DECIMAL(10,2) DEFAULT NULL AFTER unit_price",
              "ALTER TABLE quote_items ADD COLUMN IF NOT EXISTS margin DECIMAL(8,2) DEFAULT NULL AFTER unit_cost"] as $sql) {
        try { db()->exec($sql); } catch (Exception $ex) {}
    }
}

function maybe_migrate(): void {
    try {
        $v = (int) (setting('schema_version', '1'));
        if ($v >= SCHEMA_VERSION) return;
        if ($v < 2) run_phase2_migration();
        if ($v < 3) run_phase3_migration();
        if ($v < 4) run_phase4_migration();
        if ($v < 5) run_phase5_migration();
        set_setting('schema_version', (string) SCHEMA_VERSION);
    } catch (Exception $ex) {}
}
