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

-- 2. Add id_number_hash column if it doesn't already exist
DROP PROCEDURE IF EXISTS _rums_migrate;
DELIMITER $$
CREATE PROCEDURE _rums_migrate()
BEGIN
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

    -- 3. Unique index if it doesn't already exist
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'landlords'
          AND INDEX_NAME   = 'uq_landlords_id_number_hash'
    ) THEN
        ALTER TABLE `landlords`
            ADD UNIQUE KEY `uq_landlords_id_number_hash` (`id_number_hash`);
    END IF;
END$$
DELIMITER ;
CALL _rums_migrate();
DROP PROCEDURE IF EXISTS _rums_migrate;

-- 4. Back-fill hash for existing plaintext rows
--    (Encryptor::hash uses sha256 of lower(trim(value)))
UPDATE `landlords`
SET `id_number_hash` = SHA2(LOWER(TRIM(`id_number`)), 256)
WHERE `id_number` IS NOT NULL
  AND `id_number` NOT LIKE 'enc1:%';

-- ============================================================
-- Performance indexes (run once — idempotent via IF NOT EXISTS workarounds)
-- ============================================================

-- api_tokens: token lookup is the hottest query in the system
-- UNIQUE KEY uq_token already exists — verify with: SHOW INDEX FROM api_tokens;

-- CREATE INDEX IF NOT EXISTS is valid MySQL 8.0+ syntax
-- (ALTER TABLE ... ADD INDEX IF NOT EXISTS is NOT supported in MySQL)

CREATE INDEX IF NOT EXISTS `idx_rl_window`          ON `api_rate_limits`   (`window_start`);
CREATE INDEX IF NOT EXISTS `idx_logs_created`        ON `api_request_logs`  (`created_at`);
CREATE INDEX IF NOT EXISTS `idx_logs_endpoint`       ON `api_request_logs`  (`method`, `endpoint`(100));
CREATE INDEX IF NOT EXISTS `idx_notif_user_read`     ON `notifications`     (`user_id`, `is_read`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_leases_unit_status`  ON `leases`            (`unit_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_leases_tenant_status`ON `leases`            (`tenant_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_inv_tenant_status`   ON `invoices`          (`tenant_id`, `status`, `due_date`);
CREATE INDEX IF NOT EXISTS `idx_pay_lease_date`      ON `payments`          (`lease_id`, `payment_date`);
CREATE INDEX IF NOT EXISTS `idx_exp_date_status`     ON `expenses`          (`expense_date`, `status`);

-- ── Scheduled cleanup (add to cron or run weekly) ──────────
-- DELETE FROM api_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR);
-- DELETE FROM api_request_logs WHERE created_at  < DATE_SUB(NOW(), INTERVAL 90 DAY);
