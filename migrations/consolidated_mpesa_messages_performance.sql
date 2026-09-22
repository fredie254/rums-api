-- =============================================================================
-- Consolidated migration — MySQL 5.7 compatible (no IF NOT EXISTS on columns/indexes)
-- Covers: mpesa_configs, mpesa_transactions.lease_id,
--         payments.uk_mpesa_receipt, message_templates.landlord_id,
--         performance indexes
-- =============================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- =============================================================================
-- 1. mpesa_configs — per-landlord Safaricom credentials
-- =============================================================================

CREATE TABLE IF NOT EXISTS mpesa_configs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    landlord_id         INT UNSIGNED   NOT NULL,
    shortcode           VARCHAR(20)    NOT NULL,
    shortcode_type      ENUM('paybill','till')       NOT NULL DEFAULT 'paybill',
    consumer_key        TEXT           NOT NULL,
    consumer_secret     TEXT           NOT NULL,
    passkey             TEXT           NOT NULL,
    environment         ENUM('sandbox','production') NOT NULL DEFAULT 'production',
    urls_registered     TINYINT(1)     NOT NULL DEFAULT 0,
    urls_registered_at  DATETIME       NULL,
    is_active           TINYINT(1)     NOT NULL DEFAULT 1,
    notes               TEXT           NULL,
    created_by          INT UNSIGNED   NULL,
    created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_mc_landlord  (landlord_id),
    UNIQUE KEY uk_mc_shortcode (shortcode),

    CONSTRAINT fk_mc_landlord FOREIGN KEY (landlord_id) REFERENCES landlords (id) ON DELETE CASCADE,
    CONSTRAINT fk_mc_creator  FOREIGN KEY (created_by)  REFERENCES users    (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- 2. mpesa_transactions — add lease_id column (dynamic, 5.7-safe)
-- =============================================================================

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mpesa_transactions'
      AND COLUMN_NAME  = 'lease_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE mpesa_transactions ADD COLUMN lease_id INT UNSIGNED NULL AFTER payment_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mpesa_transactions'
      AND CONSTRAINT_NAME = 'fk_mptx_lease'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE mpesa_transactions ADD CONSTRAINT fk_mptx_lease FOREIGN KEY (lease_id) REFERENCES leases (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 3. payments — add mpesa_receipt column + unique constraint
-- =============================================================================

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'payments'
      AND COLUMN_NAME  = 'mpesa_receipt'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE payments ADD COLUMN mpesa_receipt VARCHAR(50) NULL DEFAULT NULL AFTER mpesa_transaction_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

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
-- 4. message_templates — per-landlord customization (dynamic, 5.7-safe)
-- =============================================================================

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'message_templates'
      AND COLUMN_NAME  = 'landlord_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE message_templates ADD COLUMN landlord_id INT UNSIGNED NULL DEFAULT NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

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
-- 5. Performance indexes (all dynamic, 5.7-safe)
-- =============================================================================

-- invoices
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_inv_status')=0,'ALTER TABLE invoices ADD INDEX idx_inv_status (status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_inv_due_date')=0,'ALTER TABLE invoices ADD INDEX idx_inv_due_date (due_date)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_inv_lease_stat')=0,'ALTER TABLE invoices ADD INDEX idx_inv_lease_stat (lease_id, status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_inv_created')=0,'ALTER TABLE invoices ADD INDEX idx_inv_created (created_at)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- leases
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='leases' AND INDEX_NAME='idx_lease_status')=0,'ALTER TABLE leases ADD INDEX idx_lease_status (status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='leases' AND INDEX_NAME='idx_lease_status_end')=0,'ALTER TABLE leases ADD INDEX idx_lease_status_end (status, end_date)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='leases' AND INDEX_NAME='idx_lease_unit_status')=0,'ALTER TABLE leases ADD INDEX idx_lease_unit_status (unit_id, status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- payments
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_status')=0,'ALTER TABLE payments ADD INDEX idx_pay_status (status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_date')=0,'ALTER TABLE payments ADD INDEX idx_pay_date (payment_date)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_stat_date')=0,'ALTER TABLE payments ADD INDEX idx_pay_stat_date (status, payment_date)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_tenant_stat')=0,'ALTER TABLE payments ADD INDEX idx_pay_tenant_stat (tenant_id, status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- units
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='units' AND INDEX_NAME='idx_unit_status')=0,'ALTER TABLE units ADD INDEX idx_unit_status (status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='units' AND INDEX_NAME='idx_unit_prop_status')=0,'ALTER TABLE units ADD INDEX idx_unit_prop_status (property_id, status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- maintenance_requests
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_requests' AND INDEX_NAME='idx_maint_status')=0,'ALTER TABLE maintenance_requests ADD INDEX idx_maint_status (status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_requests' AND INDEX_NAME='idx_maint_pri')=0,'ALTER TABLE maintenance_requests ADD INDEX idx_maint_pri (priority)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_requests' AND INDEX_NAME='idx_maint_statpri')=0,'ALTER TABLE maintenance_requests ADD INDEX idx_maint_statpri (status, priority)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_requests' AND INDEX_NAME='idx_maint_created')=0,'ALTER TABLE maintenance_requests ADD INDEX idx_maint_created (created_at)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- notifications
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND INDEX_NAME='idx_notif_user_read')=0,'ALTER TABLE notifications ADD INDEX idx_notif_user_read (user_id, is_read)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND INDEX_NAME='idx_notif_created')=0,'ALTER TABLE notifications ADD INDEX idx_notif_created (created_at)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- mpesa_configs
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mpesa_configs' AND INDEX_NAME='idx_mc_active')=0,'ALTER TABLE mpesa_configs ADD INDEX idx_mc_active (is_active)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- properties
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='properties' AND INDEX_NAME='idx_prop_status')=0,'ALTER TABLE properties ADD INDEX idx_prop_status (status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;


-- =============================================================================
-- 6. Data patch
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
    mpesa_configs,
    properties,
    message_templates;

SET foreign_key_checks = 1;
