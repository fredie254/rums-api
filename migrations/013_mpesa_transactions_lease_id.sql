-- Add lease_id to mpesa_transactions so STK callbacks can resolve the tenant
-- without a second lookup through the payments table.
ALTER TABLE mpesa_transactions
    ADD COLUMN IF NOT EXISTS lease_id INT NULL AFTER payment_id,
    ADD CONSTRAINT fk_mptx_lease FOREIGN KEY (lease_id) REFERENCES leases (id) ON DELETE SET NULL;
