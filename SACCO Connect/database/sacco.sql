-- ============================================================
-- MBARARA CITY MUKAMA NUWAMANYA PEOPLE'S SACCO — Database Schema
-- Import via phpMyAdmin (XAMPP/WAMP) or: mysql -u root -p < sacco.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS sacco_db;
USE sacco_db;

-- ------------------------------------------------------------
-- System users (staff accounts: Administrator, Manager, Cashier, Loan Officer)
-- ------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('administrator','manager','cashier','loan_officer') NOT NULL DEFAULT 'cashier',
    email VARCHAR(100),
    phone VARCHAR(30),
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    password_changed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Members
-- ------------------------------------------------------------
CREATE TABLE members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_no VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    gender ENUM('male','female','other'),
    date_of_birth DATE,
    national_id VARCHAR(40),
    phone VARCHAR(30),
    email VARCHAR(100),
    address VARCHAR(255),
    next_of_kin_name VARCHAR(120),
    next_of_kin_phone VARCHAR(30),
    photo_path VARCHAR(255),
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
    activation_fee_paid BOOLEAN DEFAULT FALSE,
    activation_fee_amount DECIMAL(14,2) DEFAULT 0.00,
    joined_date DATE NOT NULL DEFAULT (CURRENT_DATE),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Savings account balances (one row per member, kept in sync with transactions)
-- ------------------------------------------------------------
CREATE TABLE savings_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    activation_fee_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Savings transactions (deposits & withdrawals)
-- synced = 0 means recorded offline and not yet pushed to a central server
-- ------------------------------------------------------------
CREATE TABLE savings_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    type ENUM('deposit','withdrawal','activation_fee','mobile_money_deposit') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    recorded_by INT NOT NULL,
    synced TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(255),
    reference_number VARCHAR(50),
    mobile_money_provider ENUM('airtel','mtn'),
    mobile_money_number VARCHAR(30),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Shares
-- ------------------------------------------------------------
CREATE TABLE shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    shares_count INT NOT NULL DEFAULT 0,
    share_value DECIMAL(10,2) NOT NULL DEFAULT 20000.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE share_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    shares_purchased INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    recorded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Loans
-- ------------------------------------------------------------
CREATE TABLE loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    principal DECIMAL(14,2) NOT NULL,
    interest_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    term_months INT NOT NULL DEFAULT 6,
    purpose VARCHAR(255),
    status ENUM('pending','approved','rejected','active','completed') NOT NULL DEFAULT 'pending',
    applied_date DATE NOT NULL DEFAULT (CURRENT_DATE),
    approved_date DATE NULL,
    approved_by INT NULL,
    outstanding_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE loan_repayments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    recorded_by INT NOT NULL,
    synced TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Audit log — full accountability trail
-- ------------------------------------------------------------
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Notifications log
-- ------------------------------------------------------------
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    type ENUM('email','whatsapp','sms_airtel','sms_mtn') NOT NULL,
    recipient VARCHAR(100) NOT NULL,
    subject VARCHAR(255),
    message TEXT NOT NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    cost DECIMAL(10,2) DEFAULT 200.00,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Mobile money deposits (pending admin verification)
-- ------------------------------------------------------------
CREATE TABLE mobile_money_deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    provider ENUM('airtel','mtn') NOT NULL,
    sender_number VARCHAR(30) NOT NULL,
    reference_number VARCHAR(50) NOT NULL,
    transaction_message TEXT,
    status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    verified_by INT NULL,
    verified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- System settings (key/value store for things like SACCO name, interest defaults)
-- ------------------------------------------------------------
CREATE TABLE settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255)
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
('sacco_name', 'MBARARA CITY MUKAMA NUWAMANYA PEOPLE\'S SACCO'),
('default_interest_rate', '10.00'),
('share_value', '20000.00'),
('currency', 'UGX'),
('activation_fee', '15000.00'),
('min_deposit', '1000.00'),
('min_withdrawal', '5000.00'),
('notification_cost', '200.00'),
('absa_account_number', '6010003286'),
('absa_account_name', 'MBARARA CITY MUKAMA NUWAMANYA PEOPLE\'S SACCO'),
('monthly_fee_amount', '50000.00'),
('monthly_fee_due_day', '1');

-- ------------------------------------------------------------
-- Monthly payment tracking
-- ------------------------------------------------------------
CREATE TABLE monthly_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_month DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    paid_by INT NOT NULL,
    payment_reference VARCHAR(100),
    status ENUM('pending','paid','overdue') NOT NULL DEFAULT 'pending',
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (paid_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SACCO Statements
-- ------------------------------------------------------------
CREATE TABLE statements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    statement_type ENUM('monthly','quarterly','annual','custom') NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    generated_by INT NOT NULL,
    file_path VARCHAR(255),
    status ENUM('generated','sent') NOT NULL DEFAULT 'generated',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Seed an initial administrator account
-- Default password: "McmnpSacco@2024!" — CHANGE THIS immediately after first login.
-- Hash generated with: password_hash('McmnpSacco@2024!', PASSWORD_DEFAULT)
-- ------------------------------------------------------------
INSERT INTO users (username, password_hash, full_name, role, email, password_changed)
VALUES ('admin', '$2y$10$K8V2vQ9xJ7mN5pL3rT6wY.8zA1bC4dE7fG9hI2jK5lM8nO1pQ4rS', 'System Administrator', 'administrator', 'admin@mbararacitysacco.ug', FALSE);