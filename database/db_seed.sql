-- Kratos Invoice: first-deploy database seed (MySQL 8, utf8mb4).
-- Money = whole IDR (BIGINT). Qty/mult = DECIMAL(10,3).
-- Document numbers: PREFIX-YYMMNNN (e.g. INV-2610001), monthly sequence.
-- Idempotent: safe to run more than once (IF NOT EXISTS / INSERT IGNORE).
CREATE DATABASE IF NOT EXISTS kratos_invoice CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- NOTE: no USE statement here; the app selects the database from DB_NAME.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS company_settings (
  id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  company_name VARCHAR(190) NOT NULL DEFAULT 'My Company',
  address TEXT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(60) NULL,
  tax_id VARCHAR(60) NULL,
  logo_path VARCHAR(255) NULL,
  quote_validity_days INT NOT NULL DEFAULT 14,
  invoice_payment_days INT NOT NULL DEFAULT 14,
  default_notes TEXT NULL,
  default_terms TEXT NULL,
  quote_prefix VARCHAR(20) NOT NULL DEFAULT 'QT',
  invoice_prefix VARCHAR(20) NOT NULL DEFAULT 'INV',
  CONSTRAINT chk_settings_id CHECK (id = 1)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tax_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  label VARCHAR(120) NOT NULL,
  rate DECIMAL(7,4) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT chk_tax_rate CHECK (rate >= 0 AND rate <= 100)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bank_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bank_name VARCHAR(120) NOT NULL,
  account_number VARCHAR(60) NOT NULL,
  holder VARCHAR(150) NOT NULL,
  branch VARCHAR(120) NULL,
  note VARCHAR(255) NULL,
  is_default_doc TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bank_active (active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  contact_person VARCHAR(150) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(60) NULL,
  billing_address TEXT NULL,
  tax_id VARCHAR(60) NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cust_name (name),
  INDEX idx_cust_arch (archived)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  description TEXT NULL,
  unit_label VARCHAR(40) NOT NULL DEFAULT 'pcs',
  unit_price BIGINT NOT NULL DEFAULT 0,
  tax_rate_id INT UNSIGNED NULL,
  mult_enabled_default TINYINT(1) NOT NULL DEFAULT 1,
  mult_label VARCHAR(40) NULL DEFAULT 'days',
  mult_value DECIMAL(10,3) NULL DEFAULT 1.000,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_tax FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(id) ON DELETE SET NULL,
  CONSTRAINT chk_prod_price CHECK (unit_price >= 0),
  INDEX idx_prod_active (active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_counters (
  doc_type VARCHAR(10) NOT NULL,
  year INT NOT NULL,
  `month` TINYINT NOT NULL DEFAULT 0,
  last_no INT NOT NULL DEFAULT 0,
  PRIMARY KEY (doc_type, year, `month`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quotations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(40) NULL UNIQUE,
  status ENUM('draft','issued','accepted','cancelled') NOT NULL DEFAULT 'draft',
  customer_id INT UNSIGNED NULL,
  customer_snapshot JSON NULL,
  company_snapshot JSON NULL,
  doc_date DATE NOT NULL,
  expiry_date DATE NULL,
  reference VARCHAR(190) NULL,
  subtotal BIGINT NOT NULL DEFAULT 0,
  discount_type ENUM('none','fixed','percent') NOT NULL DEFAULT 'none',
  discount_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_amount BIGINT NOT NULL DEFAULT 0,
  tax_total BIGINT NOT NULL DEFAULT 0,
  tax_breakdown JSON NULL,
  total BIGINT NOT NULL DEFAULT 0,
  dp_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  dp_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  dp_amount BIGINT NOT NULL DEFAULT 0,
  dp_due_date DATE NULL,
  notes TEXT NULL,
  terms TEXT NULL,
  converted_invoice_id INT UNSIGNED NULL UNIQUE,
  cancel_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_q_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  INDEX idx_q_status (status), INDEX idx_q_date (doc_date), INDEX idx_q_num (number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quotation_sections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quotation_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_qs_q FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  INDEX idx_qs_q (quotation_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quotation_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  description TEXT NOT NULL,
  quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_label VARCHAR(40) NOT NULL DEFAULT 'pcs',
  unit_price BIGINT NOT NULL DEFAULT 0,
  mult_enabled TINYINT(1) NOT NULL DEFAULT 0,
  mult_label VARCHAR(40) NULL,
  mult_value DECIMAL(10,3) NULL,
  tax_rate_id INT UNSIGNED NULL,
  tax_rate_snapshot DECIMAL(7,4) NOT NULL DEFAULT 0,
  waived TINYINT(1) NOT NULL DEFAULT 0,
  price_mode ENUM('normal','waived','compliment','included') NOT NULL DEFAULT 'normal',
  line_amount BIGINT NOT NULL DEFAULT 0,
  tax_amount BIGINT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_ql_sec FOREIGN KEY (section_id) REFERENCES quotation_sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_ql_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT chk_ql_qty CHECK (quantity > 0),
  CONSTRAINT chk_ql_price CHECK (unit_price >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quotation_banks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quotation_id INT UNSIGNED NOT NULL,
  bank_id INT UNSIGNED NULL,
  snapshot JSON NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_qb_q FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  INDEX idx_qb_q (quotation_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(40) NULL UNIQUE,
  status ENUM('draft','issued','cancelled') NOT NULL DEFAULT 'draft',
  customer_id INT UNSIGNED NULL,
  customer_snapshot JSON NULL,
  company_snapshot JSON NULL,
  doc_date DATE NOT NULL,
  due_date DATE NULL,
  reference VARCHAR(190) NULL,
  source_quotation_id INT UNSIGNED NULL UNIQUE,
  subtotal BIGINT NOT NULL DEFAULT 0,
  discount_type ENUM('none','fixed','percent') NOT NULL DEFAULT 'none',
  discount_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_amount BIGINT NOT NULL DEFAULT 0,
  tax_total BIGINT NOT NULL DEFAULT 0,
  tax_breakdown JSON NULL,
  total BIGINT NOT NULL DEFAULT 0,
  dp_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  dp_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  dp_amount BIGINT NOT NULL DEFAULT 0,
  dp_due_date DATE NULL,
  notes TEXT NULL,
  terms TEXT NULL,
  show_signature TINYINT(1) NOT NULL DEFAULT 0,
  cancel_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_i_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  INDEX idx_i_status (status), INDEX idx_i_date (doc_date), INDEX idx_i_num (number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_sections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_is_i FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  INDEX idx_is_i (invoice_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  description TEXT NOT NULL,
  quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_label VARCHAR(40) NOT NULL DEFAULT 'pcs',
  unit_price BIGINT NOT NULL DEFAULT 0,
  mult_enabled TINYINT(1) NOT NULL DEFAULT 0,
  mult_label VARCHAR(40) NULL,
  mult_value DECIMAL(10,3) NULL,
  tax_rate_id INT UNSIGNED NULL,
  tax_rate_snapshot DECIMAL(7,4) NOT NULL DEFAULT 0,
  waived TINYINT(1) NOT NULL DEFAULT 0,
  price_mode ENUM('normal','waived','compliment','included') NOT NULL DEFAULT 'normal',
  line_amount BIGINT NOT NULL DEFAULT 0,
  tax_amount BIGINT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_il_sec FOREIGN KEY (section_id) REFERENCES invoice_sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_il_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT chk_il_qty CHECK (quantity > 0),
  CONSTRAINT chk_il_price CHECK (unit_price >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_banks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  bank_id INT UNSIGNED NULL,
  snapshot JSON NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_ib_i FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  INDEX idx_ib_i (invoice_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  amount BIGINT NOT NULL,
  pay_date DATE NOT NULL,
  method VARCHAR(80) NOT NULL DEFAULT 'Transfer',
  purpose ENUM('dp','balance','other') NOT NULL DEFAULT 'balance',
  reference VARCHAR(120) NULL,
  note VARCHAR(255) NULL,
  voided_at TIMESTAMP NULL,
  void_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT chk_pay_amt CHECK (amount > 0),
  INDEX idx_pay_inv (invoice_id), INDEX idx_pay_date (pay_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(30) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  action VARCHAR(60) NOT NULL,
  actor_id INT UNSIGNED NULL,
  detail TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit (entity_type, entity_id)
) ENGINE=InnoDB;

-- Seed: settings row + configurable tax rates (all optional; 0 = no tax)
INSERT IGNORE INTO company_settings (id) VALUES (1);
INSERT IGNORE INTO tax_rates (code, label, rate, active) VALUES
  ('NO-TAX', 'No tax', 0, 1),
  ('PPN-12', 'PPN 12%', 12, 1),
  ('PPN-11', 'PPN 11% (effective/legacy)', 11, 1),
  ('PPH23-2', 'PPh 23 2%', 2, 1);
