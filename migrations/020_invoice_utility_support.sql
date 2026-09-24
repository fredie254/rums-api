-- Migration 020: Add utility billing support to invoices
-- Adds invoice_type, unit_id, meter readings to invoices table
-- Creates invoice_items table for line-item breakdown

ALTER TABLE invoices
    ADD COLUMN invoice_type        ENUM('rent','utility','mixed') NOT NULL DEFAULT 'rent' AFTER lease_id,
    ADD COLUMN unit_id             INT UNSIGNED DEFAULT NULL AFTER invoice_type,
    ADD COLUMN meter_reading_prev  DECIMAL(10,4) DEFAULT NULL AFTER notes,
    ADD COLUMN meter_reading_curr  DECIMAL(10,4) DEFAULT NULL AFTER meter_reading_prev,
    ADD KEY idx_invoices_unit_id (unit_id),
    ADD KEY idx_invoices_type (invoice_type);

CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id`  INT UNSIGNED NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `quantity`    DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
  `unit_price`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `subtotal`    DECIMAL(12,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) STORED,
  `item_type`   VARCHAR(50) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_items_invoice` (`invoice_id`),
  CONSTRAINT `fk_invoice_items_invoice`
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
