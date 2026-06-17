-- ============================================================
-- Migration 001: Add missing columns to properties and units
-- Created: 2026-06-17
-- Run once against an existing database that was set up with
-- the original schema.sql (which lacked these columns).
-- Safe to run multiple times (uses IF NOT EXISTS guards).
-- ============================================================

-- ── Properties: add image column ──────────────────────────────
ALTER TABLE `properties`
  ADD COLUMN IF NOT EXISTS `image` VARCHAR(255) DEFAULT NULL AFTER `amenities`;

-- ── Units: add utility and block columns ──────────────────────
ALTER TABLE `units`
  ADD COLUMN IF NOT EXISTS `block_number`         VARCHAR(30)    DEFAULT NULL          AFTER `floor`,
  ADD COLUMN IF NOT EXISTS `water_included`       TINYINT(1)     NOT NULL DEFAULT 0    AFTER `furnished`,
  ADD COLUMN IF NOT EXISTS `electricity_included` TINYINT(1)     NOT NULL DEFAULT 0    AFTER `water_included`,
  ADD COLUMN IF NOT EXISTS `utility_charge`       DECIMAL(12,2)  NOT NULL DEFAULT 0.00 AFTER `electricity_included`;

-- ── Units: add 'reserved' to status ENUM ─────────────────────
-- MySQL only re-writes the table if the definition actually changes,
-- so this is safe to run even if 'reserved' is already present.
ALTER TABLE `units`
  MODIFY COLUMN `status`
    ENUM('available','occupied','maintenance','inactive','reserved')
    NOT NULL DEFAULT 'available';
