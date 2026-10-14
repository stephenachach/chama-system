-- ---------------------------------------------------------------------------
-- Chama Management System — database schema (multi-tenant)
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
    email VARCHAR(150) NOT NULL DEFAULT '',
    plan ENUM('free','pro') NOT NULL DEFAULT 'free',
    monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
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

SET FOREIGN_KEY_CHECKS = 1;

-- Seed: the default chama (existing single-group data lands here on upgrade)
INSERT INTO chamas (id, name, slug) VALUES (1, 'Default Chama', 'default-chama');

-- Seed: platform operator account (initial password: Chama@2026)
INSERT INTO users (chama_id, member_id, name, email, password, role, account_status)
VALUES (NULL, NULL, 'Platform Operator', 'superadmin@chama.local',
        '$2y$12$dXaMQdCWe3mXpOQY3e4RnuLWqxu0eqIeeD964O4ym0JTGhnxNjpg2', 'superadmin', 'approved');
