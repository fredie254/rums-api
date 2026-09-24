-- Migration 019: Add utility rate fields to units table
-- water_rate: KES per cubic meter of water consumed
-- garbage_fee: fixed monthly garbage collection charge
-- service_fee: fixed monthly service/maintenance charge

ALTER TABLE units
    ADD COLUMN water_rate    DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER utility_charge,
    ADD COLUMN garbage_fee   DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER water_rate,
    ADD COLUMN service_fee   DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER garbage_fee;
