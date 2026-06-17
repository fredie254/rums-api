-- Migration 005: Billing Engine — period columns + discount columns on invoices
-- Run after 004_lease_engine.sql

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS period_month   TINYINT UNSIGNED   NULL COMMENT 'Invoice billing month (1-12)'  AFTER amount_paid,
    ADD COLUMN IF NOT EXISTS period_year    SMALLINT UNSIGNED  NULL COMMENT 'Invoice billing year'           AFTER period_month,
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(12,2)     NOT NULL DEFAULT 0.00                         AFTER penalty_amount;

-- Back-fill period columns from invoice_date where already NULL
UPDATE invoices
SET period_month = MONTH(invoice_date),
    period_year  = YEAR(invoice_date)
WHERE period_month IS NULL OR period_year IS NULL;

-- Index for period look-ups (bulk generation duplicate check)
CREATE INDEX IF NOT EXISTS idx_invoices_period ON invoices (lease_id, period_year, period_month);
