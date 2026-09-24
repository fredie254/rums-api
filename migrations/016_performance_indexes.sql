-- Performance indexes — covers the most common WHERE and JOIN patterns
-- MySQL 5.7 compatible (dynamic check via information_schema)
-- Run ANALYZE TABLE after applying to update query planner statistics.

-- ── invoices ──────────────────────────────────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_inv_status')=0,'ALTER TABLE invoices ADD INDEX idx_inv_status (status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_inv_due_date')=0,'ALTER TABLE invoices ADD INDEX idx_inv_due_date (due_date)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_inv_lease_stat')=0,'ALTER TABLE invoices ADD INDEX idx_inv_lease_stat (lease_id, status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_inv_created')=0,'ALTER TABLE invoices ADD INDEX idx_inv_created (created_at)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ── leases ────────────────────────────────────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='leases' AND INDEX_NAME='idx_lease_status')=0,'ALTER TABLE leases ADD INDEX idx_lease_status (status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='leases' AND INDEX_NAME='idx_lease_status_end')=0,'ALTER TABLE leases ADD INDEX idx_lease_status_end (status, end_date)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='leases' AND INDEX_NAME='idx_lease_unit_status')=0,'ALTER TABLE leases ADD INDEX idx_lease_unit_status (unit_id, status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ── payments ──────────────────────────────────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_status')=0,'ALTER TABLE payments ADD INDEX idx_pay_status (status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_payment_date')=0,'ALTER TABLE payments ADD INDEX idx_pay_payment_date (payment_date)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_stat_date')=0,'ALTER TABLE payments ADD INDEX idx_pay_stat_date (status, payment_date)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_tenant_stat')=0,'ALTER TABLE payments ADD INDEX idx_pay_tenant_stat (tenant_id, status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ── units ─────────────────────────────────────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='units' AND INDEX_NAME='idx_unit_status')=0,'ALTER TABLE units ADD INDEX idx_unit_status (status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='units' AND INDEX_NAME='idx_unit_prop_status')=0,'ALTER TABLE units ADD INDEX idx_unit_prop_status (property_id, status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ── maintenance_requests ──────────────────────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_requests' AND INDEX_NAME='idx_maint_status')=0,'ALTER TABLE maintenance_requests ADD INDEX idx_maint_status (status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_requests' AND INDEX_NAME='idx_maint_priority')=0,'ALTER TABLE maintenance_requests ADD INDEX idx_maint_priority (priority)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_requests' AND INDEX_NAME='idx_maint_stat_pri')=0,'ALTER TABLE maintenance_requests ADD INDEX idx_maint_stat_pri (status, priority)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='maintenance_requests' AND INDEX_NAME='idx_maint_created')=0,'ALTER TABLE maintenance_requests ADD INDEX idx_maint_created (created_at)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ── notifications ─────────────────────────────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND INDEX_NAME='idx_notif_user_read')=0,'ALTER TABLE notifications ADD INDEX idx_notif_user_read (user_id, is_read)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND INDEX_NAME='idx_notif_created')=0,'ALTER TABLE notifications ADD INDEX idx_notif_created (created_at)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ── mpesa_configs ─────────────────────────────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mpesa_configs' AND INDEX_NAME='idx_mc_is_active')=0,'ALTER TABLE mpesa_configs ADD INDEX idx_mc_is_active (is_active)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ── properties ────────────────────────────────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='properties' AND INDEX_NAME='idx_prop_status')=0,'ALTER TABLE properties ADD INDEX idx_prop_status (status)','SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Refresh planner stats
ANALYZE TABLE invoices, leases, payments, units, maintenance_requests, notifications, mpesa_configs, properties;
