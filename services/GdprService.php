<?php
/**
 * RUMS — GDPR Service
 *
 * Handles:
 *  - Right to portability : exportUserData()
 *  - Right to erasure     : anonymizeUser()
 *  - Consent management   : recordConsent(), getConsents()
 *  - Deletion requests    : createDeletionRequest(), listDeletionRequests(), processDeletionRequest()
 */
class GdprService extends BaseService
{
    // ── Right to Portability ──────────────────────────────────

    /**
     * Compile all personal data for a user into a structured array.
     * Decrypts any encrypted fields before export.
     */
    public function exportUserData(int $userId): array
    {
        // User record
        $user = $this->fetchOne(
            "SELECT id, name, email, phone, role, status, last_login, created_at
             FROM users WHERE id = ?",
            [$userId]
        );

        if (!$user) return [];

        // Tenant profile (if applicable)
        $tenant = $this->fetchOne(
            "SELECT id, first_name, last_name, phone, email, national_id,
                    emergency_contact_name, emergency_contact_phone,
                    date_of_birth, move_in_date, status, created_at
             FROM tenants WHERE user_id = ?",
            [$userId]
        );
        // Decrypt sensitive tenant fields
        if ($tenant) {
            foreach (['national_id', 'phone', 'email'] as $field) {
                if (!empty($tenant[$field])) {
                    $tenant[$field] = Encryptor::decrypt($tenant[$field]);
                }
            }
        }

        $tenantId = $tenant['id'] ?? null;

        // Leases
        $leases = [];
        if ($tenantId) {
            $leases = $this->fetchAll(
                "SELECT l.id, l.lease_number, l.start_date, l.end_date,
                        l.monthly_rent, l.status, l.created_at,
                        u.unit_number, p.name AS property_name
                 FROM leases l
                 JOIN units u ON u.id = l.unit_id
                 JOIN properties p ON p.id = u.property_id
                 WHERE l.tenant_id = ?
                 ORDER BY l.start_date DESC",
                [$tenantId]
            );
        }

        // Payments
        $payments = [];
        if ($tenantId) {
            $payments = $this->fetchAll(
                "SELECT p.id, p.payment_date, p.amount, p.payment_method,
                        p.reference_number, p.status, p.notes, p.created_at
                 FROM payments p
                 WHERE p.tenant_id = ?
                 ORDER BY p.payment_date DESC",
                [$tenantId]
            );
        }

        // Invoices
        $invoices = [];
        if ($tenantId) {
            $invoices = $this->fetchAll(
                "SELECT id, invoice_number, issue_date, due_date,
                        total_amount, paid_amount, status, created_at
                 FROM invoices WHERE tenant_id = ?
                 ORDER BY issue_date DESC",
                [$tenantId]
            );
        }

        // Maintenance requests
        $maintenance = [];
        if ($tenantId) {
            $maintenance = $this->fetchAll(
                "SELECT id, title, description, category, priority,
                        status, created_at, resolved_at
                 FROM maintenance_requests WHERE tenant_id = ?
                 ORDER BY created_at DESC",
                [$tenantId]
            );
        }

        // Documents
        $documents = $this->fetchAll(
            "SELECT uuid, title, document_type, category,
                    file_name, file_size, access_level, created_at
             FROM documents
             WHERE uploaded_by = ? AND is_deleted = 0
             ORDER BY created_at DESC",
            [$userId]
        );

        // Notifications
        $notifications = $this->fetchAll(
            "SELECT id, type, title, created_at, read_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 200",
            [$userId]
        );

        // Consent records
        $consents = $this->fetchAll(
            "SELECT consent_type, version, consented, ip_address, created_at
             FROM consent_records
             WHERE user_id = ?
             ORDER BY created_at DESC",
            [$userId]
        );

        // Audit log entries (own actions)
        $auditLog = $this->fetchAll(
            "SELECT action, module, record_id, description, created_at
             FROM audit_logs
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 500",
            [$userId]
        );

        return [
            'export_date'    => date('c'),
            'user_id'        => $userId,
            'user'           => $user,
            'tenant_profile' => $tenant,
            'leases'         => $leases,
            'payments'       => $payments,
            'invoices'       => $invoices,
            'maintenance'    => $maintenance,
            'documents'      => $documents,
            'notifications'  => $notifications,
            'consents'       => $consents,
            'audit_log'      => $auditLog,
        ];
    }

