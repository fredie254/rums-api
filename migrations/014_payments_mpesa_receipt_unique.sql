-- Prevent double-recording if Safaricom retries the same C2B confirmation
-- simultaneously (race condition on the application-level check).
-- NULL values are excluded from unique constraints in MySQL.
ALTER TABLE payments
    ADD UNIQUE KEY uk_mpesa_receipt (mpesa_receipt);
