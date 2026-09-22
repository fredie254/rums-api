-- Performance indexes — covers the most common WHERE and JOIN patterns
-- Run ANALYZE TABLE after applying to update query planner statistics.

-- ── invoices ──────────────────────────────────────────────────────────────────
-- status is in every invoice list query; due_date in overdue checks
ALTER TABLE invoices
    ADD INDEX IF NOT EXISTS idx_inv_status     (status),
    ADD INDEX IF NOT EXISTS idx_inv_due_date   (due_date),
    ADD INDEX IF NOT EXISTS idx_inv_lease_stat (lease_id, status),       -- lease detail + unpaid filter
    ADD INDEX IF NOT EXISTS idx_inv_created    (created_at);

-- ── leases ────────────────────────────────────────────────────────────────────
-- status = 'active' is in virtually every lease join; end_date for expiry checks
ALTER TABLE leases
    ADD INDEX IF NOT EXISTS idx_lease_status          (status),
    ADD INDEX IF NOT EXISTS idx_lease_status_end      (status, end_date),  -- expiring leases
    ADD INDEX IF NOT EXISTS idx_lease_unit_status     (unit_id, status);   -- unit → active lease

-- ── payments ─────────────────────────────────────────────────────────────────
-- payment_date drives all revenue/chart queries; status in collection reports
ALTER TABLE payments
    ADD INDEX IF NOT EXISTS idx_pay_status          (status),
    ADD INDEX IF NOT EXISTS idx_pay_payment_date    (payment_date),
    ADD INDEX IF NOT EXISTS idx_pay_stat_date       (status, payment_date),  -- revenue chart
    ADD INDEX IF NOT EXISTS idx_pay_tenant_stat     (tenant_id, status);

-- ── units ─────────────────────────────────────────────────────────────────────
-- status used in occupancy queries; property_id+status for property occupancy
ALTER TABLE units
    ADD INDEX IF NOT EXISTS idx_unit_status          (status),
    ADD INDEX IF NOT EXISTS idx_unit_prop_status     (property_id, status);

-- ── maintenance_requests ──────────────────────────────────────────────────────
-- status + priority combined for dashboard urgent/open count
ALTER TABLE maintenance_requests
    ADD INDEX IF NOT EXISTS idx_maint_status       (status),
    ADD INDEX IF NOT EXISTS idx_maint_priority     (priority),
    ADD INDEX IF NOT EXISTS idx_maint_stat_pri     (status, priority),
    ADD INDEX IF NOT EXISTS idx_maint_created      (created_at);

-- ── notifications ─────────────────────────────────────────────────────────────
-- user_id + is_read for unread badge count (loaded on every page)
ALTER TABLE notifications
    ADD INDEX IF NOT EXISTS idx_notif_user_read  (user_id, is_read),
    ADD INDEX IF NOT EXISTS idx_notif_created    (created_at);

-- ── mpesa_configs (new table — add at creation) ───────────────────────────────
-- shortcode lookup happens on every Safaricom webhook
ALTER TABLE mpesa_configs
    ADD INDEX IF NOT EXISTS idx_mc_is_active (is_active);

-- ── properties ────────────────────────────────────────────────────────────────
ALTER TABLE properties
    ADD INDEX IF NOT EXISTS idx_prop_status (status);

-- Refresh planner stats for the tables we just indexed
ANALYZE TABLE invoices, leases, payments, units, maintenance_requests, notifications, properties;