    // ── Right to Erasure ──────────────────────────────────────

    /**
     * Anonymize a user's personal data.
     * Replaces PII with placeholders; preserves financial records for compliance.
     * Should only be called after processing a deletion request.
     */
    public function anonymizeUser(int $userId, int $adminId): array
    {
        $user = $this->fetchOne("SELECT id, role, status FROM users WHERE id = ?", [$userId]);
        if (!$user) return ['success' => false, 'error' => 'User not found.'];

        $anon = 'deleted_' . $userId;

        $this->db->beginTransaction();
        try {
            // Anonymize users table
            $this->execute(
                "UPDATE users SET
                    name           = ?,
                    email          = ?,
                    phone          = NULL,
                    password       = ?,
                    status         = 'inactive',
                    data_anonymized = 1,
                    anonymized_at  = NOW()
                 WHERE id = ?",
                [
                    'Deleted User #' . $userId,
                    $anon . '@deleted.invalid',
                    password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
                    $userId,
                ]
            );

            // Anonymize tenant profile
            $this->execute(
                "UPDATE tenants SET
                    first_name               = 'Deleted',
                    last_name                = ?,
                    phone                    = NULL,
                    email                    = ?,
                    national_id              = NULL,
                    emergency_contact_name   = NULL,
                    emergency_contact_phone  = NULL,
                    date_of_birth            = NULL
                 WHERE user_id = ?",
                ['User #' . $userId, $anon . '@deleted.invalid', $userId]
            );

            // Revoke all API tokens
            $this->execute(
                "UPDATE api_tokens SET revoked = 1 WHERE user_id = ?",
                [$userId]
            );

            // Remove MFA
            $this->execute("DELETE FROM mfa_secrets WHERE user_id = ?", [$userId]);
            $this->execute("DELETE FROM mfa_backup_codes WHERE user_id = ?", [$userId]);

            // Soft-delete documents (keep records for audit but remove from active use)
            $this->execute(
                "UPDATE documents SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?
                 WHERE uploaded_by = ? AND is_deleted = 0",
                [$adminId, $userId]
            );

            // Remove personal notifications
            $this->execute("DELETE FROM notifications WHERE user_id = ?", [$userId]);

            $this->db->commit();
            return ['success' => true];

        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('[GdprService] anonymizeUser failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Anonymization failed.'];
        }
    }

    // ── Consent Management ────────────────────────────────────

    public function recordConsent(
        int    $userId,
        string $consentType,
        bool   $consented,
        string $version    = '1.0',
        ?string $ip        = null,
        ?string $userAgent = null
    ): void {
        $this->execute(
            "INSERT INTO consent_records
                (user_id, consent_type, version, consented, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$userId, $consentType, $version, $consented ? 1 : 0, $ip, $userAgent]
        );
    }

    public function getConsents(int $userId): array
    {
        // Return latest consent for each type
        return $this->fetchAll(
            "SELECT cr.*
             FROM consent_records cr
             INNER JOIN (
                 SELECT consent_type, MAX(id) AS max_id
                 FROM consent_records
                 WHERE user_id = ?
                 GROUP BY consent_type
             ) latest ON latest.max_id = cr.id
             ORDER BY cr.consent_type",
            [$userId]
        );
    }

    public function getConsentHistory(int $userId): array
    {
        return $this->fetchAll(
            "SELECT consent_type, version, consented, ip_address, created_at
             FROM consent_records WHERE user_id = ?
             ORDER BY created_at DESC",
            [$userId]
        );
    }

