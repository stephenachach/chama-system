<?php
require_once __DIR__ . '/app.php';

// XAMPP default settings. Edit if your MySQL username/password is different.
$host = 'localhost';
$dbname = 'chama_system_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die('Database connection failed. Check config/db.php and import database.sql. Error: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Automatic schema migrations.
// These run on every request and are safe to repeat: they only create missing
// tables/columns, so an older single-chama installation upgrades itself into
// the multi-chama structure the first time it is opened.
// ---------------------------------------------------------------------------
function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function migrate_schema(PDO $pdo): void
{
    $tenantTables = ['users', 'members', 'contributions', 'loans', 'welfare_payouts'];
    $usersExist = column_exists($pdo, 'users', 'id');

    // 1. chamas table (multi-tenancy anchor).
    if (!column_exists($pdo, 'chamas', 'id')) {
        $pdo->exec("CREATE TABLE chamas (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // 2. Make sure the users role allows 'superadmin'.
    if ($usersExist) {
        $stmt = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'");
        $roleType = (string)$stmt->fetchColumn();
        if ($roleType !== '' && strpos($roleType, 'superadmin') === false) {
            $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','treasurer','member','superadmin') NOT NULL DEFAULT 'member'");
        }
    }

    // 3. Add chama_id to every tenant table that exists.
    foreach ($tenantTables as $table) {
        if (column_exists($pdo, $table, 'id') && !column_exists($pdo, $table, 'chama_id')) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN chama_id INT UNSIGNED NULL AFTER id");
        }
    }

    // 4. Index the new columns (ignore duplicate-index errors).
    foreach ($tenantTables as $table) {
        if (column_exists($pdo, $table, 'chama_id')) {
            try {
                $pdo->exec("CREATE INDEX idx_{$table}_chama ON {$table} (chama_id)");
            } catch (Throwable $ignored) {
            }
        }
    }

    // 5. Seed the default chama so existing data keeps working.
    $chamaCount = (int)$pdo->query('SELECT COUNT(*) FROM chamas')->fetchColumn();
    if ($chamaCount === 0) {
        $pdo->prepare('INSERT INTO chamas (name, slug) VALUES (?, ?)')
            ->execute([DEFAULT_CHAMA_NAME, 'default-chama']);
    }
    $defaultChamaId = (int)$pdo->query('SELECT id FROM chamas ORDER BY id ASC LIMIT 1')->fetchColumn();

    // 6. Backfill existing rows into the default chama.
    foreach ($tenantTables as $table) {
        if (column_exists($pdo, $table, 'chama_id')) {
            $pdo->prepare("UPDATE {$table} SET chama_id = ? WHERE chama_id IS NULL")->execute([$defaultChamaId]);
        }
    }

    // 7. Seed the platform operator account on first run.
    if ($usersExist && column_exists($pdo, 'users', 'chama_id')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'superadmin'");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $stmt = $pdo->prepare(
                "INSERT INTO users (chama_id, member_id, name, email, password, role, account_status)
                 VALUES (NULL, NULL, 'Platform Operator', ?, ?, 'superadmin', 'approved')"
            );
            $stmt->execute([SUPERADMIN_EMAIL, password_hash(SUPERADMIN_INITIAL_PASSWORD, PASSWORD_DEFAULT)]);
        }
    }
}

