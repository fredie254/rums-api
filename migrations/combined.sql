-- ============================================================
-- RUMS — Combined Migration (001–011)
-- Generated: 2026-07-04
--
-- Merges all individual migration files into one idempotent script.
--
-- Safe to run on:
--   • A fresh database (after schema.sql)
--   • Any existing database that has run any subset of 001–011
--
-- Compatible with MySQL 5.7+ and MySQL 8.x.
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
-- 001 — properties.image  +  units columns  +  status enum
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

-- MODIFY is idempotent — MySQL skips the table rewrite if nothing changed
ALTER TABLE `units`
  MODIFY COLUMN `status`
    ENUM('available','occupied','maintenance','inactive','reserved')
    NOT NULL DEFAULT 'available';

-- ============================================================
-- 002 — KYC Document Repository
-- ============================================================

CREATE TABLE IF NOT EXISTS `kyc_documents` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED  NOT NULL,
  `document_type` VARCHAR(50)   NOT NULL DEFAULT 'other',
  `original_name` VARCHAR(255)  NOT NULL,
  `file_path`     VARCHAR(255)  NOT NULL,
  `file_size`     INT UNSIGNED  DEFAULT NULL,
  `mime_type`     VARCHAR(100)  DEFAULT NULL,
  `notes`         TEXT          DEFAULT NULL,
  `uploaded_by`   INT UNSIGNED  DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_kyc_tenant` (`tenant_id`),
  KEY `fk_kyc_user`   (`uploaded_by`),
  CONSTRAINT `fk_kyc_tenant` FOREIGN KEY (`tenant_id`)   REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kyc_user`   FOREIGN KEY (`uploaded_by`) REFERENCES `users`   (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 003 — Encryption columns (tenants + landlords)
-- ============================================================

-- Drop old plain-text unique indexes before widening columns
CALL _drop_idx('tenants',   'uq_tenant_id_number');
CALL _drop_idx('landlords', 'uq_landlords_id_number');

-- Widen tenant PII columns to TEXT for AES-256-GCM ciphertext storage
ALTER TABLE `tenants`
  MODIFY COLUMN `id_number`               TEXT NOT NULL,
  MODIFY COLUMN `phone`                   TEXT NOT NULL,
  MODIFY COLUMN `dob`                     TEXT DEFAULT NULL,
  MODIFY COLUMN `monthly_income`          TEXT DEFAULT NULL,
  MODIFY COLUMN `occupation`              TEXT DEFAULT NULL,
  MODIFY COLUMN `employer`               TEXT DEFAULT NULL,
  MODIFY COLUMN `emergency_contact_name`  TEXT DEFAULT NULL,
  MODIFY COLUMN `emergency_contact_phone` TEXT DEFAULT NULL,
  MODIFY COLUMN `next_of_kin_name`        TEXT DEFAULT NULL,
  MODIFY COLUMN `next_of_kin_phone`       TEXT DEFAULT NULL;

CALL _col('tenants', 'id_number_hash',
  '`id_number_hash` CHAR(64) DEFAULT NULL AFTER `id_number`');

-- Backfill hash for any existing plaintext id_number values
UPDATE `tenants`
  SET `id_number_hash` = SHA2(LOWER(TRIM(`id_number`)), 256)
  WHERE `id_number_hash` IS NULL
    AND `id_number` IS NOT NULL
    AND `id_number` != '';

CALL _idx('tenants', 'uq_tenant_id_number_hash', '(`id_number_hash`)');

-- Widen landlord sensitive columns
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
    AND `id_number` IS NOT NULL
    AND `id_number` != '';

CALL _idx('landlords', 'uq_landlords_id_number_hash', '(`id_number_hash`)');

-- ============================================================
-- 004 — Lease Engine
-- ============================================================

-- MODIFY instead of ADD — lease_type pre-existed with old enum values ('fixed','periodic','month_to_month')
ALTER TABLE `leases`
  MODIFY COLUMN `lease_type`
    ENUM('fixed-term','periodic','commercial','furnished')
    NOT NULL DEFAULT 'fixed-term';

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
-- MODIFY instead of ADD — escalation_frequency pre-existed with old enum ('annually','semi_annually','quarterly')
ALTER TABLE `leases`
  MODIFY COLUMN `escalation_frequency`
    ENUM('annually','biannually','quarterly')
    NOT NULL DEFAULT 'annually';
CALL _col('leases', 'next_escalation_date',
  '`next_escalation_date` DATE DEFAULT NULL AFTER `escalation_frequency`');
CALL _col('leases', 'signed_at',
  '`signed_at` DATETIME DEFAULT NULL AFTER `next_escalation_date`');
CALL _col('leases', 'signed_by',
  '`signed_by` INT UNSIGNED DEFAULT NULL AFTER `signed_at`');

CREATE TABLE IF NOT EXISTS `lease_templates` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(150)  NOT NULL,
  `lease_type` ENUM('fixed-term','periodic','commercial','furnished') NOT NULL DEFAULT 'fixed-term',
  `body`       LONGTEXT      NOT NULL,
  `is_default` TINYINT(1)    NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)    NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED  DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_lt_user` (`created_by`),
  CONSTRAINT `fk_lt_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lease_renewals` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `original_lease_id` INT UNSIGNED  NOT NULL,
  `new_lease_id`      INT UNSIGNED  DEFAULT NULL,
  `initiated_by`      INT UNSIGNED  DEFAULT NULL,
  `old_end_date`      DATE          NOT NULL,
  `new_end_date`      DATE          NOT NULL,
  `old_monthly_rent`  DECIMAL(12,2) NOT NULL,
  `new_monthly_rent`  DECIMAL(12,2) NOT NULL,
  `notes`             TEXT          DEFAULT NULL,
  `status`            ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  `approved_by`       INT UNSIGNED  DEFAULT NULL,
  `approved_at`       DATETIME      DEFAULT NULL,
  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_lr_orig`      (`original_lease_id`),
  KEY `fk_lr_new`       (`new_lease_id`),
  KEY `fk_lr_initiated` (`initiated_by`),
  KEY `fk_lr_approved`  (`approved_by`),
  CONSTRAINT `fk_lr_orig`      FOREIGN KEY (`original_lease_id`) REFERENCES `leases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lr_new`       FOREIGN KEY (`new_lease_id`)      REFERENCES `leases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_lr_initiated` FOREIGN KEY (`initiated_by`)      REFERENCES `users`  (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_lr_approved`  FOREIGN KEY (`approved_by`)       REFERENCES `users`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lease_documents` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `lease_id`      INT UNSIGNED  NOT NULL,
  `document_type` VARCHAR(50)   NOT NULL DEFAULT 'contract',
  `original_name` VARCHAR(255)  NOT NULL,
  `file_path`     VARCHAR(255)  NOT NULL,
  `file_size`     INT UNSIGNED  DEFAULT NULL,
  `mime_type`     VARCHAR(100)  DEFAULT NULL,
  `notes`         TEXT          DEFAULT NULL,
  `uploaded_by`   INT UNSIGNED  DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ld_lease` (`lease_id`),
  KEY `fk_ld_user`  (`uploaded_by`),
  CONSTRAINT `fk_ld_lease` FOREIGN KEY (`lease_id`)    REFERENCES `leases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ld_user`  FOREIGN KEY (`uploaded_by`) REFERENCES `users`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 005 — Billing Engine (invoice period + discount columns)
-- ============================================================

CALL _col('invoices', 'period_month',
  '`period_month` TINYINT UNSIGNED DEFAULT NULL AFTER `amount_paid`');
CALL _col('invoices', 'period_year',
  '`period_year` SMALLINT UNSIGNED DEFAULT NULL AFTER `period_month`');
CALL _col('invoices', 'discount_amount',
  '`discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `penalty_amount`');

UPDATE `invoices`
  SET period_month = MONTH(invoice_date),
      period_year  = YEAR(invoice_date)
  WHERE period_month IS NULL OR period_year IS NULL;

CALL _idx('invoices', 'idx_invoices_period', '(lease_id, period_year, period_month)');

-- ============================================================
-- 006 — Payment Module (payment_method enum + bank recon table)
-- ============================================================

ALTER TABLE `payments`
  MODIFY COLUMN `payment_method`
    ENUM('cash','mpesa','bank','bank_transfer','cheque','card','other')
    NOT NULL DEFAULT 'cash';

CREATE TABLE IF NOT EXISTS `bank_statement_entries` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `import_batch`   VARCHAR(50)   NOT NULL COMMENT 'Groups entries from the same CSV import',
  `statement_date` DATE          NOT NULL,
  `value_date`     DATE          DEFAULT NULL,
  `description`    VARCHAR(500)  DEFAULT NULL,
  `debit`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `credit`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance`        DECIMAL(12,2) DEFAULT NULL,
  `reference`      VARCHAR(150)  DEFAULT NULL COMMENT 'Bank reference / narration',
  `payment_id`     INT UNSIGNED  DEFAULT NULL COMMENT 'Matched RUMS payment',
  `matched_by`     INT UNSIGNED  DEFAULT NULL,
  `matched_at`     DATETIME      DEFAULT NULL,
  `imported_by`    INT UNSIGNED  NOT NULL,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bse_batch`      (`import_batch`),
  KEY `idx_bse_date`       (`statement_date`),
  KEY `idx_bse_payment_id` (`payment_id`),
  CONSTRAINT `fk_bse_payment`    FOREIGN KEY (`payment_id`)  REFERENCES `payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bse_imported`   FOREIGN KEY (`imported_by`) REFERENCES `users`    (`id`),
  CONSTRAINT `fk_bse_matched_by` FOREIGN KEY (`matched_by`)  REFERENCES `users`    (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 007 — Communication & Notification Module
-- ============================================================

CREATE TABLE IF NOT EXISTS `message_templates` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150)  NOT NULL,
  `category`    ENUM('payment','lease','maintenance','broadcast','general') NOT NULL DEFAULT 'general',
  `channel`     ENUM('sms','email','both')                                 NOT NULL DEFAULT 'both',
  `subject`     VARCHAR(255)  DEFAULT NULL COMMENT 'Email subject line',
  `body`        TEXT          NOT NULL,
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `created_by`  INT UNSIGNED  NOT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mt_category` (`category`),
  KEY `idx_mt_channel`  (`channel`),
  CONSTRAINT `fk_mt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `communication_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED    DEFAULT NULL,
  `recipient`     VARCHAR(255)    NOT NULL COMMENT 'Phone number or email address',
  `channel`       ENUM('sms','email','in_app')              NOT NULL,
  `template_id`   INT UNSIGNED    DEFAULT NULL,
  `subject`       VARCHAR(255)    DEFAULT NULL,
  `body`          TEXT            NOT NULL,
  `status`        ENUM('queued','sent','delivered','failed') NOT NULL DEFAULT 'queued',
  `error_message` TEXT            DEFAULT NULL,
  `provider`      VARCHAR(50)     DEFAULT NULL COMMENT 'africastalking, smtp, mail, internal',
  `provider_ref`  VARCHAR(150)    DEFAULT NULL COMMENT 'Message ID from provider',
  `broadcast_id`  INT UNSIGNED    DEFAULT NULL,
  `sent_by`       INT UNSIGNED    DEFAULT NULL,
  `sent_at`       DATETIME        DEFAULT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cl_tenant`    (`tenant_id`),
  KEY `idx_cl_channel`   (`channel`),
  KEY `idx_cl_status`    (`status`),
  KEY `idx_cl_broadcast` (`broadcast_id`),
  KEY `idx_cl_created`   (`created_at`),
  CONSTRAINT `fk_cl_tenant`   FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`          (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cl_template` FOREIGN KEY (`template_id`) REFERENCES `message_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cl_sent_by`  FOREIGN KEY (`sent_by`)     REFERENCES `users`            (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcast_messages` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`            VARCHAR(200)  NOT NULL,
  `channel`          ENUM('sms','email','both')                           NOT NULL DEFAULT 'sms',
  `subject`          VARCHAR(255)  DEFAULT NULL COMMENT 'Email subject',
  `message`          TEXT          NOT NULL,
  `template_id`      INT UNSIGNED  DEFAULT NULL,
  `recipient_filter` JSON          DEFAULT NULL COMMENT 'Filter: {property_id, status, has_overdue}',
  `total_recipients` INT UNSIGNED  NOT NULL DEFAULT 0,
  `sent_count`       INT UNSIGNED  NOT NULL DEFAULT 0,
  `failed_count`     INT UNSIGNED  NOT NULL DEFAULT 0,
  `status`           ENUM('draft','sending','sent','failed','cancelled')  NOT NULL DEFAULT 'draft',
  `scheduled_at`     DATETIME      DEFAULT NULL,
  `started_at`       DATETIME      DEFAULT NULL,
  `completed_at`     DATETIME      DEFAULT NULL,
  `created_by`       INT UNSIGNED  NOT NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bm_status`     (`status`),
  KEY `idx_bm_created_by` (`created_by`),
  CONSTRAINT `fk_bm_created_by` FOREIGN KEY (`created_by`)  REFERENCES `users`             (`id`),
  CONSTRAINT `fk_bm_template`   FOREIGN KEY (`template_id`) REFERENCES `message_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default message templates (INSERT IGNORE is idempotent)
INSERT IGNORE INTO `message_templates` (`id`, `name`, `category`, `channel`, `subject`, `body`, `created_by`) VALUES
(1, 'Payment Reminder (SMS)', 'payment', 'sms', NULL,
 'Dear {{TENANT_NAME}}, your rent of {{AMOUNT_DUE}} for {{UNIT_NUMBER}} ({{PROPERTY_NAME}}) is due on {{DUE_DATE}}. Invoice: {{INVOICE_NUMBER}}. Please pay promptly to avoid penalties. {{COMPANY_NAME}}',
 1),
(2, 'Payment Reminder (Email)', 'payment', 'email', 'Rent Due Reminder — {{INVOICE_NUMBER}}',
 '<p>Dear {{TENANT_NAME}},</p><p>This is a reminder that your rent payment of <strong>{{AMOUNT_DUE}}</strong> for unit <strong>{{UNIT_NUMBER}}</strong> at <strong>{{PROPERTY_NAME}}</strong> is due on <strong>{{DUE_DATE}}</strong>.</p><p><strong>Invoice:</strong> {{INVOICE_NUMBER}}</p><p>Please make payment at your earliest convenience to avoid late penalties.</p><p>If you have already paid, please disregard this message.</p><p>Regards,<br>{{COMPANY_NAME}}</p>',
 1),
(3, 'Payment Received (SMS)', 'payment', 'sms', NULL,
 'Hi {{TENANT_NAME}}, we received your payment of {{AMOUNT_DUE}} on {{PAYMENT_DATE}}. Ref: {{PAYMENT_REF}}. Thank you! {{COMPANY_NAME}}',
 1),
(4, 'Lease Expiry Reminder (SMS)', 'lease', 'sms', NULL,
 'Dear {{TENANT_NAME}}, your lease for {{UNIT_NUMBER}} expires on {{END_DATE}} ({{DAYS_REMAINING}} days). Please contact us to discuss renewal. Lease: {{LEASE_NUMBER}}. {{COMPANY_NAME}}',
 1),
(5, 'Lease Expiry Reminder (Email)', 'lease', 'email', 'Lease Expiry Notice — {{LEASE_NUMBER}}',
 '<p>Dear {{TENANT_NAME}},</p><p>Your lease agreement for unit <strong>{{UNIT_NUMBER}}</strong> at <strong>{{PROPERTY_NAME}}</strong> is due to expire on <strong>{{END_DATE}}</strong> (in <strong>{{DAYS_REMAINING}} days</strong>).</p><p>Please contact our office to discuss renewal options.</p><p><strong>Lease Reference:</strong> {{LEASE_NUMBER}}</p><p>Regards,<br>{{COMPANY_NAME}}</p>',
 1),
(6, 'Welcome Tenant (SMS)', 'general', 'sms', NULL,
 'Welcome {{TENANT_NAME}}! Your lease for {{UNIT_NUMBER}} at {{PROPERTY_NAME}} starts {{START_DATE}}. Rent: {{MONTHLY_RENT}} due on day {{PAYMENT_DAY}} each month. {{COMPANY_NAME}}',
 1),
(7, 'Maintenance Update (SMS)', 'maintenance', 'sms', NULL,
 'Hi {{TENANT_NAME}}, your maintenance request for {{UNIT_NUMBER}} has been updated to: {{STATUS}}. We will attend to it shortly. {{COMPANY_NAME}}',
 1);

-- ============================================================
-- 008 — Report Schedules
-- ============================================================

CREATE TABLE IF NOT EXISTS `report_schedules` (
  `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150)   NOT NULL,
  `report_type` ENUM('financial','occupancy','rent_collection','arrears','maintenance',
                     'tenant_analytics','aging','deposits','dashboard') NOT NULL,
  `format`      ENUM('csv','pdf') NOT NULL DEFAULT 'csv',
  `filters`     JSON           DEFAULT NULL COMMENT 'e.g. {"property_id":1,"year":2025}',
  `frequency`   ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'monthly',
  `run_day`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'weekly: 0=Sun–6=Sat; monthly: 1-28',
  `run_hour`    TINYINT UNSIGNED NOT NULL DEFAULT 7  COMMENT '24h server time',
  `recipients`  JSON           NOT NULL COMMENT 'array of email strings',
  `is_active`   TINYINT(1)     NOT NULL DEFAULT 1,
  `last_run_at` DATETIME       DEFAULT NULL,
  `next_run_at` DATETIME       DEFAULT NULL,
  `created_by`  INT UNSIGNED   NOT NULL,
  `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_rs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL _idx('report_schedules', 'idx_report_schedules_active_next', '(is_active, next_run_at)');

-- ============================================================
-- 009 — Document Management
-- ============================================================

CREATE TABLE IF NOT EXISTS `documents` (
  `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `uuid`         CHAR(36)         NOT NULL UNIQUE   COMMENT 'Public identifier (v4 UUID)',
  `title`        VARCHAR(200)     NOT NULL,
  `description`  TEXT             DEFAULT NULL,
  `document_type` ENUM('lease','tenant','property','certificate',
                       'financial','maintenance','other') NOT NULL DEFAULT 'other',
  `category`     VARCHAR(100)     DEFAULT NULL       COMMENT 'Sub-type within document_type',
  `entity_type`  ENUM('lease','tenant','property','unit','general') NOT NULL DEFAULT 'general',
  `entity_id`    INT UNSIGNED     DEFAULT NULL,
  `file_name`    VARCHAR(255)     NOT NULL           COMMENT 'Original filename shown to users',
  `stored_name`  VARCHAR(255)     NOT NULL           COMMENT 'UUID-based disk name',
  `file_path`    VARCHAR(500)     NOT NULL           COMMENT 'Relative to DOCUMENT_STORAGE',
  `file_size`    INT UNSIGNED     NOT NULL DEFAULT 0 COMMENT 'Bytes',
  `mime_type`    VARCHAR(100)     NOT NULL,
  `version`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `parent_id`    INT UNSIGNED     DEFAULT NULL       COMMENT 'Previous version document id',
  `is_latest`    TINYINT(1)       NOT NULL DEFAULT 1,
  `access_level` ENUM('private','internal','shared') NOT NULL DEFAULT 'internal'
                 COMMENT 'private=uploader only, internal=staff, shared=tenant+staff',
  `is_deleted`   TINYINT(1)       NOT NULL DEFAULT 0,
  `deleted_at`   DATETIME         DEFAULT NULL,
  `deleted_by`   INT UNSIGNED     DEFAULT NULL,
  `uploaded_by`  INT UNSIGNED     NOT NULL,
  `created_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_doc_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users`     (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_doc_parent`      FOREIGN KEY (`parent_id`)   REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_doc_deleted_by`  FOREIGN KEY (`deleted_by`)  REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL _idx('documents', 'idx_docs_entity', '(entity_type, entity_id)');
CALL _idx('documents', 'idx_docs_type',   '(document_type, is_deleted)');
CALL _idx('documents', 'idx_docs_latest', '(uuid, is_latest, is_deleted)');
CALL _idx('documents', 'idx_docs_parent', '(parent_id)');

CREATE TABLE IF NOT EXISTS `document_access_logs` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `document_id` INT UNSIGNED  NOT NULL,
  `user_id`     INT UNSIGNED  NOT NULL,
  `action`      ENUM('view','download','delete','upload','version') NOT NULL,
  `ip_address`  VARCHAR(45)   DEFAULT NULL,
  `user_agent`  VARCHAR(500)  DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dal_document` (`document_id`),
  KEY `idx_dal_user`     (`user_id`),
  KEY `idx_dal_created`  (`created_at`),
  CONSTRAINT `fk_dal_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dal_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`     (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 010 — Multi-Factor Authentication (TOTP)
-- ============================================================

CREATE TABLE IF NOT EXISTS `mfa_secrets` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED  NOT NULL UNIQUE,
  `secret`     VARCHAR(512)  NOT NULL COMMENT 'AES-256-GCM encrypted TOTP base32 secret',
  `is_enabled` TINYINT(1)    NOT NULL DEFAULT 0,
  `enabled_at` DATETIME      DEFAULT NULL,
  `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_mfa_secrets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mfa_backup_codes` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED  NOT NULL,
  `code_hash`  VARCHAR(255)  NOT NULL COMMENT 'bcrypt hash of the 8-char one-time code',
  `used_at`    DATETIME      DEFAULT NULL,
  `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mfa_backup_user` (`user_id`),
  CONSTRAINT `fk_mfa_backup_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mfa_pending` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED  NOT NULL,
  `pending_token` CHAR(64)      NOT NULL UNIQUE COMMENT 'random_bytes(32) hex',
  `expires_at`    DATETIME      NOT NULL,
  `used`          TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mfa_pending_token`   (`pending_token`),
  KEY `idx_mfa_pending_expires` (`expires_at`),
  CONSTRAINT `fk_mfa_pending_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 011 — GDPR Compliance
-- ============================================================

CREATE TABLE IF NOT EXISTS `consent_records` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED  NOT NULL,
  `consent_type` ENUM('terms','privacy','marketing') NOT NULL,
  `version`      VARCHAR(20)   NOT NULL DEFAULT '1.0',
  `consented`    TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '1=given, 0=withdrawn',
  `ip_address`   VARCHAR(45)   DEFAULT NULL,
  `user_agent`   VARCHAR(500)  DEFAULT NULL,
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_consent_user_type` (`user_id`, `consent_type`),
  CONSTRAINT `fk_consent_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `data_export_requests` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED  NOT NULL,
  `status`         ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
  `download_token` CHAR(64)      DEFAULT NULL COMMENT 'One-time download token',
  `token_expires`  DATETIME      DEFAULT NULL,
  `completed_at`   DATETIME      DEFAULT NULL,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_export_user`  (`user_id`),
  KEY `idx_export_token` (`download_token`),
  CONSTRAINT `fk_export_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `data_deletion_requests` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED  NOT NULL,
  `reason`       TEXT          DEFAULT NULL,
  `status`       ENUM('pending','processing','completed','rejected') NOT NULL DEFAULT 'pending',
  `requested_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME      DEFAULT NULL,
  `processed_by` INT UNSIGNED  DEFAULT NULL,
  `admin_notes`  TEXT          DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_deletion_user`   (`user_id`),
  KEY `idx_deletion_status` (`status`),
  CONSTRAINT `fk_deletion_user`      FOREIGN KEY (`user_id`)      REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_deletion_processed` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL _col('users', 'data_anonymized',
  '`data_anonymized` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`');
CALL _col('users', 'anonymized_at',
  '`anonymized_at` DATETIME DEFAULT NULL AFTER `data_anonymized`');

-- ── Cleanup helper procedures ─────────────────────────────────
DROP PROCEDURE IF EXISTS _col;
DROP PROCEDURE IF EXISTS _idx;
DROP PROCEDURE IF EXISTS _drop_idx;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'RUMS combined migration complete.' AS result;
