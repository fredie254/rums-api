-- ============================================================
-- RUMS — MySQL 5.7 compatible patch
-- Replaces migrations 001/003/004/005/008/009/011 which used
-- ADD COLUMN IF NOT EXISTS (not supported in MySQL 5.7).
-- Safe to re-run — checks information_schema before every change.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Helper: add column only if it does not exist ─────────────
DROP PROCEDURE IF EXISTS _col;
DELIMITER $$
CREATE PROCEDURE _col(IN tbl VARCHAR(64), IN col VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

-- ── Helper: create index only if it does not exist ───────────
DROP PROCEDURE IF EXISTS _idx;
DELIMITER $$
CREATE PROCEDURE _idx(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx
  ) THEN
    SET @s = CONCAT('CREATE INDEX `', idx, '` ON `', tbl, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

-- ── Helper: drop index only if it exists ─────────────────────
DROP PROCEDURE IF EXISTS _drop_idx;
DELIMITER $$
CREATE PROCEDURE _drop_idx(IN tbl VARCHAR(64), IN idx VARCHAR(64))
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` DROP INDEX `', idx, '`');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

-- ============================================================
-- 001 — properties.image + units columns + status enum
-- ============================================================

CALL _col('properties', 'image',
  '`image` VARCHAR(255) DEFAULT NULL AFTER `amenities`');

CALL _col('units', 'block_number',
  '`block_number` VARCHAR(30) DEFAULT NULL AFTER `floor`');
CALL _col('units', 'water_included',
  '`water_included` TINYINT(1) NOT NULL DEFAULT 0 AFTER `furnished`');
CALL _col('units', 'electricity_included',
  '`electricity_included` TINYINT(1) NOT NULL DEFAULT 0 AFTER `water_included`');
CALL _col('units', 'utility_charge',
  '`utility_charge` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `electricity_included`');

-- MODIFY is idempotent — safe even if 'reserved' already present
ALTER TABLE `units` MODIFY COLUMN `status`
  ENUM('available','occupied','maintenance','inactive','reserved')
  NOT NULL DEFAULT 'available';

-- ============================================================
-- 003 — Encryption columns (tenants + landlords)
-- ============================================================

-- tenants.id_number is already varchar(512) — wide enough for encrypted values.
-- Only need to drop the old unique index and add the hash column/index.
CALL _drop_idx('tenants', 'uq_tenant_id_number');

CALL _col('tenants', 'id_number_hash',
  '`id_number_hash` CHAR(64) DEFAULT NULL AFTER `id_number`');

UPDATE `tenants`
  SET `id_number_hash` = SHA2(LOWER(TRIM(`id_number`)), 256)
  WHERE `id_number_hash` IS NULL
    AND `id_number` IS NOT NULL AND `id_number` != '';

CALL _idx('tenants', 'uq_tenant_id_number_hash', '(`id_number_hash`)');

-- Landlords: drop the plain-text unique index before widening to TEXT
-- (MySQL cannot have a UNIQUE index on an unindexed-length TEXT column)
CALL _drop_idx('landlords', 'uq_landlords_id_number');

ALTER TABLE `landlords`
  MODIFY COLUMN `id_number`    TEXT DEFAULT NULL,
  MODIFY COLUMN `kra_pin`      TEXT DEFAULT NULL,
  MODIFY COLUMN `bank_account` TEXT DEFAULT NULL,
  MODIFY COLUMN `mpesa_number` TEXT DEFAULT NULL;

CALL _col('landlords', 'id_number_hash',
  '`id_number_hash` CHAR(64) DEFAULT NULL AFTER `id_number`');

UPDATE `landlords`
  SET `id_number_hash` = SHA2(LOWER(TRIM(`id_number`)), 256)
  WHERE `id_number_hash` IS NULL
    AND `id_number` IS NOT NULL AND `id_number` != '';

CALL _idx('landlords', 'uq_landlords_id_number_hash', '(`id_number_hash`)');

-- ============================================================
-- 004 — Lease engine columns
-- ============================================================

CALL _col('leases', 'lease_type',
  '`lease_type` ENUM(''fixed-term'',''periodic'',''commercial'',''furnished'') NOT NULL DEFAULT ''fixed-term'' AFTER `lease_number`');
CALL _col('leases', 'template_id',
  '`template_id` INT UNSIGNED DEFAULT NULL AFTER `lease_type`');
CALL _col('leases', 'renewed_from_id',
  '`renewed_from_id` INT UNSIGNED DEFAULT NULL AFTER `template_id`');
CALL _col('leases', 'notice_period_days',
  '`notice_period_days` SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER `penalty_rate`');
CALL _col('leases', 'escalation_type',
  '`escalation_type` ENUM(''none'',''fixed'',''percentage'') NOT NULL DEFAULT ''none'' AFTER `notice_period_days`');
CALL _col('leases', 'escalation_rate',
  '`escalation_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `escalation_type`');
CALL _col('leases', 'escalation_frequency',
  '`escalation_frequency` ENUM(''annually'',''biannually'',''quarterly'') NOT NULL DEFAULT ''annually'' AFTER `escalation_rate`');
CALL _col('leases', 'next_escalation_date',
  '`next_escalation_date` DATE DEFAULT NULL AFTER `escalation_frequency`');
CALL _col('leases', 'signed_at',
  '`signed_at` DATETIME DEFAULT NULL AFTER `next_escalation_date`');
CALL _col('leases', 'signed_by',
  '`signed_by` INT UNSIGNED DEFAULT NULL AFTER `signed_at`');

-- ============================================================
-- 005 — Invoice period columns
-- ============================================================

CALL _col('invoices', 'period_month',
  '`period_month` TINYINT UNSIGNED DEFAULT NULL AFTER `amount_paid`');
CALL _col('invoices', 'period_year',
  '`period_year` SMALLINT UNSIGNED DEFAULT NULL AFTER `period_month`');

UPDATE `invoices`
  SET period_month = MONTH(invoice_date),
      period_year  = YEAR(invoice_date)
  WHERE period_month IS NULL OR period_year IS NULL;

CALL _idx('invoices', 'idx_invoices_period',
  '(lease_id, period_year, period_month)');

-- ============================================================
-- 006 — payment_method enum (idempotent MODIFY)
-- ============================================================

ALTER TABLE `payments` MODIFY COLUMN `payment_method`
  ENUM('cash','mpesa','bank','cheque','bank_transfer','card','other')
  NOT NULL DEFAULT 'cash';

-- ============================================================
-- 008 — report_schedules index
-- ============================================================

CALL _idx('report_schedules', 'idx_report_schedules_active_next',
  '(is_active, next_run_at)');

-- ============================================================
-- 009 — document indexes
-- ============================================================

CALL _idx('documents', 'idx_docs_entity',  '(entity_type, entity_id)');
CALL _idx('documents', 'idx_docs_type',    '(document_type, is_deleted)');
CALL _idx('documents', 'idx_docs_latest',  '(uuid, is_latest, is_deleted)');
CALL _idx('documents', 'idx_docs_parent',  '(parent_id)');

-- ============================================================
-- 011 — GDPR columns on users + consent indexes
-- ============================================================

CALL _col('users', 'data_anonymized',
  '`data_anonymized` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`');
CALL _col('users', 'anonymized_at',
  '`anonymized_at` DATETIME DEFAULT NULL AFTER `data_anonymized`');

CALL _idx('consent_records',       'idx_consent_user_type', '(user_id, consent_type)');
CALL _idx('data_export_requests',  'idx_export_user',       '(user_id)');
CALL _idx('data_export_requests',  'idx_export_token',      '(download_token)');
CALL _idx('data_deletion_requests','idx_deletion_user',     '(user_id)');
CALL _idx('data_deletion_requests','idx_deletion_status',   '(status)');

-- ── Cleanup ───────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS _col;
DROP PROCEDURE IF EXISTS _idx;
DROP PROCEDURE IF EXISTS _drop_idx;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Patch complete.' AS result;
