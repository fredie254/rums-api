-- ============================================================
-- Migration 003: Widen columns for encrypted field storage
-- Created: 2026-06-17
--
-- Run ONCE on an existing database (fresh installs use schema.sql
-- which already includes these definitions).
--
-- What this does:
--   • Widens short VARCHAR / changes DATE/DECIMAL columns to TEXT
--     so they can hold AES-256-GCM ciphertext (base64-encoded).
--   • Adds *_hash shadow columns (SHA-256 of normalised plaintext)
--     to preserve UNIQUE constraint semantics on encrypted fields.
--   • Populates hashes for existing plaintext rows using MySQL's
--     SHA2() — same algorithm as PHP Encryptor::hash().
--   • Swaps UNIQUE indexes from the plaintext column to the hash column.
--
-- After running this migration:
--   • The app encrypts new/updated values automatically.
--   • Existing plaintext rows remain readable (Encryptor::decrypt()
--     returns plaintext as-is when the "enc1:" prefix is absent).
--   • To fully encrypt existing rows, run the companion PHP script:
--       php artisan (or a CLI script) that reads and re-saves each row.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Tenants ───────────────────────────────────────────────────

ALTER TABLE `tenants`
  -- id_number: drop old UNIQUE, widen column, add hash column
  DROP INDEX `uq_tenant_id_number`,
  MODIFY COLUMN `id_number`               TEXT          NOT NULL,
  ADD    COLUMN `id_number_hash`          CHAR(64)      DEFAULT NULL AFTER `id_number`,

  -- phone + contact fields — store encrypted strings
  MODIFY COLUMN `phone`                   TEXT          NOT NULL,

  -- dob was DATE — convert to TEXT so encrypted string can be stored
  MODIFY COLUMN `dob`                     TEXT          DEFAULT NULL,

  -- monthly_income was DECIMAL — convert to TEXT for encrypted storage
  MODIFY COLUMN `monthly_income`          TEXT          DEFAULT NULL,

  -- other PII fields — widen from short VARCHAR to TEXT
  MODIFY COLUMN `occupation`              TEXT          DEFAULT NULL,
  MODIFY COLUMN `employer`               TEXT          DEFAULT NULL,
  MODIFY COLUMN `emergency_contact_name`  TEXT          DEFAULT NULL,
  MODIFY COLUMN `emergency_contact_phone` TEXT          DEFAULT NULL,
  MODIFY COLUMN `next_of_kin_name`        TEXT          DEFAULT NULL,
  MODIFY COLUMN `next_of_kin_phone`       TEXT          DEFAULT NULL;

-- Populate hash for existing plaintext id_number rows
-- SHA2(LOWER(TRIM(col)), 256) matches PHP: hash('sha256', strtolower(trim($value)))
UPDATE `tenants`
  SET `id_number_hash` = SHA2(LOWER(TRIM(`id_number`)), 256)
  WHERE `id_number_hash` IS NULL
    AND `id_number` IS NOT NULL
    AND `id_number` != '';

-- Add unique index on hash column
ALTER TABLE `tenants`
  ADD UNIQUE KEY `uq_tenant_id_number_hash` (`id_number_hash`);

-- ── Landlords ─────────────────────────────────────────────────

ALTER TABLE `landlords`
  -- id_number: drop old UNIQUE, widen, add hash column
  DROP INDEX  `uq_landlords_id_number`,
  MODIFY COLUMN `id_number`    TEXT    DEFAULT NULL,
  ADD    COLUMN `id_number_hash` CHAR(64) DEFAULT NULL AFTER `id_number`,

  -- financial / credential fields
  MODIFY COLUMN `kra_pin`      TEXT    DEFAULT NULL,
  MODIFY COLUMN `bank_account` TEXT    DEFAULT NULL,
  MODIFY COLUMN `mpesa_number` TEXT    DEFAULT NULL;

-- Populate hash for existing landlord id_number rows
UPDATE `landlords`
  SET `id_number_hash` = SHA2(LOWER(TRIM(`id_number`)), 256)
  WHERE `id_number_hash` IS NULL
    AND `id_number` IS NOT NULL
    AND `id_number` != '';

ALTER TABLE `landlords`
  ADD UNIQUE KEY `uq_landlords_id_number_hash` (`id_number_hash`);

SET FOREIGN_KEY_CHECKS = 1;
