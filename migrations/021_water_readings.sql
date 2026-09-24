-- Migration 021: Water meter readings
-- Each row = one meter reading event for a unit.
-- Opening reading (is_initial=1) is auto-created when a lease is created.
-- Consumption for a period = this reading_value - previous reading_value.

CREATE TABLE IF NOT EXISTS `water_readings` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `unit_id`        INT UNSIGNED    NOT NULL,
  `lease_id`       INT UNSIGNED    DEFAULT NULL,
  `reading_date`   DATE            NOT NULL,
  `reading_value`  DECIMAL(10,4)   NOT NULL DEFAULT 0.0000 COMMENT 'Meter reading in m³',
  `water_rate`     DECIMAL(10,2)   NOT NULL DEFAULT 0.00   COMMENT 'KES per m³ at time of reading',
  `is_initial`     TINYINT(1)      NOT NULL DEFAULT 0      COMMENT '1 = opening meter reading on move-in',
  `invoiced`       TINYINT(1)      NOT NULL DEFAULT 0,
  `invoice_id`     INT UNSIGNED    DEFAULT NULL,
  `notes`          VARCHAR(500)    DEFAULT NULL,
  `recorded_by`    INT UNSIGNED    DEFAULT NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wr_unit_date`  (`unit_id`, `reading_date`),
  KEY `idx_wr_lease`      (`lease_id`),
  CONSTRAINT `fk_wr_unit`  FOREIGN KEY (`unit_id`)  REFERENCES `units`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wr_lease` FOREIGN KEY (`lease_id`) REFERENCES `leases` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
