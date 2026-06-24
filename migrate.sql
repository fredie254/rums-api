-- ============================================================
-- RUMS API — Migration: Encryption support for landlords
-- Run once in phpMyAdmin or: mysql -u user -p db < migrate.sql
-- ============================================================

-- 1. Widen encrypted columns so ciphertext fits (enc1: + base64 of iv+tag+ct)
ALTER TABLE `landlords`
    MODIFY COLUMN `id_number`    TEXT            DEFAULT NULL COMMENT 'AES-256-GCM encrypted',
    MODIFY COLUMN `kra_pin`      TEXT            DEFAULT NULL COMMENT 'AES-256-GCM encrypted',
    MODIFY COLUMN `bank_account` TEXT            DEFAULT NULL COMMENT 'AES-256-GCM encrypted',
    MODIFY COLUMN `mpesa_number` TEXT            DEFAULT NULL COMMENT 'AES-256-GCM encrypted';

-- 2. Add id_number_hash column + unique index + performance indexes
--    Uses stored procedure for all conditional DDL (works on MySQL 5.7+)
DROP PROCEDURE IF EXISTS _rums_migrate;
DELIMITER $$
CREATE PROCEDURE _rums_migrate()
BEGIN
    -- id_number_hash column
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'landlords'
          AND COLUMN_NAME  = 'id_number_hash'
    ) THEN
        ALTER TABLE `landlords`
            ADD COLUMN `id_number_hash` CHAR(64) DEFAULT NULL
                COMMENT 'SHA-256 of plaintext id_number'
            AFTER `id_number`;
    END IF;

    -- Unique index on id_number_hash
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'landlords'
          AND INDEX_NAME   = 'uq_landlords_id_number_hash'
    ) THEN
        ALTER TABLE `landlords`
            ADD UNIQUE KEY `uq_landlords_id_number_hash` (`id_number_hash`);
    END IF;

    -- Performance indexes (all guarded by information_schema check)

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_rate_limits' AND INDEX_NAME = 'idx_rl_window'
    ) THEN
        ALTER TABLE `api_rate_limits` ADD INDEX `idx_rl_window` (`window_start`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_request_logs' AND INDEX_NAME = 'idx_logs_created'
    ) THEN
        ALTER TABLE `api_request_logs` ADD INDEX `idx_logs_created` (`created_at`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_request_logs' AND INDEX_NAME = 'idx_logs_endpoint'
    ) THEN
        ALTER TABLE `api_request_logs` ADD INDEX `idx_logs_endpoint` (`method`, `endpoint`(100));
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notif_user_read'
    ) THEN
        ALTER TABLE `notifications` ADD INDEX `idx_notif_user_read` (`user_id`, `is_read`, `created_at`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leases' AND INDEX_NAME = 'idx_leases_unit_status'
    ) THEN
        ALTER TABLE `leases` ADD INDEX `idx_leases_unit_status` (`unit_id`, `status`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leases' AND INDEX_NAME = 'idx_leases_tenant_status'
    ) THEN
        ALTER TABLE `leases` ADD INDEX `idx_leases_tenant_status` (`tenant_id`, `status`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND INDEX_NAME = 'idx_inv_tenant_status'
    ) THEN
        ALTER TABLE `invoices` ADD INDEX `idx_inv_tenant_status` (`tenant_id`, `status`, `due_date`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND INDEX_NAME = 'idx_pay_lease_date'
    ) THEN
        ALTER TABLE `payments` ADD INDEX `idx_pay_lease_date` (`lease_id`, `payment_date`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND INDEX_NAME = 'idx_exp_date_status'
    ) THEN
        ALTER TABLE `expenses` ADD INDEX `idx_exp_date_status` (`expense_date`, `status`);
    END IF;

END$$
DELIMITER ;
CALL _rums_migrate();
DROP PROCEDURE IF EXISTS _rums_migrate;

-- 3. Back-fill hash for existing plaintext rows
--    (Encryptor::hash uses sha256 of lower(trim(value)))
UPDATE `landlords`
SET `id_number_hash` = SHA2(LOWER(TRIM(`id_number`)), 256)
WHERE `id_number` IS NOT NULL
  AND `id_number` NOT LIKE 'enc1:%';

-- ── Tenants table fixes ─────────────────────────────────────────