    // ── Deletion Requests ─────────────────────────────────────

    public function createDeletionRequest(int $userId, ?string $reason): array
    {
        // Check if an open request already exists
        $existing = $this->fetchOne(
            "SELECT id, status FROM data_deletion_requests
             WHERE user_id = ? AND status IN ('pending','processing')
             LIMIT 1",
            [$userId]
        );
        if ($existing) {
            return ['success' => false, 'error' => 'A deletion request is already pending.'];
        }

        $this->execute(
            "INSERT INTO data_deletion_requests (user_id, reason) VALUES (?, ?)",
            [$userId, $reason]
        );
        return ['success' => true, 'id' => (int)$this->db->lastInsertId()];
    }

    public function listDeletionRequests(string $status = 'all', int $page = 1, int $perPage = 25): array
    {
        $where  = $status !== 'all' ? "WHERE r.status = ?" : "WHERE 1=1";
        $params = $status !== 'all' ? [$status] : [];

        $total = $this->fetchColumn(
            "SELECT COUNT(*) FROM data_deletion_requests r $where",
            $params
        );

        $offset = ($page - 1) * $perPage;
        $rows   = $this->fetchAll(
            "SELECT r.*, u.name AS user_name, u.email AS user_email,
                    a.name AS processed_by_name
             FROM data_deletion_requests r
             JOIN users u ON u.id = r.user_id
             LEFT JOIN users a ON a.id = r.processed_by
             $where
             ORDER BY r.requested_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        return [
            'data' => $rows,
            'meta' => [
                'total'       => (int)$total,
                'per_page'    => $perPage,
                'current_page'=> $page,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ];
    }

    public function processDeletionRequest(int $requestId, string $action, int $adminId, ?string $notes): array
    {
        $req = $this->fetchOne(
            "SELECT * FROM data_deletion_requests WHERE id = ?",
            [$requestId]
        );
        if (!$req) return ['success' => false, 'error' => 'Request not found.'];
        if (!in_array($req['status'], ['pending', 'processing'])) {
            return ['success' => false, 'error' => 'Request already processed.'];
        }

        if ($action === 'approve') {
            $result = $this->anonymizeUser((int)$req['user_id'], $adminId);
            if (!$result['success']) return $result;

            $this->execute(
                "UPDATE data_deletion_requests
                 SET status = 'completed', processed_at = NOW(), processed_by = ?, admin_notes = ?
                 WHERE id = ?",
                [$adminId, $notes, $requestId]
            );
            return ['success' => true, 'action' => 'anonymized'];
        }

        // reject
        $this->execute(
            "UPDATE data_deletion_requests
             SET status = 'rejected', processed_at = NOW(), processed_by = ?, admin_notes = ?
             WHERE id = ?",
            [$adminId, $notes, $requestId]
        );
        return ['success' => true, 'action' => 'rejected'];
    }

    // ── Data Export Token ─────────────────────────────────────

    public function createExportRequest(int $userId): array
    {
        $token = bin2hex(random_bytes(32));
        $this->execute(
            "INSERT INTO data_export_requests (user_id, download_token, token_expires)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))",
            [$userId, $token]
        );
        return ['success' => true, 'token' => $token, 'expires_in' => 3600];
    }

    public function resolveExportToken(string $token): ?int
    {
        $row = $this->fetchOne(
            "SELECT user_id, status FROM data_export_requests
             WHERE download_token = ?
               AND token_expires > NOW()
               AND status = 'pending'",
            [$token]
        );

        if (!$row) return null;

        // Invalidate the token (single-use)
        $this->execute(
            "UPDATE data_export_requests
             SET status = 'completed', completed_at = NOW()
             WHERE download_token = ?",
            [$token]
        );

        return (int)$row['user_id'];
    }
}
