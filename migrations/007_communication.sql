-- Migration 007: Communication & Notification Module
-- Run after 006_payment_module.sql

-- ── Message Templates ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `message_templates` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150)    NOT NULL,
  `category`    ENUM('payment','lease','maintenance','broadcast','general') NOT NULL DEFAULT 'general',
  `channel`     ENUM('sms','email','both')                                 NOT NULL DEFAULT 'both',
  `subject`     VARCHAR(255)    DEFAULT NULL COMMENT 'Email subject line',
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
  `recipient`     VARCHAR(255)    NOT NULL COMMENT 'Phone number or email address',
  `channel`       ENUM('sms','email','in_app')                   NOT NULL,
  `template_id`   INT UNSIGNED    DEFAULT NULL,
  `subject`       VARCHAR(255)    DEFAULT NULL,
  `body`          TEXT            NOT NULL,
  `status`        ENUM('queued','sent','delivered','failed')      NOT NULL DEFAULT 'queued',
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
  CONSTRAINT `fk_cl_tenant`    FOREIGN KEY (`tenant_id`)   REFERENCES `tenants`  (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cl_template`  FOREIGN KEY (`template_id`) REFERENCES `message_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cl_sent_by`   FOREIGN KEY (`sent_by`)     REFERENCES `users`    (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Broadcast Messages ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `broadcast_messages` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `title`             VARCHAR(200)    NOT NULL,
  `channel`           ENUM('sms','email','both')                           NOT NULL DEFAULT 'sms',
  `subject`           VARCHAR(255)    DEFAULT NULL COMMENT 'Email subject',
  `message`           TEXT            NOT NULL,
  `template_id`       INT UNSIGNED    DEFAULT NULL,
  `recipient_filter`  JSON            DEFAULT NULL COMMENT 'Filter: {property_id, status, has_overdue}',
  `total_recipients`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `sent_count`        INT UNSIGNED    NOT NULL DEFAULT 0,
  `failed_count`      INT UNSIGNED    NOT NULL DEFAULT 0,
  `status`            ENUM('draft','sending','sent','failed','cancelled')  NOT NULL DEFAULT 'draft',
  `scheduled_at`      DATETIME        DEFAULT NULL,
  `started_at`        DATETIME        DEFAULT NULL,
  `completed_at`      DATETIME        DEFAULT NULL,
  `created_by`        INT UNSIGNED    NOT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bm_status`     (`status`),
  KEY `idx_bm_created_by` (`created_by`),
  CONSTRAINT `fk_bm_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`             (`id`),
  CONSTRAINT `fk_bm_template`   FOREIGN KEY (`template_id`) REFERENCES `message_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default message templates
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