DROP PROCEDURE IF EXISTS _rums_tenant_migrate;
DELIMITER $$
CREATE PROCEDURE _rums_tenant_migrate()
BEGIN
    -- Make id_number nullable (backfill records may not have it yet)
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'tenants'
          AND COLUMN_NAME  = 'id_number'
          AND IS_NULLABLE  = 'NO'
    ) THEN
        ALTER TABLE `tenants` MODIFY COLUMN `id_number` VARCHAR(30) DEFAULT NULL;
    END IF;

    -- Make phone nullable too (user may not have provided it)
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'tenants'
          AND COLUMN_NAME  = 'phone'
          AND IS_NULLABLE  = 'NO'
    ) THEN
        ALTER TABLE `tenants` MODIFY COLUMN `phone` VARCHAR(30) DEFAULT NULL;
    END IF;

    -- Add id_number_hash column if missing
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'tenants'
          AND COLUMN_NAME  = 'id_number_hash'
    ) THEN
        ALTER TABLE `tenants`
            ADD COLUMN `id_number_hash` CHAR(64) DEFAULT NULL
                COMMENT 'SHA-256 of plaintext id_number'
            AFTER `id_number`;
    END IF;

    -- Add unique index on id_number_hash
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'tenants'
          AND INDEX_NAME   = 'uq_tenants_id_number_hash'
    ) THEN
        ALTER TABLE `tenants`
            ADD UNIQUE KEY `uq_tenants_id_number_hash` (`id_number_hash`);
    END IF;

    -- Backfill landlord profiles for landlord users that have no profile
    INSERT INTO `landlords` (`user_id`)
    SELECT u.id FROM `users` u
    WHERE u.role = 'landlord'
      AND NOT EXISTS (SELECT 1 FROM `landlords` l WHERE l.user_id = u.id);

    -- Backfill tenant profiles for tenant users that have no profile
    INSERT INTO `tenants` (`user_id`, `first_name`, `last_name`, `email`, `phone`, `status`)
    SELECT
        u.id,
        TRIM(SUBSTRING_INDEX(u.name, ' ', 1)),
        TRIM(CASE WHEN LOCATE(' ', u.name) > 0
             THEN SUBSTRING(u.name FROM LOCATE(' ', u.name) + 1)
             ELSE '' END),
        u.email,
        u.phone,
        'active'
    FROM `users` u
    WHERE u.role = 'tenant'
      AND NOT EXISTS (SELECT 1 FROM `tenants` t WHERE t.user_id = u.id);

END$$
DELIMITER ;
CALL _rums_tenant_migrate();
DROP PROCEDURE IF EXISTS _rums_tenant_migrate;

-- Backfill id_number_hash for existing tenant rows with a plaintext id_number
UPDATE `tenants`
SET `id_number_hash` = SHA2(LOWER(TRIM(`id_number`)), 256)
WHERE `id_number` IS NOT NULL
  AND `id_number` != ''
  AND `id_number_hash` IS NULL;

-- ── Units table: add columns used by the frontend form ────────
DROP PROCEDURE IF EXISTS _rums_units_migrate;
DELIMITER $$
CREATE PROCEDURE _rums_units_migrate()
BEGIN
    -- Widen floor to varchar so values like 'G', 'B1', 'Groundfloor' are accepted
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'units'
          AND COLUMN_NAME = 'floor' AND DATA_TYPE = 'tinyint'
    ) THEN
        ALTER TABLE `units` MODIFY COLUMN `floor` VARCHAR(20) DEFAULT NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'units' AND COLUMN_NAME = 'block_number'
    ) THEN
        ALTER TABLE `units` ADD COLUMN `block_number` VARCHAR(30) DEFAULT NULL AFTER `floor`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'units' AND COLUMN_NAME = 'water_included'
    ) THEN
        ALTER TABLE `units` ADD COLUMN `water_included` TINYINT(1) NOT NULL DEFAULT 0 AFTER `deposit_amount`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'units' AND COLUMN_NAME = 'electricity_included'
    ) THEN
        ALTER TABLE `units` ADD COLUMN `electricity_included` TINYINT(1) NOT NULL DEFAULT 0 AFTER `water_included`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'units' AND COLUMN_NAME = 'utility_charge'
    ) THEN
        ALTER TABLE `units` ADD COLUMN `utility_charge` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `electricity_included`;
    END IF;
