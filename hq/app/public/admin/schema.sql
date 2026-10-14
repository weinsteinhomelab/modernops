-- Modern Ops HQ — database schema
-- Run automatically by setup.php (or import via phpMyAdmin).

CREATE TABLE IF NOT EXISTS admin_users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(160) NOT NULL,
  business   VARCHAR(160) DEFAULT NULL,
  email      VARCHAR(190) DEFAULT NULL,
  phone      VARCHAR(60)  DEFAULT NULL,
  address    VARCHAR(255) DEFAULT NULL,
  notes      TEXT         DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (name),
  INDEX (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS requests (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT DEFAULT NULL,
  name        VARCHAR(160) NOT NULL,
  business    VARCHAR(160) DEFAULT NULL,
  email       VARCHAR(190) DEFAULT NULL,
  phone       VARCHAR(60)  DEFAULT NULL,
  type        ENUM('quote','service','general') NOT NULL DEFAULT 'general',
  message     TEXT DEFAULT NULL,
  status      ENUM('new','contacted','quoted','won','closed') NOT NULL DEFAULT 'new',
  source      VARCHAR(40) NOT NULL DEFAULT 'website',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (status),
  INDEX (created_at),
  INDEX (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quotes (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  customer_id  INT NOT NULL,
  quote_number VARCHAR(40) NOT NULL UNIQUE,
  issue_date   DATE NOT NULL,
  valid_until  DATE DEFAULT NULL,
  status       ENUM('draft','sent','accepted','declined') NOT NULL DEFAULT 'draft',
  tax_rate     DECIMAL(6,3) NOT NULL DEFAULT 0,
  tax_label    VARCHAR(80) NOT NULL DEFAULT 'Sales Tax',
  discount     DECIMAL(10,2) NOT NULL DEFAULT 0,
  notes        TEXT DEFAULT NULL,
  terms_included TINYINT NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (customer_id),
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS quote_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  quote_id    INT NOT NULL,
  title       VARCHAR(190) NOT NULL DEFAULT '',
  description VARCHAR(255) DEFAULT NULL,
  quantity    DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price  DECIMAL(10,2) NOT NULL DEFAULT 0,
  billing     ENUM('one_time','monthly') NOT NULL DEFAULT 'one_time',
  sort_order  INT NOT NULL DEFAULT 0,
  INDEX (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS catalog_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(190) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  unit_price  DECIMAL(10,2) NOT NULL DEFAULT 0,
  billing     ENUM('one_time','monthly') NOT NULL DEFAULT 'one_time',
  active      TINYINT NOT NULL DEFAULT 1,
  sort_order  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(80) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Records login attempts for brute-force throttling
CREATE TABLE IF NOT EXISTS login_attempts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(64) NOT NULL,
  username   VARCHAR(80) DEFAULT NULL,
  ok         TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
