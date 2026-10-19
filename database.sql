-- ---------------------------------------------------------------------------
-- Chama Management System — database schema (multi-tenant + M-Pesa billing)
--
-- Fresh install: create an empty database and import this file:
--   CREATE DATABASE IF NOT EXISTS chama_system_db CHARACTER SET utf8mb4;
--   mysql -u root chama_system_db < database.sql
--
-- Upgraded install: you do NOT need to re-import. The app applies the
-- required changes automatically on first load (see config/db.php).
--
-- NOTE: the seeded superadmin password is 'Chama@2026'.
-- Keep it in sync with SUPERADMIN_INITIAL_PASSWORD in config/app.php.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS mpesa_payments;
DROP TABLE IF EXISTS mpesa_logs;
DROP TABLE IF EXISTS subscription_payments;
DROP TABLE IF EXISTS loan_repayments;
DROP TABLE IF EXISTS welfare_payouts;
DROP TABLE IF EXISTS loans;
DROP TABLE IF EXISTS contributions;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS members;
DROP TABLE IF EXISTS chamas;

-- One row per group. All other tables carry a chama_id that points here.
CREATE TABLE chamas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    phone VARCHAR(30) NOT NULL DEFAULT '',
    billing_phone VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'M-Pesa number billed for the subscription',
    email VARCHAR(150) NOT NULL DEFAULT '',
    plan ENUM('free','pro') NOT NULL DEFAULT 'free',
    monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'what the CHAMA pays the platform',
    monthly_dues DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'what each MEMBER pays the chama per month',
    sms_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'monthly dues reminders on/off',
    sms_receipts TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'send confirmation SMS when money lands',
    sms_reminder_day TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'day of month for the reminder, 1-28',
    sms_last_reminder DATE NULL COMMENT 'last day a reminder batch was sent',
    pay_shortcode VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'group OWN Paybill/Till for member money',
    pay_passkey VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'Daraja passkey for the group shortcode',
    pay_verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'operator confirmed route ownership',
    pay_verified_at DATETIME NULL,
    sms_balance INT NOT NULL DEFAULT 0 COMMENT 'prepaid SMS units',
    is_pilot TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'on a free trial window',
    tier VARCHAR(20) NOT NULL DEFAULT 'standard' COMMENT 'pricing tier key',
    setup_fee DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'one-off onboarding fee quoted',
    setup_fee_paid TINYINT(1) NOT NULL DEFAULT 0,
    plan_expires_at DATE NULL COMMENT 'NULL = never expires',
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    suspension_reason VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'nonpayment | manual | empty',
    started_at DATE NULL,
    notes VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chamas_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE members (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chama_id INT UNSIGNED NULL,
    member_no VARCHAR(30) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL DEFAULT '',
    email VARCHAR(150) NOT NULL DEFAULT '',
    address VARCHAR(255) NOT NULL DEFAULT '',
    join_date DATE NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    PRIMARY KEY (id),
    KEY idx_members_chama (chama_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chama_id INT UNSIGNED NULL COMMENT 'NULL for the platform operator',
    member_id INT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','treasurer','member','superadmin') NOT NULL DEFAULT 'member',
    account_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_chama (chama_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE contributions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chama_id INT UNSIGNED NULL,
    member_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    contribution_date DATE NOT NULL,
    type VARCHAR(30) NOT NULL DEFAULT 'monthly',
    reference VARCHAR(100) NOT NULL DEFAULT '',
    notes VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_contributions_chama (chama_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chama_id INT UNSIGNED NULL,
    member_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    interest_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    total_payable DECIMAL(12,2) NOT NULL,
    balance DECIMAL(12,2) NOT NULL,
    purpose VARCHAR(255) NOT NULL DEFAULT '',
    application_date DATE NOT NULL,
    approved_date DATE NULL,
    status ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (id),
    KEY idx_loans_chama (chama_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loan_repayments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    reference VARCHAR(100) NOT NULL DEFAULT '',
    notes VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_loan_repayments_loan (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE welfare_payouts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chama_id INT UNSIGNED NULL,
    member_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    notes VARCHAR(255) NOT NULL DEFAULT '',
    request_date DATE NOT NULL,
    approved_date DATE NULL,
    status ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (id),
    KEY idx_welfare_chama (chama_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Subscription billing (Phase 2). One row per fee payment or trial grant.
CREATE TABLE subscription_payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chama_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    months TINYINT UNSIGNED NOT NULL DEFAULT 1,
    period_start DATE NULL,
    period_end DATE NULL,
    method ENUM('mpesa_stk','manual','bank','cash','trial') NOT NULL DEFAULT 'mpesa_stk',
    status ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
    checkout_request_id VARCHAR(120) NULL,
    merchant_request_id VARCHAR(120) NULL,
    mpesa_receipt VARCHAR(50) NULL,
    mpesa_phone VARCHAR(30) NULL,
    paid_at DATETIME NULL,
    notes VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subpay_chama (chama_id),
    KEY idx_subpay_checkout (checkout_request_id),
    KEY idx_subpay_status (status),
    UNIQUE KEY uq_subpay_receipt (mpesa_receipt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Member-facing M-Pesa payments: dues and loan repayments (Phase 2b).
CREATE TABLE mpesa_payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chama_id INT UNSIGNED NOT NULL,
    purpose ENUM('contribution','loan_repayment') NOT NULL,
    member_id INT UNSIGNED NULL,
    loan_id INT UNSIGNED NULL,
    amount DECIMAL(12,2) NOT NULL,
    phone VARCHAR(30) NOT NULL DEFAULT '',
    status ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
    checkout_request_id VARCHAR(120) NULL,
    merchant_request_id VARCHAR(120) NULL,
    shortcode_used VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'which shortcode collected this money',
    mpesa_receipt VARCHAR(50) NULL,
    result_record_id INT UNSIGNED NULL,
    notes VARCHAR(255) NOT NULL DEFAULT '',
    initiated_by INT UNSIGNED NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mpaya_chama (chama_id),
    KEY idx_mpaya_member (member_id),
    KEY idx_mpaya_checkout (checkout_request_id),
    KEY idx_mpaya_status (status),
    UNIQUE KEY uq_mpaya_receipt (mpesa_receipt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Raw trail of every Daraja request, callback and billing event.
CREATE TABLE mpesa_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    direction ENUM('in','out') NOT NULL DEFAULT 'out',
    endpoint VARCHAR(120) NOT NULL DEFAULT '',
    checkout_request_id VARCHAR(120) NULL,
    receipt_no VARCHAR(50) NULL,
    payload MEDIUMTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mpesalog_checkout (checkout_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Phase 3: SMS messaging and the audit trail
-- ---------------------------------------------------------------------------
CREATE TABLE sms_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chama_id INT UNSIGNED NULL,
    member_id INT UNSIGNED NULL,
    phone VARCHAR(30) NOT NULL DEFAULT '',
    purpose ENUM('receipt','reminder','broadcast','loan','notice') NOT NULL DEFAULT 'notice',
    message VARCHAR(480) NOT NULL DEFAULT '',
    provider VARCHAR(40) NOT NULL DEFAULT 'simulation',
    status ENUM('queued','sent','simulated','failed') NOT NULL DEFAULT 'queued',
    provider_message_id VARCHAR(120) NULL,
    error VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_smslog_chama (chama_id),
    KEY idx_smslog_member (member_id),
    KEY idx_smslog_purpose (purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chama_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    actor VARCHAR(150) NOT NULL DEFAULT '',
    action VARCHAR(80) NOT NULL DEFAULT '',
    entity_type VARCHAR(40) NOT NULL DEFAULT '',
    entity_id INT UNSIGNED NULL,
    description VARCHAR(480) NOT NULL DEFAULT '',
    meta TEXT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_chama (chama_id, created_at),
    KEY idx_audit_action (action),
    KEY idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE plans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_key VARCHAR(30) NOT NULL,
    label VARCHAR(80) NOT NULL DEFAULT '',
    monthly DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_members INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
    annual_months_pay TINYINT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'pay N months for 12',
    sort INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    description VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_plans_key (plan_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(150) NOT NULL DEFAULT '',
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_email (email, created_at),
    KEY idx_login_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- Seed: the default chama (existing single-group data lands here on upgrade)
INSERT INTO chamas (id, name, slug) VALUES (1, 'Default Chama', 'default-chama');

-- Seed: launch pricing HYPOTHESES (edit in Billing > Plans, not here)
INSERT INTO plans (plan_key, label, monthly, max_members, sort, description) VALUES
    ('micro',        'Micro - 8 to 10 active members',   150,  10, 1, 'Small table-banking and merry-go-round groups'),
    ('small',        'Small - 10 to 15 active members',  250,  15, 2, 'Growing savings groups'),
    ('community',    'Community - up to 20 members',     500,  20, 3, 'Established chamas with monthly meetings'),
    ('growth',       'Growth - up to 100 members',      1200, 100, 4, 'Large chamas with real float'),
    ('welfare_plus', 'Welfare Plus - up to 250 members', 2500, 250, 5, 'Welfare societies and alumni groups'),
    ('enterprise',   'Large organisation - quoted',     3500,   0, 6, 'From KSh 3,500/month, quoted by requirements');

-- Seed: platform operator account (initial password: Chama@2026)
INSERT INTO users (chama_id, member_id, name, email, password, role, account_status)
VALUES (NULL, NULL, 'Platform Operator', 'superadmin@chama.local',
        '$2y$12$dXaMQdCWe3mXpOQY3e4RnuLWqxu0eqIeeD964O4ym0JTGhnxNjpg2', 'superadmin', 'approved');
