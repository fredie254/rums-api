-- ============================================================
-- RUMS API — Database Schema (canonical, includes migrations 001–011)
-- Import via phpMyAdmin or: mysql -u user -p db < schema.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'NO_ENGINE_SUBSTITUTION';

-- ── Users ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(150)    NOT NULL,
  `email`            VARCHAR(150)    NOT NULL,
  `phone`            VARCHAR(30)     DEFAULT NULL,
  `role`             ENUM('admin','manager','landlord','tenant','accountant','maintenance','auditor','security') NOT NULL DEFAULT 'tenant',
  `password`         VARCHAR(255)    NOT NULL,
  `status`           ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `data_anonymized`  TINYINT(1)      NOT NULL DEFAULT 0,
  `anonymized_at`    DATETIME        DEFAULT NULL,
  `last_login`       DATETIME        DEFAULT NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── API Tokens ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED    NOT NULL,
  `token`        VARCHAR(255)    NOT NULL,
  `name`         VARCHAR(100)    NOT NULL DEFAULT 'API Token',
  `scopes`       TEXT            DEFAULT NULL,
  `revoked`      TINYINT(1)      NOT NULL DEFAULT 0,
  `last_used`    DATETIME        DEFAULT NULL,
  `expires_at`   DATETIME        DEFAULT NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `fk_tokens_user` (`user_id`),
  CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── API Rate Limits ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `api_rate_limits` (
  `identifier`    VARCHAR(100)   NOT NULL,
  `window_start`  DATETIME       NOT NULL,
  `request_count` INT UNSIGNED   NOT NULL DEFAULT 1,
  PRIMARY KEY (`identifier`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── API Request Logs ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `api_request_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_id`     INT UNSIGNED    DEFAULT NULL,
  `user_id`      INT UNSIGNED    DEFAULT NULL,
  `method`       VARCHAR(10)     DEFAULT NULL,
  `endpoint`     VARCHAR(255)    DEFAULT NULL,
  `status_code`  SMALLINT        NOT NULL DEFAULT 0,
  `ip_address`   VARCHAR(45)     DEFAULT NULL,
  `user_agent`   VARCHAR(255)    DEFAULT NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_user`  (`user_id`),
  KEY `idx_logs_token` (`token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Landlords ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `landlords` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED    NOT NULL,
  `id_number`       TEXT            DEFAULT NULL,
  `id_number_hash`  CHAR(64)        DEFAULT NULL,
  `company_name`    VARCHAR(150)    DEFAULT NULL,
  `kra_pin`         TEXT            DEFAULT NULL,
  `bank_name`       VARCHAR(100)    DEFAULT NULL,
  `bank_account`    TEXT            DEFAULT NULL,
  `bank_branch`     VARCHAR(100)    DEFAULT NULL,
  `mpesa_number`    TEXT            DEFAULT NULL,
  `commission_rate` DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
  `notes`           TEXT            DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_landlords_id_number_hash` (`id_number_hash`),
  KEY `fk_landlords_user` (`user_id`),
  CONSTRAINT `fk_landlords_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Properties ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `properties` (
  `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(150)    NOT NULL,
  `property_type`    VARCHAR(50)     NOT NULL,
  `address_line1`    VARCHAR(150)    DEFAULT NULL,
  `address_line2`    VARCHAR(150)    DEFAULT NULL,
  `address_city`     VARCHAR(100)    DEFAULT NULL,
  `address_county`   VARCHAR(100)    NOT NULL,
  `address_country`  VARCHAR(100)    NOT NULL DEFAULT 'Kenya',
  `total_units`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `year_built`       YEAR            DEFAULT NULL,
  `landlord_id`      INT UNSIGNED    DEFAULT NULL,
  `manager_id`       INT UNSIGNED    DEFAULT NULL,
  `description`      TEXT            DEFAULT NULL,
  `amenities`        TEXT            DEFAULT NULL,
  `image`            VARCHAR(255)    DEFAULT NULL,
  `status`           ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_properties_landlord` (`landlord_id`),
  KEY `fk_properties_manager`  (`manager_id`),
  CONSTRAINT `fk_properties_landlord` FOREIGN KEY (`landlord_id`) REFERENCES `landlords` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_properties_manager`  FOREIGN KEY (`manager_id`)  REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Units ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `units` (
  `id`                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `property_id`          INT UNSIGNED    NOT NULL,
  `unit_number`          VARCHAR(30)     NOT NULL,
  `unit_type`            VARCHAR(50)     NOT NULL,
  `floor`                TINYINT         DEFAULT NULL,
  `block_number`         VARCHAR(30)     DEFAULT NULL,
  `bedrooms`             TINYINT UNSIGNED DEFAULT NULL,
  `bathrooms`            TINYINT UNSIGNED DEFAULT NULL,
  `size_sqft`            DECIMAL(8,2)    DEFAULT NULL,
  `rent_amount`          DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `deposit_amount`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `furnished`            TINYINT(1)      NOT NULL DEFAULT 0,
  `water_included`       TINYINT(1)      NOT NULL DEFAULT 0,
  `electricity_included` TINYINT(1)      NOT NULL DEFAULT 0,
  `utility_charge`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `amenities`            TEXT            DEFAULT NULL,
  `description`          TEXT            DEFAULT NULL,
  `status`               ENUM('available','occupied','maintenance','inactive','reserved') NOT NULL DEFAULT 'available',
  `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_in_property` (`property_id`, `unit_number`),
  KEY `fk_units_property` (`property_id`),
  CONSTRAINT `fk_units_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tenants ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `tenants` (
  `id`                      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`                 INT UNSIGNED  DEFAULT NULL,
  `first_name`              VARCHAR(80)   NOT NULL,
  `last_name`               VARCHAR(80)   NOT NULL,
  `email`                   VARCHAR(150)  NOT NULL,
  `phone`                   TEXT          NOT NULL,
  `id_number`               TEXT          NOT NULL,
  `id_number_hash`          CHAR(64)      DEFAULT NULL,
  `id_type`                 VARCHAR(20)   NOT NULL DEFAULT 'national_id',
  `dob`                     TEXT          DEFAULT NULL,
  `gender`                  ENUM('male','female','other') DEFAULT NULL,
  `nationality`             VARCHAR(50)   DEFAULT 'Kenyan',
  `emergency_contact_name`  TEXT          DEFAULT NULL,
  `emergency_contact_phone` TEXT          DEFAULT NULL,
  `next_of_kin_name`        TEXT          DEFAULT NULL,
  `next_of_kin_phone`       TEXT          DEFAULT NULL,
  `occupation`              TEXT          DEFAULT NULL,
  `employer`                TEXT          DEFAULT NULL,
  `monthly_income`          TEXT          DEFAULT NULL,
  `notes`                   TEXT          DEFAULT NULL,
  `status`                  ENUM('active','inactive','blacklisted') NOT NULL DEFAULT 'active',
  `created_at`              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_email`          (`email`),
  UNIQUE KEY `uq_tenant_id_number_hash` (`id_number_hash`),
  KEY `fk_tenants_user` (`user_id`),
  CONSTRAINT `fk_tenants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- ── Leases ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `leases` (
  `id`                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `lease_number`         VARCHAR(30)     NOT NULL,
  `lease_type`           ENUM('fixed-term','periodic','commercial','furnished') NOT NULL DEFAULT 'fixed-term',
  `template_id`          INT UNSIGNED    DEFAULT NULL,
  `renewed_from_id`      INT UNSIGNED    DEFAULT NULL,
  `unit_id`              INT UNSIGNED    NOT NULL,
  `tenant_id`            INT UNSIGNED    NOT NULL,
  `start_date`           DATE            NOT NULL,
  `end_date`             DATE            NOT NULL,
  `monthly_rent`         DECIMAL(12,2)   NOT NULL,
  `deposit_amount`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `deposit_paid_date`    DATE            DEFAULT NULL,
  `payment_day`          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `grace_period_days`    TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `penalty_rate`         DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
  `notice_period_days`   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `escalation_type`      ENUM('none','fixed','percentage') NOT NULL DEFAULT 'none',
  `escalation_rate`      DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
  `escalation_frequency` ENUM('annually','biannually','quarterly') NOT NULL DEFAULT 'annually',
  `next_escalation_date` DATE            DEFAULT NULL,
  `terms`                TEXT            DEFAULT NULL,
  `notes`                TEXT            DEFAULT NULL,
  `status`               ENUM('active','expired','terminated') NOT NULL DEFAULT 'active',
  `termination_reason`   TEXT            DEFAULT NULL,
  `terminated_at`        DATETIME        DEFAULT NULL,
  `signed_at`            DATETIME        DEFAULT NULL,
  `signed_by`            INT UNSIGNED    DEFAULT NULL,
  `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lease_number` (`lease_number`),
  KEY `fk_leases_unit`     (`unit_id`),
  KEY `fk_leases_tenant`   (`tenant_id`),
  KEY `fk_leases_template` (`template_id`),
  KEY `fk_leases_signed`   (`signed_by`),
  CONSTRAINT `fk_leases_unit`     FOREIGN KEY (`unit_id`)     REFERENCES `units`           (`id`),
  CONSTRAINT `fk_leases_tenant`   FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`         (`id`),
  CONSTRAINT `fk_leases_template` FOREIGN KEY (`template_id`) REFERENCES `lease_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leases_signed`   FOREIGN KEY (`signed_by`)   REFERENCES `users`           (`id`) ON DELETE SET NULL
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

-- ── Invoices ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `invoices` (
  `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `invoice_number`   VARCHAR(30)     NOT NULL,
  `lease_id`         INT UNSIGNED    NOT NULL,
  `tenant_id`        INT UNSIGNED    NOT NULL,
  `invoice_date`     DATE            NOT NULL,
  `due_date`         DATE            NOT NULL,
  `rent_amount`      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `utility_amount`   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `penalty_amount`   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `discount_amount`  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `total_amount`     DECIMAL(12,2)   NOT NULL,
  `amount_paid`      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `period_month`     TINYINT UNSIGNED         DEFAULT NULL  COMMENT 'Invoice billing month (1-12)',
  `period_year`      SMALLINT UNSIGNED        DEFAULT NULL  COMMENT 'Invoice billing year',
  `status`           ENUM('unpaid','partial','paid','overdue','cancelled') NOT NULL DEFAULT 'unpaid',
  `notes`            TEXT            DEFAULT NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  KEY `fk_invoices_lease`        (`lease_id`),
  KEY `fk_invoices_tenant`       (`tenant_id`),
  KEY `idx_invoices_period`      (`lease_id`, `period_year`, `period_month`),
  CONSTRAINT `fk_invoices_lease`  FOREIGN KEY (`lease_id`)  REFERENCES `leases`  (`id`),
  CONSTRAINT `fk_invoices_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Payments ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `payments` (
  `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `payment_ref`           VARCHAR(30)     NOT NULL,
  `lease_id`              INT UNSIGNED    NOT NULL,
  `invoice_id`            INT UNSIGNED    DEFAULT NULL,
  `tenant_id`             INT UNSIGNED    NOT NULL,
  `unit_id`               INT UNSIGNED    DEFAULT NULL,
  `amount`                DECIMAL(12,2)   NOT NULL,
  `payment_date`          DATE            NOT NULL,
  `payment_method`        ENUM('cash','mpesa','bank','bank_transfer','cheque','card','other') NOT NULL DEFAULT 'cash',
  `payment_type`          VARCHAR(50)     NOT NULL DEFAULT 'rent',
  `period_month`          TINYINT UNSIGNED DEFAULT NULL,
  `period_year`           SMALLINT UNSIGNED DEFAULT NULL,
  `mpesa_transaction_id`  VARCHAR(50)     DEFAULT NULL,
  `mpesa_receipt`         VARCHAR(50)     DEFAULT NULL,
  `cheque_number`         VARCHAR(30)     DEFAULT NULL,
  `notes`                 TEXT            DEFAULT NULL,
  `status`                ENUM('completed','pending','reversed','failed') NOT NULL DEFAULT 'completed',
  `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_ref` (`payment_ref`),
  KEY `fk_payments_lease`   (`lease_id`),
  KEY `fk_payments_invoice` (`invoice_id`),
  KEY `fk_payments_tenant`  (`tenant_id`),
  KEY `fk_payments_unit`    (`unit_id`),
  CONSTRAINT `fk_payments_lease`   FOREIGN KEY (`lease_id`)   REFERENCES `leases`   (`id`),
  CONSTRAINT `fk_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_tenant`  FOREIGN KEY (`tenant_id`)  REFERENCES `tenants`  (`id`),
  CONSTRAINT `fk_payments_unit`    FOREIGN KEY (`unit_id`)    REFERENCES `units`    (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Maintenance Requests ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `maintenance_requests` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `request_number`    VARCHAR(30)     NOT NULL,
  `unit_id`           INT UNSIGNED    NOT NULL,
  `tenant_id`         INT UNSIGNED    DEFAULT NULL,
  `issue_title`       VARCHAR(200)    NOT NULL,
  `description`       TEXT            DEFAULT NULL,
  `priority`          ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `category`          VARCHAR(50)     DEFAULT NULL,
  `status`            ENUM('open','in_progress','completed','resolved','cancelled') NOT NULL DEFAULT 'open',
  `assigned_to`       INT UNSIGNED    DEFAULT NULL,
  `notes`             TEXT            DEFAULT NULL,
  `work_started`      DATETIME        DEFAULT NULL,
  `work_completed`    DATETIME        DEFAULT NULL,
  `labour_hours`      DECIMAL(6,2)    DEFAULT NULL,
  `materials_cost`    DECIMAL(12,2)   DEFAULT NULL,
  `labour_cost`       DECIMAL(12,2)   DEFAULT NULL,
  `contractor_name`   VARCHAR(150)    DEFAULT NULL,
  `contractor_phone`  VARCHAR(30)     DEFAULT NULL,
  `is_recurring`      TINYINT(1)      NOT NULL DEFAULT 0,
  `next_due_date`     DATE            DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_request_number` (`request_number`),
  KEY `fk_mr_unit`        (`unit_id`),
  KEY `fk_mr_tenant`      (`tenant_id`),
  KEY `fk_mr_assigned_to` (`assigned_to`),
  CONSTRAINT `fk_mr_unit`        FOREIGN KEY (`unit_id`)     REFERENCES `units`   (`id`),
  CONSTRAINT `fk_mr_tenant`      FOREIGN KEY (`tenant_id`)   REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mr_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users`   (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Expenses ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `expenses` (
  `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `property_id`  INT UNSIGNED    DEFAULT NULL,
  `unit_id`      INT UNSIGNED    DEFAULT NULL,
  `category`     VARCHAR(80)     NOT NULL,
  `description`  TEXT            NOT NULL,
  `amount`       DECIMAL(12,2)   NOT NULL,
  `expense_date` DATE            NOT NULL,
  `vendor`       VARCHAR(150)    DEFAULT NULL,
  `receipt_ref`  VARCHAR(80)     DEFAULT NULL,
  `paid_by`      INT UNSIGNED    DEFAULT NULL,
  `approved_by`  INT UNSIGNED    DEFAULT NULL,
  `status`       ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
  `notes`        TEXT            DEFAULT NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_expenses_property`    (`property_id`),
  KEY `fk_expenses_unit`        (`unit_id`),
  KEY `fk_expenses_paid_by`     (`paid_by`),
  KEY `fk_expenses_approved_by` (`approved_by`),
  CONSTRAINT `fk_expenses_property`    FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_expenses_unit`        FOREIGN KEY (`unit_id`)     REFERENCES `units`       (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_expenses_paid_by`     FOREIGN KEY (`paid_by`)     REFERENCES `users`       (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_expenses_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`       (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Settings ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(100)    NOT NULL,
  `setting_value` TEXT            DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── KYC Documents ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `kyc_documents` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED    NOT NULL,
  `document_type` VARCHAR(50)     NOT NULL DEFAULT 'other',
  `original_name` VARCHAR(255)    NOT NULL,
  `file_path`     VARCHAR(255)    NOT NULL,
  `file_size`     INT UNSIGNED    DEFAULT NULL,
  `mime_type`     VARCHAR(100)    DEFAULT NULL,
  `notes`         TEXT            DEFAULT NULL,
  `uploaded_by`   INT UNSIGNED    DEFAULT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_kyc_tenant` (`tenant_id`),
  KEY `fk_kyc_user`   (`uploaded_by`),
  CONSTRAINT `fk_kyc_tenant` FOREIGN KEY (`tenant_id`)  REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kyc_user`   FOREIGN KEY (`uploaded_by`) REFERENCES `users`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Notifications ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED    NOT NULL,
  `title`      VARCHAR(200)    NOT NULL,
  `message`    TEXT            NOT NULL,
  `type`       VARCHAR(30)     NOT NULL DEFAULT 'info',
  `link`       VARCHAR(255)    DEFAULT NULL,
  `is_read`    TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_notifications_user`  (`user_id`),
  KEY `idx_notifications_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Audit Logs ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED    DEFAULT NULL,
  `action`      VARCHAR(80)     NOT NULL,
  `module`      VARCHAR(80)     NOT NULL,
  `entity_id`   INT UNSIGNED    DEFAULT NULL,
  `description` TEXT            DEFAULT NULL,
  `ip_address`  VARCHAR(45)     DEFAULT NULL,
  `user_agent`  VARCHAR(255)    DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user`       (`user_id`),
  KEY `idx_audit_action`     (`action`),
  KEY `idx_audit_module`     (`module`),
  KEY `idx_audit_created_at` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Visitor Logs ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `visitor_logs` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `property_id`     INT UNSIGNED    DEFAULT NULL,
  `unit_id`         INT UNSIGNED    DEFAULT NULL,
  `tenant_id`       INT UNSIGNED    DEFAULT NULL,
  `visitor_name`    VARCHAR(150)    NOT NULL,
  `visitor_phone`   VARCHAR(30)     DEFAULT NULL,
  `visitor_id_no`   VARCHAR(50)     DEFAULT NULL,
  `visitor_id_type` VARCHAR(20)     NOT NULL DEFAULT 'national_id',
  `vehicle_reg`     VARCHAR(20)     DEFAULT NULL,
  `purpose`         VARCHAR(200)    NOT NULL DEFAULT '',
  `host_name`       VARCHAR(100)    DEFAULT NULL,
  `check_in`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `check_out`       DATETIME        DEFAULT NULL,
  `badge_no`        VARCHAR(20)     DEFAULT NULL,
  `notes`           TEXT            DEFAULT NULL,
  `status`          ENUM('in','out','overstay') NOT NULL DEFAULT 'in',
  `logged_by`       INT UNSIGNED    DEFAULT NULL,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_visitor_check_in`  (`check_in`),
  KEY `idx_visitor_status`    (`status`),
  KEY `fk_visitor_property`   (`property_id`),
  KEY `fk_visitor_unit`       (`unit_id`),
  KEY `fk_visitor_tenant`     (`tenant_id`),
  KEY `fk_visitor_logged_by`  (`logged_by`),
  CONSTRAINT `fk_visitor_property`  FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visitor_unit`      FOREIGN KEY (`unit_id`)     REFERENCES `units`       (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visitor_tenant`    FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`     (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visitor_logged_by` FOREIGN KEY (`logged_by`)   REFERENCES `users`       (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Security Incidents ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `security_incidents` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `property_id`       INT UNSIGNED    DEFAULT NULL,
  `unit_id`           INT UNSIGNED    DEFAULT NULL,
  `incident_type`     VARCHAR(80)     NOT NULL DEFAULT 'other',
  `severity`          ENUM('critical','high','medium','low') NOT NULL DEFAULT 'medium',
  `incident_date`     DATETIME        NOT NULL,
  `description`       TEXT            NOT NULL,
  `persons_involved`  TEXT            DEFAULT NULL,
  `action_taken`      TEXT            DEFAULT NULL,
  `police_ref`        VARCHAR(80)     DEFAULT NULL,
  `resolved`          TINYINT(1)      NOT NULL DEFAULT 0,
  `resolved_at`       DATETIME        DEFAULT NULL,
  `logged_by`         INT UNSIGNED    DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_si_incident_date` (`incident_date`),
  KEY `idx_si_severity`      (`severity`),
  KEY `idx_si_resolved`      (`resolved`),
  KEY `fk_si_property`       (`property_id`),
  KEY `fk_si_unit`           (`unit_id`),
  KEY `fk_si_logged_by`      (`logged_by`),
  CONSTRAINT `fk_si_property`  FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_si_unit`      FOREIGN KEY (`unit_id`)     REFERENCES `units`       (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_si_logged_by` FOREIGN KEY (`logged_by`)   REFERENCES `users`       (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Occupancy Logs ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `occupancy_logs` (
  `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `property_id`   INT UNSIGNED      DEFAULT NULL,
  `unit_id`       INT UNSIGNED      DEFAULT NULL,
  `tenant_id`     INT UNSIGNED      DEFAULT NULL,
  `event_type`    VARCHAR(50)       NOT NULL DEFAULT 'other',
  `event_date`    DATE              NOT NULL,
  `event_time`    TIME              DEFAULT NULL,
  `description`   TEXT              DEFAULT NULL,
  `persons_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `authorized_by` VARCHAR(150)      DEFAULT NULL,
  `reference_no`  VARCHAR(80)       DEFAULT NULL,
  `logged_by`     INT UNSIGNED      DEFAULT NULL,
  `created_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ol_event_date` (`event_date`),
  KEY `idx_ol_event_type` (`event_type`),
  KEY `fk_ol_property`    (`property_id`),
  KEY `fk_ol_unit`        (`unit_id`),
  KEY `fk_ol_tenant`      (`tenant_id`),
  KEY `fk_ol_logged_by`   (`logged_by`),
  CONSTRAINT `fk_ol_property`  FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ol_unit`      FOREIGN KEY (`unit_id`)     REFERENCES `units`       (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ol_tenant`    FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`     (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ol_logged_by` FOREIGN KEY (`logged_by`)   REFERENCES `users`       (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── M-Pesa Transactions ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mpesa_transactions` (
  `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `transaction_id`        VARCHAR(80)     DEFAULT NULL,
  `checkout_request_id`   VARCHAR(100)    NOT NULL,
  `merchant_request_id`   VARCHAR(100)    DEFAULT NULL,
  `payment_id`            INT UNSIGNED    DEFAULT NULL,
  `phone`                 VARCHAR(20)     DEFAULT NULL,
  `msisdn`                VARCHAR(20)     DEFAULT NULL,
  `first_name`            VARCHAR(80)     DEFAULT NULL,
  `last_name`             VARCHAR(80)     DEFAULT NULL,
  `amount`                DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `account_reference`     VARCHAR(80)     DEFAULT NULL,
  `mpesa_receipt`         VARCHAR(50)     DEFAULT NULL,
  `result_code`           TINYINT         DEFAULT NULL,
  `result_desc`           VARCHAR(255)    DEFAULT NULL,
  `raw_response`          MEDIUMTEXT      DEFAULT NULL,
  `status`                ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
  `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mpesa_checkout_req` (`checkout_request_id`),
  KEY `idx_mpesa_status`     (`status`),
  KEY `idx_mpesa_created_at` (`created_at`),
  KEY `fk_mpesa_payment`     (`payment_id`),
  CONSTRAINT `fk_mpesa_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bank Statement Entries ────────────────────────────────────
-- (migration 006: bank reconciliation)
CREATE TABLE IF NOT EXISTS `bank_statement_entries` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `import_batch`   VARCHAR(50)     NOT NULL  COMMENT 'Groups entries from the same CSV import',
  `statement_date` DATE            NOT NULL,
  `value_date`     DATE            DEFAULT NULL,
  `description`    VARCHAR(500)    DEFAULT NULL,
  `debit`          DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `credit`         DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `balance`        DECIMAL(12,2)   DEFAULT NULL,
  `reference`      VARCHAR(150)    DEFAULT NULL  COMMENT 'Bank reference / narration',
  `payment_id`     INT UNSIGNED    DEFAULT NULL  COMMENT 'Matched RUMS payment',
  `matched_by`     INT UNSIGNED    DEFAULT NULL,
  `matched_at`     DATETIME        DEFAULT NULL,
  `imported_by`    INT UNSIGNED    NOT NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bse_batch`      (`import_batch`),
  KEY `idx_bse_date`       (`statement_date`),
  KEY `idx_bse_payment_id` (`payment_id`),
  CONSTRAINT `fk_bse_payment`    FOREIGN KEY (`payment_id`)  REFERENCES `payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bse_imported`   FOREIGN KEY (`imported_by`) REFERENCES `users`    (`id`),
  CONSTRAINT `fk_bse_matched_by` FOREIGN KEY (`matched_by`)  REFERENCES `users`    (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Message Templates ─────────────────────────────────────────
-- (migration 007: communication module)
CREATE TABLE IF NOT EXISTS `message_templates` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150)    NOT NULL,
  `category`    ENUM('payment','lease','maintenance','broadcast','general') NOT NULL DEFAULT 'general',
  `channel`     ENUM('sms','email','both')                                  NOT NULL DEFAULT 'both',
  `subject`     VARCHAR(255)    DEFAULT NULL  COMMENT 'Email subject line',
  `body`        TEXT            NOT NULL,
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `created_by`  INT UNSIGNED    NOT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mt_category` (`category`),
  KEY `idx_mt_channel`  (`channel`),
  CONSTRAINT `fk_mt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Communication Logs ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `communication_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED    DEFAULT NULL,
  `recipient`     VARCHAR(255)    NOT NULL  COMMENT 'Phone number or email address',
  `channel`       ENUM('sms','email','in_app')                NOT NULL,
  `template_id`   INT UNSIGNED    DEFAULT NULL,
  `subject`       VARCHAR(255)    DEFAULT NULL,
  `body`          TEXT            NOT NULL,
  `status`        ENUM('queued','sent','delivered','failed')   NOT NULL DEFAULT 'queued',
  `error_message` TEXT            DEFAULT NULL,
  `provider`      VARCHAR(50)     DEFAULT NULL  COMMENT 'africastalking, smtp, mail, internal',
  `provider_ref`  VARCHAR(150)    DEFAULT NULL  COMMENT 'Message ID from provider',
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
  CONSTRAINT `fk_cl_template` FOREIGN KEY (`template_id`) REFERENCES `message_templates`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cl_sent_by`  FOREIGN KEY (`sent_by`)     REFERENCES `users`            (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Broadcast Messages ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `broadcast_messages` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `title`             VARCHAR(200)    NOT NULL,
  `channel`           ENUM('sms','email','both')                          NOT NULL DEFAULT 'sms',
  `subject`           VARCHAR(255)    DEFAULT NULL  COMMENT 'Email subject',
  `message`           TEXT            NOT NULL,
  `template_id`       INT UNSIGNED    DEFAULT NULL,
  `recipient_filter`  JSON            DEFAULT NULL  COMMENT 'Filter: {property_id, status, has_overdue}',
  `total_recipients`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `sent_count`        INT UNSIGNED    NOT NULL DEFAULT 0,
  `failed_count`      INT UNSIGNED    NOT NULL DEFAULT 0,
  `status`            ENUM('draft','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
  `scheduled_at`      DATETIME        DEFAULT NULL,
  `started_at`        DATETIME        DEFAULT NULL,
  `completed_at`      DATETIME        DEFAULT NULL,
  `created_by`        INT UNSIGNED    NOT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bm_status`     (`status`),
  KEY `idx_bm_created_by` (`created_by`),
  CONSTRAINT `fk_bm_created_by` FOREIGN KEY (`created_by`)  REFERENCES `users`            (`id`),
  CONSTRAINT `fk_bm_template`   FOREIGN KEY (`template_id`) REFERENCES `message_templates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Report Schedules ──────────────────────────────────────────
-- (migration 008: report analytics)
CREATE TABLE IF NOT EXISTS `report_schedules` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150)    NOT NULL,
  `report_type` ENUM('financial','occupancy','rent_collection','arrears','maintenance','tenant_analytics','aging','deposits','dashboard') NOT NULL,
  `format`      ENUM('csv','pdf') NOT NULL DEFAULT 'csv',
  `filters`     JSON            DEFAULT NULL  COMMENT 'e.g. {"property_id":1,"year":2025}',
  `frequency`   ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'monthly',
  `run_day`     TINYINT UNSIGNED NOT NULL DEFAULT 1  COMMENT 'For weekly: 0=Sun…6=Sat. For monthly: 1-28.',
  `run_hour`    TINYINT UNSIGNED NOT NULL DEFAULT 7   COMMENT '24h, server TZ',
  `recipients`  JSON            NOT NULL  COMMENT 'Array of email strings',
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `last_run_at` DATETIME        DEFAULT NULL,
  `next_run_at` DATETIME        DEFAULT NULL,
  `created_by`  INT UNSIGNED    NOT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_report_schedules_active_next` (`is_active`, `next_run_at`),
  CONSTRAINT `fk_rs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Documents ─────────────────────────────────────────────────
-- (migration 009: document management with versioning)
CREATE TABLE IF NOT EXISTS `documents` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `uuid`          CHAR(36)         NOT NULL  COMMENT 'Public identifier (v4 UUID)',
  `title`         VARCHAR(200)     NOT NULL,
  `description`   TEXT             DEFAULT NULL,
  `document_type` ENUM('lease','tenant','property','certificate','financial','maintenance','other') NOT NULL DEFAULT 'other',
  `category`      VARCHAR(100)     DEFAULT NULL  COMMENT 'Sub-type within document_type',
  `entity_type`   ENUM('lease','tenant','property','unit','general') NOT NULL DEFAULT 'general',
  `entity_id`     INT UNSIGNED     DEFAULT NULL,
  `file_name`     VARCHAR(255)     NOT NULL  COMMENT 'Original filename shown to users',
  `stored_name`   VARCHAR(255)     NOT NULL  COMMENT 'UUID-based disk name',
  `file_path`     VARCHAR(500)     NOT NULL  COMMENT 'Relative to DOCUMENT_STORAGE',
  `file_size`     INT UNSIGNED     NOT NULL DEFAULT 0  COMMENT 'Bytes',
  `mime_type`     VARCHAR(100)     NOT NULL,
  `version`       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `parent_id`     INT UNSIGNED     DEFAULT NULL  COMMENT 'Previous version document id',
  `is_latest`     TINYINT(1)       NOT NULL DEFAULT 1,
  `access_level`  ENUM('private','internal','shared') NOT NULL DEFAULT 'internal'
                  COMMENT 'private=uploader only, internal=staff, shared=tenant+staff',
  `is_deleted`    TINYINT(1)       NOT NULL DEFAULT 0,
  `deleted_at`    DATETIME         DEFAULT NULL,
  `deleted_by`    INT UNSIGNED     DEFAULT NULL,
  `uploaded_by`   INT UNSIGNED     NOT NULL,
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_documents_uuid` (`uuid`),
  KEY `idx_docs_entity`  (`entity_type`, `entity_id`),
  KEY `idx_docs_type`    (`document_type`, `is_deleted`),
  KEY `idx_docs_latest`  (`uuid`, `is_latest`, `is_deleted`),
  KEY `idx_docs_parent`  (`parent_id`),
  CONSTRAINT `fk_docs_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users`     (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_docs_parent`      FOREIGN KEY (`parent_id`)   REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_docs_deleted_by`  FOREIGN KEY (`deleted_by`)  REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Document Access Logs ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `document_access_logs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `action`      ENUM('view','download','delete','upload','version') NOT NULL,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `user_agent`  VARCHAR(500) DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dal_document`   (`document_id`),
  KEY `idx_dal_user`       (`user_id`),
  KEY `idx_dal_created_at` (`created_at`),
  CONSTRAINT `fk_dal_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dal_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`      (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── MFA Secrets ───────────────────────────────────────────────
-- (migration 010: TOTP multi-factor authentication)
CREATE TABLE IF NOT EXISTS `mfa_secrets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `secret`     VARCHAR(512) NOT NULL  COMMENT 'AES-256-GCM encrypted TOTP base32 secret',
  `is_enabled` TINYINT(1)   NOT NULL DEFAULT 0,
  `enabled_at` DATETIME     DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfa_secrets_user` (`user_id`),
  CONSTRAINT `fk_mfa_secrets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── MFA Backup Codes ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mfa_backup_codes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `code_hash`  VARCHAR(255) NOT NULL  COMMENT 'password_hash of the 8-char code',
  `used_at`    DATETIME     DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mfa_backup_user` (`user_id`),
  CONSTRAINT `fk_mfa_backup_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── MFA Pending Challenges ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mfa_pending` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `pending_token` CHAR(64)     NOT NULL  COMMENT 'random_bytes(32) hex',
  `expires_at`    DATETIME     NOT NULL,
  `used`          TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfa_pending_token` (`pending_token`),
  KEY `idx_mfa_pending_token`   (`pending_token`),
  KEY `idx_mfa_pending_expires` (`expires_at`),
  CONSTRAINT `fk_mfa_pending_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Consent Records ───────────────────────────────────────────
-- (migration 011: GDPR compliance)
CREATE TABLE IF NOT EXISTS `consent_records` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `consent_type` ENUM('terms','privacy','marketing') NOT NULL,
  `version`      VARCHAR(20)  NOT NULL DEFAULT '1.0',
  `consented`    TINYINT(1)   NOT NULL DEFAULT 1  COMMENT '1=given, 0=withdrawn',
  `ip_address`   VARCHAR(45)  DEFAULT NULL,
  `user_agent`   VARCHAR(500) DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_consent_user_type` (`user_id`, `consent_type`),
  CONSTRAINT `fk_consent_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Data Export Requests ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `data_export_requests` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `status`         ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
  `download_token` CHAR(64)     DEFAULT NULL  COMMENT 'One-time download token',
  `token_expires`  DATETIME     DEFAULT NULL,
  `completed_at`   DATETIME     DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_export_user`  (`user_id`),
  KEY `idx_export_token` (`download_token`),
  CONSTRAINT `fk_export_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Data Deletion Requests ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `data_deletion_requests` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `reason`       TEXT         DEFAULT NULL,
  `status`       ENUM('pending','processing','completed','rejected') NOT NULL DEFAULT 'pending',
  `requested_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME     DEFAULT NULL,
  `processed_by` INT UNSIGNED DEFAULT NULL,
  `admin_notes`  TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_deletion_user`   (`user_id`),
  KEY `idx_deletion_status` (`status`),
  CONSTRAINT `fk_deletion_user`         FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_deletion_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Seed Data ─────────────────────────────────────────────────

-- Default message templates (migration 007)
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
