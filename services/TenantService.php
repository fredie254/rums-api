<?php
require_once __DIR__ . '/BaseService.php';

class TenantService extends BaseService
{
    /** PII fields stored encrypted at rest */
    private const ENCRYPTED = [
        'phone',
        'id_number',
        'dob',
        'monthly_income',
        'occupation',
        'employer',
        'notes',
        'emergency_contact_name',
        'emergency_contact_phone',
        'next_of_kin_name',
        'next_of_kin_phone',
    ];

    // ── Encryption helpers ────────────────────────────────────

    private static function encryptRow(array $data): array
    {
        return Encryptor::encryptFields($data, self::ENCRYPTED);
    }

    private static function decryptRow(array $row): array
    {
        return Encryptor::decryptFields($row, self::ENCRYPTED);
    }

    // ── Public methods ────────────────────────────────────────

    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            // Only search unencrypted columns (phone is now encrypted, cannot LIKE)
            $where[] = "(CONCAT(t.first_name,' ',t.last_name) LIKE ? OR t.email LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }
        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['property_id'])) {
            $where[] = 'u.property_id = ?';
            $params[] = (int)$filters['property_id'];
        }

        $w = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT t.*,
            CONCAT(t.first_name,' ',t.last_name) AS full_name,
            l.id AS lease_id, l.status AS lease_status,
            u.unit_number, pr.name AS property_name
            FROM tenants t
            LEFT JOIN leases l      ON l.tenant_id = t.id AND l.status = 'active'
            LEFT JOIN units u       ON u.id = l.unit_id
            LEFT JOIN properties pr ON pr.id = u.property_id
            $w ORDER BY t.first_name, t.last_name";

        $countSql = "SELECT COUNT(DISTINCT t.id) FROM tenants t
            LEFT JOIN leases l ON l.tenant_id = t.id AND l.status='active'
            LEFT JOIN units u ON u.id = l.unit_id $w";

        $result = $this->paginatedQuery($sql, $params, $countSql, $params, $page, $perPage);
        $result['data'] = array_map([self::class, 'decryptRow'], $result['data']);
        return $result;
    }

    public function find(int $id): ?array
    {
        $tenant = $this->fetchOne(
            "SELECT t.*, CONCAT(t.first_name,' ',t.last_name) AS full_name, u.name AS user_name
             FROM tenants t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?",
            [$id]
        );
        if (!$tenant) return null;

        $tenant = self::decryptRow($tenant);

        $tenant['active_lease'] = $this->fetchOne(
            "SELECT l.*, u.unit_number, pr.name AS property_name
             FROM leases l
             JOIN units u       ON u.id = l.unit_id
             JOIN properties pr ON pr.id = u.property_id
             WHERE l.tenant_id = ? AND l.status = 'active' LIMIT 1",
            [$id]
        );

        $tenant['payment_summary'] = $this->fetchOne(
            "SELECT COUNT(*) AS payment_count,
                    COALESCE(SUM(amount), 0) AS total_paid,
                    MAX(payment_date)        AS last_payment_date
             FROM payments WHERE tenant_id = ?",
            [$id]
        );

        $tenant['outstanding_balance'] = (float)$this->fetchColumn(
            "SELECT COALESCE(SUM(total_amount - amount_paid), 0)
             FROM invoices WHERE tenant_id = ? AND status IN ('unpaid','partial','overdue')",
            [$id]
        );

        return $tenant;
    }

    public function create(array $data): array
    {
        // ── Link existing user to a tenant profile ────────────
        if (!empty($data['user_id'])) {
            $userId     = (int)$data['user_id'];
            $existingUser = $this->fetchOne(
                "SELECT id, name, email, phone, role FROM users WHERE id = ?", [$userId]
            );
            if (!$existingUser) return ['success' => false, 'message' => 'User not found.'];
            if ($existingUser['role'] !== 'tenant') return ['success' => false, 'message' => 'User role must be tenant.'];

            $already = $this->fetchColumn("SELECT COUNT(*) FROM tenants WHERE user_id = ?", [$userId]);
            if ($already > 0) return ['success' => false, 'message' => 'A tenant profile already exists for this user.'];

            $nameParts  = explode(' ', trim($existingUser['name']), 2);
            $allowed    = $this->only($data, [
                'first_name', 'last_name', 'email', 'phone', 'id_number', 'id_type',
                'dob', 'gender', 'nationality', 'emergency_contact_name', 'emergency_contact_phone',
                'next_of_kin_name', 'next_of_kin_phone', 'occupation', 'employer',
                'monthly_income', 'notes', 'status',
            ]);
            $allowed['user_id']    = $userId;
            $allowed['first_name'] = $allowed['first_name'] ?? $nameParts[0];
            $allowed['last_name']  = $allowed['last_name']  ?? ($nameParts[1] ?? '');
            $allowed['email']      = strtolower(trim($allowed['email'] ?? $existingUser['email']));
            $allowed['phone']      = $allowed['phone'] ?? $existingUser['phone'];
            $allowed['status']     = $allowed['status'] ?? 'active';

            if (!empty($data['id_number'])) {
                $idNumHash = Encryptor::hash($data['id_number']);
                $hashExists = $this->fetchColumn(
                    "SELECT COUNT(*) FROM tenants WHERE id_number_hash = ?", [$idNumHash]
                );
                if ($hashExists > 0) return ['success' => false, 'message' => 'ID number already registered.'];
                $allowed['id_number_hash'] = $idNumHash;
            }

            $allowed = self::encryptRow($allowed);

            $this->db->beginTransaction();
            try {
                $cols   = implode(', ', array_keys($allowed));
                $places = implode(', ', array_fill(0, count($allowed), '?'));
                $id     = $this->insert(
                    "INSERT INTO tenants ($cols) VALUES ($places)", array_values($allowed)
                );
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Failed to create tenant profile: ' . $e->getMessage()];
            }

            return ['success' => true, 'id' => $id, 'user_id' => $userId, 'message' => 'Tenant profile created.'];
        }

        // ── Create new tenant + user account ─────────────────
        $missing = $this->requireFields($data, ['first_name', 'last_name', 'email', 'phone', 'id_number']);
        if ($missing) return ['success' => false, 'errors' => $missing, 'message' => 'Missing required fields.'];

        $email     = strtolower(trim($data['email']));
        $idNumHash = Encryptor::hash($data['id_number']);

        $emailExists = $this->fetchColumn("SELECT COUNT(*) FROM tenants WHERE email = ?", [$email]);
        if ($emailExists > 0) return ['success' => false, 'message' => 'Email already in use.'];

        $userExists = $this->fetchColumn("SELECT COUNT(*) FROM users WHERE email = ?", [$email]);
        if ($userExists > 0) return ['success' => false, 'message' => 'A user account with this email already exists.'];

        $hashExists = $this->fetchColumn(
            "SELECT COUNT(*) FROM tenants WHERE id_number_hash = ?", [$idNumHash]
        );
        if ($hashExists > 0) return ['success' => false, 'message' => 'ID number already registered.'];

        $allowed = $this->only($data, [
            'first_name', 'last_name', 'email', 'phone', 'id_number', 'id_type',
            'dob', 'gender', 'nationality', 'emergency_contact_name', 'emergency_contact_phone',
            'next_of_kin_name', 'next_of_kin_phone', 'occupation', 'employer',
            'monthly_income', 'notes', 'status',
        ]);
        $allowed['email']          = $email;
        $allowed['status']         = $allowed['status'] ?? 'active';
        $allowed['id_number_hash'] = $idNumHash;

        $allowed = self::encryptRow($allowed);

        $this->db->beginTransaction();
        try {
            $defaultPassword = 'Tenant@' . substr(preg_replace('/\D/', '', $data['id_number']), -4);

            $userId = $this->insert(
                "INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, 'tenant', 'active')",
                [
                    trim($data['first_name'] . ' ' . $data['last_name']),
                    $email,
                    password_hash($defaultPassword, PASSWORD_BCRYPT, ['cost' => 10]),
                ]
            );

            $allowed['user_id'] = $userId;
            $cols   = implode(', ', array_keys($allowed));
            $places = implode(', ', array_fill(0, count($allowed), '?'));
            $id     = $this->insert("INSERT INTO tenants ($cols) VALUES ($places)", array_values($allowed));

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to create tenant: ' . $e->getMessage()];
        }

        return [
            'success'          => true,
            'id'               => $id,
            'user_id'          => $userId,
            'default_password' => $defaultPassword,
            'message'          => 'Tenant created. Default password: ' . $defaultPassword,
        ];
    }

    public function update(int $id, array $data): array
    {
        $tenant = $this->find($id); // returns decrypted row
        if (!$tenant) return ['success' => false, 'message' => 'Tenant not found.'];

        $allowed = $this->only($data, [
            'first_name', 'last_name', 'phone', 'id_number', 'id_type',
            'dob', 'gender', 'nationality', 'emergency_contact_name', 'emergency_contact_phone',
            'next_of_kin_name', 'next_of_kin_phone', 'occupation', 'employer',
            'monthly_income', 'notes', 'status',
        ]);
        if (!$allowed) return ['success' => false, 'message' => 'No valid fields to update.'];

        // If id_number is changing, validate uniqueness via hash and update hash column
        if (isset($data['id_number'])) {
            $newHash  = Encryptor::hash($data['id_number']);
            $conflict = $this->fetchColumn(
                "SELECT COUNT(*) FROM tenants WHERE id_number_hash = ? AND id != ?",
                [$newHash, $id]
            );
            if ($conflict > 0) {
                return ['success' => false, 'message' => 'ID number already registered to another tenant.'];
            }
            $allowed['id_number_hash'] = $newHash;
        }

        // Encrypt PII fields before persisting
        $allowed = self::encryptRow($allowed);

        $this->db->beginTransaction();
        try {
            [$set, $vals] = $this->buildSet($allowed);
            $this->execute("UPDATE tenants SET $set WHERE id = ?", [...$vals, $id]);

            // Keep linked users row in sync (plaintext phone goes to users.phone)
            if (!empty($tenant['user_id'])) {
                $userUpdates = [];
                if (isset($data['first_name']) || isset($data['last_name'])) {
                    $firstName           = $data['first_name'] ?? $tenant['first_name'];
                    $lastName            = $data['last_name']  ?? $tenant['last_name'];
                    $userUpdates['name'] = trim("$firstName $lastName");
                }
                if (isset($data['phone'])) {
                    // users.phone is NOT encrypted — store plaintext
                    $userUpdates['phone'] = $data['phone'];
                }
                if (isset($data['status'])) {
                    $userUpdates['status'] = $data['status'] === 'active' ? 'active' : 'inactive';
                }
                if ($userUpdates) {
                    [$uset, $uvals] = $this->buildSet($userUpdates);
                    $this->execute("UPDATE users SET $uset WHERE id = ?", [...$uvals, (int)$tenant['user_id']]);
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Update failed: ' . $e->getMessage()];
        }

        return ['success' => true, 'message' => 'Tenant updated.'];
    }

    public function delete(int $id): array
    {
        $tenant = $this->fetchOne("SELECT id, user_id FROM tenants WHERE id = ?", [$id]);
        if (!$tenant) return ['success' => false, 'message' => 'Tenant not found.'];

        $activeLeases = $this->fetchColumn(
            "SELECT COUNT(*) FROM leases WHERE tenant_id = ? AND status = 'active'", [$id]
        );
        if ($activeLeases > 0) {
            return ['success' => false, 'message' => 'Cannot delete tenant with an active lease. End the lease first.'];
        }

        $this->db->beginTransaction();
        try {
            $this->execute("DELETE FROM tenants WHERE id = ?", [$id]);
            if (!empty($tenant['user_id'])) {
                $this->execute("UPDATE users SET status = 'inactive' WHERE id = ?", [(int)$tenant['user_id']]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()];
        }

        return ['success' => true, 'message' => 'Tenant deleted.'];
    }

    public function getStatement(int $tenantId, string $dateFrom, string $dateTo): array
    {
        $payments = $this->fetchAll(
            "SELECT p.*, i.invoice_number, u.unit_number, pr.name AS property_name
             FROM payments p
             LEFT JOIN invoices i ON i.id = p.invoice_id
             JOIN leases l        ON l.id = p.lease_id
             JOIN units u         ON u.id = l.unit_id
             JOIN properties pr   ON pr.id = u.property_id
             WHERE p.tenant_id = ? AND p.payment_date BETWEEN ? AND ?
             ORDER BY p.payment_date",
            [$tenantId, $dateFrom, $dateTo]
        );

        $invoices = $this->fetchAll(
            "SELECT i.*, u.unit_number, pr.name AS property_name
             FROM invoices i
             JOIN leases l      ON l.id = i.lease_id
             JOIN units u       ON u.id = l.unit_id
             JOIN properties pr ON pr.id = u.property_id
             WHERE i.tenant_id = ? AND i.invoice_date BETWEEN ? AND ?
             ORDER BY i.invoice_date",
            [$tenantId, $dateFrom, $dateTo]
        );

        $openInvoices = array_filter(
            $invoices,
            fn($i) => in_array($i['status'], ['unpaid', 'partial', 'overdue'])
        );

        return [
            'payments'     => $payments,
            'invoices'     => $invoices,
            'total_billed' => array_sum(array_column($invoices, 'total_amount')),
            'total_paid'   => array_sum(array_column($payments, 'amount')),
            'outstanding'  => array_sum(array_column($openInvoices, 'total_amount'))
                            - array_sum(array_column($openInvoices, 'amount_paid')),
        ];
    }
}
