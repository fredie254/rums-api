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

-- api_rate_limits: composite PK (identifier, window_start) exists — good.
-- Add cleanup index so old rows can be purged efficiently:
ALTER TABLE `api_rate_limits`
    ADD INDEX IF NOT EXISTS `idx_rl_window` (`window_start`);

-- api_request_logs: grows unbounded — needs pruning + index for audit queries
ALTER TABLE `api_request_logs`
    ADD INDEX IF NOT EXISTS `idx_logs_created` (`created_at`),
    ADD INDEX IF NOT EXISTS `idx_logs_endpoint` (`method`, `endpoint`(100));

-- notifications: unread count query runs on every dashboard load
ALTER TABLE `notifications`
    ADD INDEX IF NOT EXISTS `idx_notif_user_read` (`user_id`, `is_read`, `created_at`);

-- leases: active lease lookups by unit and tenant
ALTER TABLE `leases`
    ADD INDEX IF NOT EXISTS `idx_leases_unit_status`   (`unit_id`, `status`),
    ADD INDEX IF NOT EXISTS `idx_leases_tenant_status` (`tenant_id`, `status`);

-- invoices: dashboard "unpaid invoices" query
ALTER TABLE `invoices`
    ADD INDEX IF NOT EXISTS `idx_inv_tenant_status` (`tenant_id`, `status`, `due_date`);

-- payments: reconciliation and per-lease queries
ALTER TABLE `payments`
    ADD INDEX IF NOT EXISTS `idx_pay_lease_date` (`lease_id`, `payment_date`);

-- expenses: date-range filter (the default query always filters by expense_date)
ALTER TABLE `expenses`
    ADD INDEX IF NOT EXISTS `idx_exp_date_status` (`expense_date`, `status`);

-- ── Scheduled cleanup (add to cron or run weekly) ──────────
-- DELETE FROM api_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR);
-- DELETE FROM api_request_logs WHERE created_at  < DATE_SUB(NOW(), INTERVAL 90 DAY);
