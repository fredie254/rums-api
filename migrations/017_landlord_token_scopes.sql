-- Expand scopes for existing landlord/owner tokens to match the new role permissions.
-- Users who are already logged in won't get the new scopes until their token is
-- refreshed, so we patch existing active tokens here.

UPDATE api_tokens t
JOIN users u ON u.id = t.user_id
SET t.scopes = 'read:properties,write:properties,read:units,write:units,read:tenants,write:tenants,read:leases,write:leases,read:payments,write:payments,read:invoices,write:invoices,read:maintenance,write:maintenance,read:reports'
WHERE u.role IN ('landlord', 'owner')
  AND (t.expires_at IS NULL OR t.expires_at > NOW());
