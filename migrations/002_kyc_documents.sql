-- ============================================================
-- Migration 002: KYC document repository
-- Created: 2026-06-17
-- ============================================================

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
  KEY `fk_kyc_user` (`uploaded_by`),
  CONSTRAINT `fk_kyc_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kyc_user`   FOREIGN KEY (`uploaded_by`) REFERENCES `users`   (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
