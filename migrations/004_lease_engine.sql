-- ============================================================
-- RUMS API — Migration 004: Lease Engine
-- Adds lease templates, renewals, documents tables
-- and escalation / lifecycle columns to the leases table.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Extend leases table ───────────────────────────────────────
ALTER TABLE `leases`
  ADD COLUMN IF NOT EXISTS `lease_type`
    ENUM('fixed-term','periodic','commercial','furnished')
    NOT NULL DEFAULT 'fixed-term' AFTER `lease_number`,
  ADD COLUMN IF NOT EXISTS `template_id`
    INT UNSIGNED DEFAULT NULL AFTER `lease_type`,
  ADD COLUMN IF NOT EXISTS `renewed_from_id`
    INT UNSIGNED DEFAULT NULL AFTER `template_id`,
  ADD COLUMN IF NOT EXISTS `notice_period_days`
    SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER `penalty_rate`,
  ADD COLUMN IF NOT EXISTS `escalation_type`
    ENUM('none','fixed','percentage') NOT NULL DEFAULT 'none' AFTER `notice_period_days`,
  ADD COLUMN IF NOT EXISTS `escalation_rate`
    DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `escalation_type`,
  ADD COLUMN IF NOT EXISTS `escalation_frequency`
    ENUM('annually','biannually','quarterly') NOT NULL DEFAULT 'annually' AFTER `escalation_rate`,
  ADD COLUMN IF NOT EXISTS `next_escalation_date`
    DATE DEFAULT NULL AFTER `escalation_frequency`,
  ADD COLUMN IF NOT EXISTS `signed_at`
    DATETIME DEFAULT NULL AFTER `next_escalation_date`,
  ADD COLUMN IF NOT EXISTS `signed_by`
    INT UNSIGNED DEFAULT NULL AFTER `signed_at`;

-- ── Lease Templates ───────────────────────────────────────────
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

-- ── Lease Renewals ────────────────────────────────────────────
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

-- ── Lease Documents ───────────────────────────────────────────
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

SET FOREIGN_KEY_CHECKS = 1;
