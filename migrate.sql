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

-- ── Scheduled cleanup (add to cron or run weekly) ──────────
-- DELETE FROM api_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR);
-- DELETE FROM api_request_logs WHERE created_at  < DATE_SUB(NOW(), INTERVAL 90 DAY);
