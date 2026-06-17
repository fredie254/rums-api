-- Migration 006: Payment Module — fix payment_method enum + bank reconciliation table
-- Run after 005_billing_engine.sql

-- ── Fix payment_method enum ───────────────────────────────────────
ALTER TABLE payments
    MODIFY COLUMN payment_method
        ENUM('cash','mpesa','bank','bank_transfer','cheque','card','other')
        NOT NULL DEFAULT 'cash';

-- ── Bank Statement Entries (for bank reconciliation) ──────────────
CREATE TABLE IF NOT EXISTS `bank_statement_entries` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `import_batch`   VARCHAR(50)     NOT NULL COMMENT 'Groups entries from the same CSV import',
  `statement_date` DATE            NOT NULL,
  `value_date`     DATE            DEFAULT NULL,
  `description`    VARCHAR(500)    DEFAULT NULL,
  `debit`          DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `credit`         DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `balance`        DECIMAL(12,2)   DEFAULT NULL,
  `reference`      VARCHAR(150)    DEFAULT NULL COMMENT 'Bank reference / narration',
  `payment_id`     INT UNSIGNED    DEFAULT NULL COMMENT 'Matched RUMS payment',
  `matched_by`     INT UNSIGNED    DEFAULT NULL,
  `matched_at`     DATETIME        DEFAULT NULL,
  `imported_by`    INT UNSIGNED    NOT NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bse_batch`       (`import_batch`),
  KEY `idx_bse_date`        (`statement_date`),
  KEY `idx_bse_payment_id`  (`payment_id`),
  CONSTRAINT `fk_bse_payment`    FOREIGN KEY (`payment_id`)  REFERENCES `payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bse_imported`   FOREIGN KEY (`imported_by`) REFERENCES `users`    (`id`),
  CONSTRAINT `fk_bse_matched_by` FOREIGN KEY (`matched_by`)  REFERENCES `users`    (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
