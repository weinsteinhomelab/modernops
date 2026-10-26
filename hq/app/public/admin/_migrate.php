<?php
/** Idempotent schema migrations so a live database upgrades itself safely. */
require_once __DIR__ . '/_db.php';

const SCHEMA_VERSION = 12;

/** Starting pricebook for an empty Catalog: the "catalog" list in the client file. */
function catalog_defaults(): array {
    return (array) client('catalog', []);
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
    if (setting('next_quote_number') === null) set_setting('next_quote_number', (string) client('quotes.first_number', '1001'));
    if (setting('default_tax_rate') === null)  set_setting('default_tax_rate', (string) client('quotes.tax_rate', '0'));
    if (setting('default_tax_label') === null) set_setting('default_tax_label', (string) client('quotes.tax_label', 'Sales Tax'));
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

/** Phase 6: section headings among quote lines (title only; billing marks the one-time or recurring area). */
function run_phase6_migration(): void {
    try { db()->exec("ALTER TABLE quote_items ADD COLUMN IF NOT EXISTS is_section TINYINT NOT NULL DEFAULT 0 AFTER billing"); } catch (Exception $ex) {}
}

/** Phase 7: optional add-on lines (shown on the quote, not counted in totals). */
function run_phase7_migration(): void {
    try { db()->exec("ALTER TABLE quote_items ADD COLUMN IF NOT EXISTS is_optional TINYINT NOT NULL DEFAULT 0 AFTER is_section"); } catch (Exception $ex) {}
}

/** Phase 8: customer link + online acceptance on quotes. */
function run_phase8_migration(): void {
    $ddl = [
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS public_token VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS viewed_at DATETIME DEFAULT NULL",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS accepted_at DATETIME DEFAULT NULL",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS accepted_name VARCHAR(160) DEFAULT NULL",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS accepted_ip VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS accepted_ua VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS accepted_optional TEXT DEFAULT NULL",
        "CREATE UNIQUE INDEX IF NOT EXISTS quotes_public_token ON quotes (public_token)",
    ];
    foreach ($ddl as $sql) { try { db()->exec($sql); } catch (Exception $ex) {} }
}

/** Phase 9: people (admins + users), permissions, mailbox access, customer owners, approvals, activity log. */
function run_phase9_migration(): void {
    $ddl = [
        "ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS role ENUM('admin','user') NOT NULL DEFAULT 'user' AFTER password_hash",
        "ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS name VARCHAR(120) DEFAULT NULL AFTER role",
        "ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS email VARCHAR(190) DEFAULT NULL AFTER name",
        "ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS active TINYINT NOT NULL DEFAULT 1 AFTER email",
        "ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS must_change_pw TINYINT NOT NULL DEFAULT 0 AFTER active",
        "ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS last_login_at DATETIME DEFAULT NULL AFTER must_change_pw",
        "CREATE TABLE IF NOT EXISTS user_permissions (user_id INT NOT NULL, perm VARCHAR(40) NOT NULL, PRIMARY KEY (user_id, perm)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS user_mailboxes (user_id INT NOT NULL, mailbox VARCHAR(190) NOT NULL, PRIMARY KEY (user_id, mailbox)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "ALTER TABLE businesses ADD COLUMN IF NOT EXISTS assigned_to INT DEFAULT NULL AFTER notes",
        "ALTER TABLE businesses ADD INDEX IF NOT EXISTS businesses_assigned (assigned_to)",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS created_by INT DEFAULT NULL AFTER business_id",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS approval_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' AFTER status",
        "ALTER TABLE quotes ADD COLUMN IF NOT EXISTS approval_hash VARCHAR(64) DEFAULT NULL AFTER approval_status",
        "CREATE TABLE IF NOT EXISTS approvals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type ENUM('catalog_add','catalog_edit','quote_pricing') NOT NULL,
            status ENUM('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
            requested_by INT DEFAULT NULL,
            quote_id INT DEFAULT NULL,
            catalog_item_id INT DEFAULT NULL,
            summary VARCHAR(500) NOT NULL DEFAULT '',
            payload TEXT,
            decided_by INT DEFAULT NULL,
            decided_at DATETIME DEFAULT NULL,
            decision_note VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (status), INDEX (quote_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS activity (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            action VARCHAR(60) NOT NULL,
            entity VARCHAR(40) DEFAULT NULL,
            entity_id INT DEFAULT NULL,
            detail VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (created_at), INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($ddl as $sql) { try { db()->exec($sql); } catch (Exception $ex) {} }
    // Everyone who could log in before this was the owner: make them admins and owners of existing records.
    try {
        db()->exec("UPDATE admin_users SET role = 'admin'");   // runs once, before any users exist
        $first = (int) db()->query("SELECT MIN(id) FROM admin_users WHERE role = 'admin'")->fetchColumn();
        if ($first) {
            db()->prepare('UPDATE quotes SET created_by = ? WHERE created_by IS NULL')->execute([$first]);
            db()->prepare('UPDATE businesses SET assigned_to = ? WHERE assigned_to IS NULL')->execute([$first]);
        }
    } catch (Exception $ex) {}
}

/** Phase 10: one margin for the whole quote (pricebook prices are our cost). */
function run_phase10_migration(): void {
    try { db()->exec("ALTER TABLE quotes ADD COLUMN IF NOT EXISTS margin DECIMAL(8,2) DEFAULT NULL AFTER tax_label"); } catch (Exception $ex) {}
}

/** Phase 11: pictures on a customer's websites, and a locally hosted preview beside each demo. */
function run_phase11_migration(): void {
    $ddl = [
        "ALTER TABLE sites ADD COLUMN IF NOT EXISTS local_url VARCHAR(500) DEFAULT NULL AFTER url",
        "ALTER TABLE sites MODIFY url VARCHAR(500) NOT NULL DEFAULT ''",
        "CREATE TABLE IF NOT EXISTS site_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            site_id INT DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            mime VARCHAR(100) NOT NULL,
            size INT NOT NULL DEFAULT 0,
            width INT DEFAULT NULL,
            height INT DEFAULT NULL,
            data LONGBLOB NOT NULL,
            created_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (business_id), INDEX (site_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($ddl as $sql) { try { db()->exec($sql); } catch (Exception $ex) {} }
}

/** Phase 12: the customer's own website on the business record. */
function run_phase12_migration(): void {
    try { db()->exec("ALTER TABLE businesses ADD COLUMN IF NOT EXISTS website VARCHAR(500) DEFAULT NULL AFTER main_email"); } catch (Exception $ex) {}
    // Notes that are nothing but a web address were the only place to put it before: move them over.
    try {
        foreach (db()->query("SELECT id, notes FROM businesses WHERE (website IS NULL OR website = '') AND notes IS NOT NULL AND notes <> ''")->fetchAll() as $r) {
            $u = trim((string) $r['notes']);
            if (!preg_match('#^(https?://)?(www\.)?[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}(/\S*)?$#i', $u)) continue;
            if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
            if (filter_var($u, FILTER_VALIDATE_URL)) db()->prepare("UPDATE businesses SET website = ?, notes = '' WHERE id = ?")->execute([$u, $r['id']]);
        }
    } catch (Exception $ex) {}
}

function maybe_migrate(): void {
    try {
        $v = (int) (setting('schema_version', '1'));
        if ($v >= SCHEMA_VERSION) return;
        if ($v < 2) run_phase2_migration();
        if ($v < 3) run_phase3_migration();
        if ($v < 4) run_phase4_migration();
        if ($v < 5) run_phase5_migration();
        if ($v < 6) run_phase6_migration();
        if ($v < 7) run_phase7_migration();
        if ($v < 8) run_phase8_migration();
        if ($v < 9) run_phase9_migration();
        if ($v < 10) run_phase10_migration();
        if ($v < 11) run_phase11_migration();
        if ($v < 12) run_phase12_migration();
        set_setting('schema_version', (string) SCHEMA_VERSION);
    } catch (Exception $ex) {}
}