END$$
DELIMITER ;
CALL _rums_units_migrate();
DROP PROCEDURE IF EXISTS _rums_units_migrate;

-- ── Tenants: widen encrypted PII columns ──────────────────────
-- AES-256-GCM output: "enc1:" + base64(12-byte IV + 16-byte tag + plaintext)
-- A 150-char plaintext encrypts to ~245 chars; varchar(512) covers all cases.
-- dob (date) and monthly_income (decimal) must become varchar to hold ciphertext.
DROP PROCEDURE IF EXISTS _rums_tenants_widen;
DELIMITER $$
CREATE PROCEDURE _rums_tenants_widen()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='phone'                  AND CHARACTER_MAXIMUM_LENGTH < 512) THEN ALTER TABLE `tenants` MODIFY COLUMN `phone`                  VARCHAR(512) COLLATE utf8mb4_unicode_ci NOT NULL; END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='id_number'               AND CHARACTER_MAXIMUM_LENGTH < 512) THEN ALTER TABLE `tenants` MODIFY COLUMN `id_number`               VARCHAR(512) COLLATE utf8mb4_unicode_ci NOT NULL; END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='dob'                     AND DATA_TYPE='date')              THEN ALTER TABLE `tenants` MODIFY COLUMN `dob`                     VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL; END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='monthly_income'           AND DATA_TYPE='decimal')           THEN ALTER TABLE `tenants` MODIFY COLUMN `monthly_income`           VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL; END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='occupation'               AND CHARACTER_MAXIMUM_LENGTH < 512) THEN ALTER TABLE `tenants` MODIFY COLUMN `occupation`               VARCHAR(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL; END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='employer'                 AND CHARACTER_MAXIMUM_LENGTH < 512) THEN ALTER TABLE `tenants` MODIFY COLUMN `employer`                 VARCHAR(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL; END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='emergency_contact_name'   AND CHARACTER_MAXIMUM_LENGTH < 512) THEN ALTER TABLE `tenants` MODIFY COLUMN `emergency_contact_name`   VARCHAR(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL; END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='emergency_contact_phone'  AND CHARACTER_MAXIMUM_LENGTH < 512) THEN ALTER TABLE `tenants` MODIFY COLUMN `emergency_contact_phone`  VARCHAR(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL; END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='next_of_kin_name'         AND CHARACTER_MAXIMUM_LENGTH < 512) THEN ALTER TABLE `tenants` MODIFY COLUMN `next_of_kin_name`         VARCHAR(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL; END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='next_of_kin_phone'        AND CHARACTER_MAXIMUM_LENGTH < 512) THEN ALTER TABLE `tenants` MODIFY COLUMN `next_of_kin_phone`        VARCHAR(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL; END IF;
END$$
DELIMITER ;
CALL _rums_tenants_widen();
DROP PROCEDURE IF EXISTS _rums_tenants_widen;

-- ── Maintenance request activity log ────────────────────────
DROP PROCEDURE IF EXISTS _rums_maintenance_logs_create;
DELIMITER $$
CREATE PROCEDURE _rums_maintenance_logs_create()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'maintenance_request_logs'
    ) THEN
        CREATE TABLE `maintenance_request_logs` (
            `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            `request_id`  INT UNSIGNED    NOT NULL,
            `user_id`     INT UNSIGNED    DEFAULT NULL,
            `user_name`   VARCHAR(100)    DEFAULT NULL,
            `action`      VARCHAR(80)     NOT NULL,
            `from_value`  VARCHAR(150)    DEFAULT NULL,
            `to_value`    VARCHAR(150)    DEFAULT NULL,
            `note`        TEXT            DEFAULT NULL,
            `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_mrl_request_id` (`request_id`),
            KEY `idx_mrl_created_at` (`created_at`),
            CONSTRAINT `fk_mrl_request` FOREIGN KEY (`request_id`)
                REFERENCES `maintenance_requests` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_mrl_user` FOREIGN KEY (`user_id`)
                REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;
END$$
DELIMITER ;
CALL _rums_maintenance_logs_create();
DROP PROCEDURE IF EXISTS _rums_maintenance_logs_create;

-- ── Patch existing tenant tokens: add read:maintenance scope ─
-- Tenant scope was missing read:maintenance — this backfills active tokens.
UPDATE `api_tokens` at
JOIN `users` u ON u.id = at.user_id
SET at.scopes = 'read:leases,read:payments,read:invoices,read:maintenance,write:maintenance'
WHERE u.role = 'tenant'
  AND at.revoked = 0
  AND at.scopes NOT LIKE '%read:maintenance%';

-- ── Security incidents table ────────────────────────────────
-- Created here for deployments whose schema pre-dates this feature.
DROP PROCEDURE IF EXISTS _rums_security_incidents_create;
DELIMITER $$
CREATE PROCEDURE _rums_security_incidents_create()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'security_incidents'
    ) THEN
        CREATE TABLE `security_incidents` (
            `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `property_id`      INT UNSIGNED  DEFAULT NULL,
            `unit_id`          INT UNSIGNED  DEFAULT NULL,
            `incident_type`    VARCHAR(80)   NOT NULL DEFAULT 'other',
            `severity`         ENUM('critical','high','medium','low') NOT NULL DEFAULT 'medium',
            `incident_date`    DATETIME      NOT NULL,
            `description`      TEXT          NOT NULL,
            `persons_involved` TEXT          DEFAULT NULL,
            `action_taken`     TEXT          DEFAULT NULL,
            `police_ref`       VARCHAR(80)   DEFAULT NULL,
            `resolved`         TINYINT(1)    NOT NULL DEFAULT 0,
            `resolved_at`      DATETIME      DEFAULT NULL,
            `logged_by`        INT UNSIGNED  DEFAULT NULL,
            `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_si_incident_date` (`incident_date`),
            KEY `idx_si_severity`      (`severity`),
            CONSTRAINT `fk_si_logged_by` FOREIGN KEY (`logged_by`)  REFERENCES `users`       (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_si_property`  FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_si_unit`      FOREIGN KEY (`unit_id`)     REFERENCES `units`       (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;
END$$
DELIMITER ;
CALL _rums_security_incidents_create();
DROP PROCEDURE IF EXISTS _rums_security_incidents_create;

-- ── Security: patch token scopes (add read:security_incidents) ─
-- Security role tokens issued before this migration lack explicit incident scopes.
-- The endpoint checks read:properties which security already has — no patch needed.

-- ── Visitor log table ────────────────────────────────────────
-- Created here for deployments whose schema pre-dates this feature.
DROP PROCEDURE IF EXISTS _rums_visitor_logs_create;
DELIMITER $$
CREATE PROCEDURE _rums_visitor_logs_create()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'visitor_logs'
    ) THEN
        CREATE TABLE `visitor_logs` (
            `id`               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
            `property_id`      INT UNSIGNED   DEFAULT NULL,
            `unit_id`          INT UNSIGNED   DEFAULT NULL,
            `tenant_id`        INT UNSIGNED   DEFAULT NULL,
            `visitor_name`     VARCHAR(150)   NOT NULL,
            `visitor_phone`    VARCHAR(30)    DEFAULT NULL,
            `visitor_id_no`    VARCHAR(50)    DEFAULT NULL,
            `visitor_id_type`  VARCHAR(20)    NOT NULL DEFAULT 'national_id',
            `vehicle_reg`      VARCHAR(20)    DEFAULT NULL,
            `purpose`          VARCHAR(200)   NOT NULL DEFAULT '',
            `host_name`        VARCHAR(100)   DEFAULT NULL,
            `check_in`         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `check_out`        DATETIME       DEFAULT NULL,
            `badge_no`         VARCHAR(20)    DEFAULT NULL,
            `notes`            TEXT           DEFAULT NULL,
            `status`           ENUM('in','out','overstay') NOT NULL DEFAULT 'in',
            `logged_by`        INT UNSIGNED   DEFAULT NULL,
            `updated_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_visitor_check_in` (`check_in`),
            CONSTRAINT `fk_visitor_logged_by` FOREIGN KEY (`logged_by`)    REFERENCES `users`       (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_visitor_property`  FOREIGN KEY (`property_id`)  REFERENCES `properties`  (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;
END$$
DELIMITER ;
CALL _rums_visitor_logs_create();
DROP PROCEDURE IF EXISTS _rums_visitor_logs_create;

-- ── Scheduled cleanup (add to cron or run weekly) ──────────
-- DELETE FROM api_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR);
-- DELETE FROM api_request_logs WHERE created_at  < DATE_SUB(NOW(), INTERVAL 90 DAY);
