-- =============================================================================
-- RUMS Consolidated Migration
-- Covers: M-Pesa multi-landlord, message template customization, performance
-- =============================================================================
-- Run once on a fresh or existing database.
-- Safe to re-run: CREATE TABLE uses IF NOT EXISTS; ALTER TABLE uses IF NOT EXISTS
-- for columns and indexes where supported.
--
-- Order matters — run top to bottom:
--   1. New tables (mpesa_configs)
--   2. Column additions to existing tables
--   3. Constraints and indexes
--   4. Data patches
--   5. ANALYZE
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1. mpesa_configs — per-landlord Safaricom credentials
-- =============================================================================
-- Each landlord has their own shortcode (paybill/till).
-- A single shared callback/validate/confirm URL handles all landlords;
-- the BusinessShortCode field in each Safaricom payload identifies which
-- landlord's config to use.

CREATE TABLE IF NOT EXISTS mpesa_configs (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id         INT            NOT NULL,
    shortcode           VARCHAR(20)    NOT NULL,
    shortcode_type      ENUM('paybill','till')         NOT NULL DEFAULT 'paybill',
    consumer_key        TEXT           NOT NULL,
    consumer_secret     TEXT           NOT NULL,
    passkey             TEXT           NOT NULL,
    environment         ENUM('sandbox','production')   NOT NULL DEFAULT 'production',
    urls_registered     TINYINT(1)     NOT NULL DEFAULT 0,
    urls_registered_at  DATETIME       NULL,
    is_active           TINYINT(1)     NOT NULL DEFAULT 1,
    notes               TEXT           NULL,
    created_by          INT            NULL,
    created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_mc_landlord  (landlord_id),
    UNIQUE KEY uk_mc_shortcode (shortcode),

    CONSTRAINT fk_mc_landlord FOREIGN KEY (landlord_id) REFERENCES landlords (id) ON DELETE CASCADE,
    CONSTRAINT fk_mc_creator  FOREIGN KEY (created_by)  REFERENCES users    (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- 2. mpesa_transactions — add lease_id column
-- =============================================================================
-- Allows STK push callbacks to resolve the tenant directly without an extra
-- lookup through the payments table.

ALTER TABLE mpesa_transactions
    ADD COLUMN IF NOT EXISTS lease_id INT NULL AFTER payment_id;

-- Add FK only if the column was just added (safe because IF NOT EXISTS was used)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mpesa_transactions'
      AND COLUMN_NAME  = 'lease_id'
);

SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mpesa_transactions'
      AND CONSTRAINT_NAME = 'fk_mptx_lease'
);

SET @sql = IF(@col_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE mpesa_transactions ADD CONSTRAINT fk_mptx_lease FOREIGN KEY (lease_id) REFERENCES leases (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 3. payments — unique constraint on mpesa_receipt
-- =============================================================================
-- Prevents double-recording when Safaricom retries the same C2B confirmation
-- simultaneously. NULL values are excluded from MySQL unique constraints, so
-- manual/non-Mpesa payments (mpesa_receipt = NULL) are unaffected.

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payments'
      AND INDEX_NAME   = 'uk_mpesa_receipt'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE payments ADD UNIQUE KEY uk_mpesa_receipt (mpesa_receipt)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 4. message_templates — per-landlord customization
-- =============================================================================
-- landlord_id = NULL  →  global default managed by super admin (existing rows)
-- landlord_id = N     →  landlord N's custom override of that category+channel

ALTER TABLE message_templates
    ADD COLUMN IF NOT EXISTS landlord_id INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'NULL = global default; non-null = landlord custom override'
        AFTER id;

-- Foreign key (add only if the column exists and FK doesn't yet)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'message_templates'
      AND CONSTRAINT_NAME = 'fk_mt_landlord'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE message_templates ADD CONSTRAINT fk_mt_landlord FOREIGN KEY (landlord_id) REFERENCES landlords (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- One custom override per landlord per category+channel pair
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'message_templates'
      AND INDEX_NAME   = 'uk_landlord_cat_chan'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE message_templates ADD UNIQUE KEY uk_landlord_cat_chan (landlord_id, category, channel)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 5. Performance indexes
-- =============================================================================
-- Covers the most common WHERE / JOIN / ORDER BY patterns across the app.
-- IF NOT EXISTS prevents errors on re-run.

-- invoices
ALTER TABLE invoices
    ADD INDEX IF NOT EXISTS idx_inv_status     (status),
    ADD INDEX IF NOT EXISTS idx_inv_due_date   (due_date),
    ADD INDEX IF NOT EXISTS idx_inv_lease_stat (lease_id, status),
    ADD INDEX IF NOT EXISTS idx_inv_created    (created_at);

-- leases
ALTER TABLE leases
    ADD INDEX IF NOT EXISTS idx_lease_status      (status),
    ADD INDEX IF NOT EXISTS idx_lease_status_end  (status, end_date),
    ADD INDEX IF NOT EXISTS idx_lease_unit_status (unit_id, status);

-- payments
ALTER TABLE payments
    ADD INDEX IF NOT EXISTS idx_pay_status      (status),
    ADD INDEX IF NOT EXISTS idx_pay_date        (payment_date),
    ADD INDEX IF NOT EXISTS idx_pay_stat_date   (status, payment_date),
    ADD INDEX IF NOT EXISTS idx_pay_tenant_stat (tenant_id, status);

-- units
ALTER TABLE units
    ADD INDEX IF NOT EXISTS idx_unit_status      (status),
    ADD INDEX IF NOT EXISTS idx_unit_prop_status (property_id, status);

-- maintenance_requests
ALTER TABLE maintenance_requests
    ADD INDEX IF NOT EXISTS idx_maint_status  (status),
    ADD INDEX IF NOT EXISTS idx_maint_pri     (priority),
    ADD INDEX IF NOT EXISTS idx_maint_statpri (status, priority),
    ADD INDEX IF NOT EXISTS idx_maint_created (created_at);

-- notifications (unread badge — queried on every page load)
ALTER TABLE notifications
    ADD INDEX IF NOT EXISTS idx_notif_user_read (user_id, is_read),
    ADD INDEX IF NOT EXISTS idx_notif_created   (created_at);

-- mpesa_configs
ALTER TABLE mpesa_configs
    ADD INDEX IF NOT EXISTS idx_mc_active (is_active);

-- properties
ALTER TABLE properties
    ADD INDEX IF NOT EXISTS idx_prop_status (status);


-- =============================================================================
-- 6. Data patch — ensure existing message_templates rows are global defaults
-- =============================================================================
UPDATE message_templates SET landlord_id = NULL WHERE landlord_id IS NULL;


-- =============================================================================
-- 7. Update query planner statistics
-- =============================================================================
ANALYZE TABLE
    invoices,
    leases,
    payments,
    units,
    maintenance_requests,
    notifications,
    properties,
    mpesa_configs,
    mpesa_transactions,
    message_templates;

SET FOREIGN_KEY_CHECKS = 1;

-- Done. Verify with:
--   SHOW CREATE TABLE mpesa_configs;
--   SHOW INDEX FROM payments WHERE Key_name = 'uk_mpesa_receipt';
--   SHOW INDEX FROM leases WHERE Key_name LIKE 'idx_lease%';
