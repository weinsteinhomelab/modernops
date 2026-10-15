-- Modern Ops HQ — database schema (fresh installs). Existing databases are
-- upgraded by _migrate.php instead.

CREATE TABLE IF NOT EXISTS admin_users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','user') NOT NULL DEFAULT 'user',
  name          VARCHAR(120) DEFAULT NULL,
  email         VARCHAR(190) DEFAULT NULL,
  active        TINYINT NOT NULL DEFAULT 1,
  must_change_pw TINYINT NOT NULL DEFAULT 0,
  last_login_at DATETIME DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS businesses (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(190) NOT NULL,
  vertical   VARCHAR(120) DEFAULT NULL,
  main_phone VARCHAR(60)  DEFAULT NULL,
  main_email VARCHAR(190) DEFAULT NULL,
  address    VARCHAR(255) DEFAULT NULL,
  address2   VARCHAR(255) DEFAULT NULL,
  city       VARCHAR(120) DEFAULT NULL,
  state      VARCHAR(80)  DEFAULT NULL,
  country    VARCHAR(80)  DEFAULT NULL,
  zip        VARCHAR(30)  DEFAULT NULL,
  notes      TEXT DEFAULT NULL,
  assigned_to INT DEFAULT NULL,
  legacy_customer_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contacts (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  first_name  VARCHAR(120) DEFAULT NULL,
  last_name   VARCHAR(120) DEFAULT NULL,
  email       VARCHAR(190) DEFAULT NULL,
  phone       VARCHAR(60)  DEFAULT NULL,
  title       VARCHAR(120) DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS touchpoints (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  tp_date     DATE NOT NULL,
  tp_type     ENUM('Call','Email','Text','Voicemail','Meeting','Other') NOT NULL DEFAULT 'Call',
  note        TEXT DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (business_id), INDEX (tp_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS requests (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT DEFAULT NULL,
  name        VARCHAR(160) NOT NULL,
  business    VARCHAR(160) DEFAULT NULL,
  email       VARCHAR(190) DEFAULT NULL,
  phone       VARCHAR(60)  DEFAULT NULL,
  type        ENUM('quote','service','general') NOT NULL DEFAULT 'general',
  message     TEXT DEFAULT NULL,
  status      ENUM('new','contacted','quoted','won','closed') NOT NULL DEFAULT 'new',
  source      VARCHAR(40) NOT NULL DEFAULT 'website',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (status), INDEX (created_at), INDEX (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quotes (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  business_id  INT NOT NULL,
  created_by   INT DEFAULT NULL,
  contact_id   INT DEFAULT NULL,
  quote_number VARCHAR(40) NOT NULL UNIQUE,
  issue_date   DATE NOT NULL,
  valid_until  DATE DEFAULT NULL,
  status       ENUM('draft','sent','accepted','declined') NOT NULL DEFAULT 'draft',
  approval_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
  approval_hash VARCHAR(64) DEFAULT NULL,
  tax_rate     DECIMAL(6,3) NOT NULL DEFAULT 0,
  tax_label    VARCHAR(80) NOT NULL DEFAULT 'Sales Tax',
  discount     DECIMAL(10,2) NOT NULL DEFAULT 0,
  scope_of_work TEXT DEFAULT NULL,
  notes        TEXT DEFAULT NULL,
  terms_included TINYINT NOT NULL DEFAULT 1,
  public_token VARCHAR(64) DEFAULT NULL UNIQUE,
  viewed_at    DATETIME DEFAULT NULL,
  accepted_at  DATETIME DEFAULT NULL,
  accepted_name VARCHAR(160) DEFAULT NULL,
  accepted_ip  VARCHAR(64) DEFAULT NULL,
  accepted_ua  VARCHAR(255) DEFAULT NULL,
  accepted_optional TEXT DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (business_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quote_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  quote_id    INT NOT NULL,
  title       VARCHAR(190) NOT NULL DEFAULT '',
  description VARCHAR(255) DEFAULT NULL,
  quantity    DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price  DECIMAL(10,2) NOT NULL DEFAULT 0,
  unit_cost   DECIMAL(10,2) DEFAULT NULL,
  margin      DECIMAL(8,2) DEFAULT NULL,
  billing     ENUM('one_time','monthly','quarterly','semiannual','annual') NOT NULL DEFAULT 'one_time',
  is_section  TINYINT NOT NULL DEFAULT 0,
  is_optional TINYINT NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  INDEX (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS catalog_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(190) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  unit_price  DECIMAL(10,2) NOT NULL DEFAULT 0,
  billing     ENUM('one_time','monthly','quarterly','semiannual','annual') NOT NULL DEFAULT 'one_time',
  active      TINYINT NOT NULL DEFAULT 1,
  sort_order  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(80) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(64) NOT NULL,
  username   VARCHAR(80) DEFAULT NULL,
  ok         TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip), INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sites (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  kind        ENUM('demo','live') NOT NULL DEFAULT 'demo',
  label       VARCHAR(190) DEFAULT NULL,
  url         VARCHAR(500) NOT NULL,
  notes       TEXT DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_permissions (
  user_id INT NOT NULL,
  perm    VARCHAR(40) NOT NULL,
  PRIMARY KEY (user_id, perm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_mailboxes (
  user_id INT NOT NULL,
  mailbox VARCHAR(190) NOT NULL,
  PRIMARY KEY (user_id, mailbox)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS approvals (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) DEFAULT NULL,
  entity_id INT DEFAULT NULL,
  detail VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (created_at), INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
