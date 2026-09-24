-- Migration 022: Meter number per unit
ALTER TABLE `units`
  ADD COLUMN `meter_number` VARCHAR(50) DEFAULT NULL AFTER `unit_number`;