// ---------------------------------------------------------------------------
// Phase 2: subscription billing + M-Pesa tables (also runs as an upgrade).
// ---------------------------------------------------------------------------
function migrate_billing_schema(PDO $pdo): void
{
    if (!column_exists($pdo, 'chamas', 'id')) {
        return;
    }

    if (!column_exists($pdo, 'chamas', 'plan_expires_at')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN plan_expires_at DATE NULL AFTER monthly_fee");
    }
    if (!column_exists($pdo, 'chamas', 'billing_phone')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN billing_phone VARCHAR(30) NOT NULL DEFAULT '' AFTER phone");
    }
    if (!column_exists($pdo, 'chamas', 'suspension_reason')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN suspension_reason VARCHAR(30) NOT NULL DEFAULT '' AFTER status");
    }
    if (!column_exists($pdo, 'chamas', 'monthly_dues')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN monthly_dues DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER monthly_fee");
    }

    if (!column_exists($pdo, 'mpesa_payments', 'id')) {
        $pdo->exec("CREATE TABLE mpesa_payments (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!column_exists($pdo, 'subscription_payments', 'id')) {
        $pdo->exec("CREATE TABLE subscription_payments (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!column_exists($pdo, 'mpesa_logs', 'id')) {
        $pdo->exec("CREATE TABLE mpesa_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            direction ENUM('in','out') NOT NULL DEFAULT 'out',
            endpoint VARCHAR(120) NOT NULL DEFAULT '',
            checkout_request_id VARCHAR(120) NULL,
            receipt_no VARCHAR(50) NULL,
            payload MEDIUMTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_mpesalog_checkout (checkout_request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// ---------------------------------------------------------------------------
// Phase 3: SMS messaging, PDF statements support and the audit log.
// ---------------------------------------------------------------------------
function migrate_phase3_schema(PDO $pdo): void
{
    if (!column_exists($pdo, 'chamas', 'id')) {
        return;
    }

    if (!column_exists($pdo, 'chamas', 'sms_enabled')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN sms_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER monthly_dues");
    }
    if (!column_exists($pdo, 'chamas', 'sms_receipts')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN sms_receipts TINYINT(1) NOT NULL DEFAULT 1 AFTER sms_enabled");
    }
    if (!column_exists($pdo, 'chamas', 'sms_reminder_day')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN sms_reminder_day TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER sms_receipts");
    }
    if (!column_exists($pdo, 'chamas', 'sms_last_reminder')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN sms_last_reminder DATE NULL AFTER sms_reminder_day");
    }

    if (!column_exists($pdo, 'sms_logs', 'id')) {
        $pdo->exec("CREATE TABLE sms_logs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!column_exists($pdo, 'chamas', 'is_pilot')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN is_pilot TINYINT(1) NOT NULL DEFAULT 0 AFTER sms_last_reminder");
    }
    if (!column_exists($pdo, 'chamas', 'tier')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN tier VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER is_pilot");
    }
    if (!column_exists($pdo, 'chamas', 'setup_fee')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN setup_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER tier");
    }
    if (!column_exists($pdo, 'chamas', 'setup_fee_paid')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN setup_fee_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER setup_fee");
    }

    if (!column_exists($pdo, 'audit_logs', 'id')) {
        $pdo->exec("CREATE TABLE audit_logs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// ---------------------------------------------------------------------------
// Phase 5 hardening: group payment routes, configurable plans, SMS credit,
// login throttling. Additive and idempotent like the others.
// ---------------------------------------------------------------------------
function migrate_funds_schema(PDO $pdo): void
{
    if (!column_exists($pdo, 'chamas', 'id')) {
        return;
    }
    if (!column_exists($pdo, 'chamas', 'pay_shortcode')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN pay_shortcode VARCHAR(20) NOT NULL DEFAULT '' AFTER sms_last_reminder");
    }
    if (!column_exists($pdo, 'chamas', 'pay_passkey')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN pay_passkey VARCHAR(120) NOT NULL DEFAULT '' AFTER pay_shortcode");
    }
    if (!column_exists($pdo, 'chamas', 'pay_verified')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN pay_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER pay_passkey");
    }
    if (!column_exists($pdo, 'chamas', 'pay_verified_at')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN pay_verified_at DATETIME NULL AFTER pay_verified");
    }
    if (!column_exists($pdo, 'chamas', 'sms_balance')) {
        $pdo->exec("ALTER TABLE chamas ADD COLUMN sms_balance INT NOT NULL DEFAULT 0 AFTER pay_verified_at");
    }
    if (!column_exists($pdo, 'mpesa_payments', 'shortcode_used')) {
        $pdo->exec("ALTER TABLE mpesa_payments ADD COLUMN shortcode_used VARCHAR(20) NOT NULL DEFAULT '' AFTER merchant_request_id");
    }
    if (!column_exists($pdo, 'plans', 'id')) {
        $pdo->exec("CREATE TABLE plans (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO plans (plan_key, label, monthly, max_members, sort, description) VALUES
            ('micro',        'Micro - 8 to 10 active members',   150,  10, 1, 'Small table-banking and merry-go-round groups'),
            ('small',        'Small - 10 to 15 active members',  250,  15, 2, 'Growing savings groups'),
            ('community',    'Community - up to 20 members',     500,  20, 3, 'Established chamas with monthly meetings'),
            ('growth',       'Growth - up to 100 members',      1200, 100, 4, 'Large chamas with real float'),
            ('welfare_plus', 'Welfare Plus - up to 250 members', 2500, 250, 5, 'Welfare societies and alumni groups'),
            ('enterprise',   'Large organisation - quoted',     3500,   0, 6, 'From KSh 3,500/month, quoted by requirements')");
    }
    if (!column_exists($pdo, 'login_attempts', 'id')) {
        $pdo->exec("CREATE TABLE login_attempts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(150) NOT NULL DEFAULT '',
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_login_email (email, created_at),
            KEY idx_login_ip (ip_address, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    // Retire the old hard-coded tier names in favour of the plans table keys.
    $pdo->exec("UPDATE chamas SET tier = 'community' WHERE tier = 'standard'");
    $pdo->exec("UPDATE chamas SET tier = 'welfare_plus' WHERE tier = 'premium'");
}

try {
    migrate_schema($pdo);
    migrate_billing_schema($pdo);
    migrate_phase3_schema($pdo);
    migrate_funds_schema($pdo);
} catch (Throwable $ignored) {
    // When the core tables have not been imported yet, skip migration;
    // importing database.sql creates everything in the correct shape.
}
