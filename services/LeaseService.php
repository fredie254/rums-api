<?php
require_once __DIR__ . '/BaseService.php';

class LeaseService extends BaseService
{
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = "(l.lease_number LIKE ? OR CONCAT(t.first_name,' ',t.last_name) LIKE ? OR u.unit_number LIKE ?)";
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s;
        }
        if (!empty($filters['status']))      { $where[] = 'l.status = ?';      $params[] = $filters['status']; }
        if (!empty($filters['property_id'])) { $where[] = 'u.property_id = ?'; $params[] = (int)$filters['property_id']; }
        if (!empty($filters['tenant_id']))   { $where[] = 'l.tenant_id = ?';   $params[] = (int)$filters['tenant_id']; }
        if (!empty($filters['unit_id']))     { $where[] = 'l.unit_id = ?';     $params[] = (int)$filters['unit_id']; }

        $w = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT l.id, l.lease_number, l.lease_type, l.status,
                    l.start_date, l.end_date, l.monthly_rent, l.deposit_amount,
                    l.payment_day, l.escalation_type, l.next_escalation_date,
                    l.signed_at,
                    CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                    t.phone AS tenant_phone,
                    u.unit_number, u.rent_amount, pr.name AS property_name,
                    DATEDIFF(l.end_date, CURDATE()) AS days_remaining
                FROM leases l
                JOIN tenants t     ON t.id = l.tenant_id
                JOIN units u       ON u.id = l.unit_id
                JOIN properties pr ON pr.id = u.property_id
                $w ORDER BY l.created_at DESC";

        $countSql = "SELECT COUNT(*) FROM leases l
                     JOIN units u ON u.id = l.unit_id $w";

        $result = $this->paginatedQuery($sql, $params, $countSql, $params, $page, $perPage);

        // Decrypt tenant phone (stored encrypted at rest)
        foreach ($result['data'] as &$row) {
            if (!empty($row['tenant_phone'])) {
                $row['tenant_phone'] = Encryptor::decrypt($row['tenant_phone']);
            }
        }

        return $result;
    }

    public function find(int $id): ?array
    {
        $lease = $this->fetchOne(
            "SELECT l.*,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                t.email AS tenant_email, t.phone AS tenant_phone,
                u.unit_number, u.rent_amount, u.unit_type,
                pr.name AS property_name,
                sb.name AS signed_by_name,
                DATEDIFF(l.end_date, CURDATE()) AS days_remaining
             FROM leases l
             JOIN tenants t     ON t.id = l.tenant_id
             JOIN units u       ON u.id = l.unit_id
             JOIN properties pr ON pr.id = u.property_id
             LEFT JOIN users sb ON sb.id = l.signed_by
             WHERE l.id = ?",
            [$id]
        );
        if (!$lease) return null;

        // Decrypt tenant PII
        if (!empty($lease['tenant_phone'])) {
            $lease['tenant_phone'] = Encryptor::decrypt($lease['tenant_phone']);
        }

        $lease['invoices'] = $this->fetchAll(
            "SELECT id, invoice_number, invoice_date, due_date, total_amount, amount_paid, status
             FROM invoices WHERE lease_id = ? ORDER BY invoice_date DESC LIMIT 12",
            [$id]
        );

        $lease['payments'] = $this->fetchAll(
            "SELECT id, payment_ref, amount, payment_date, payment_method, status
             FROM payments WHERE lease_id = ? ORDER BY payment_date DESC LIMIT 12",
            [$id]
        );

        $lease['documents'] = $this->fetchAll(
            "SELECT d.id, d.document_type, d.original_name, d.file_path,
                    d.file_size, d.mime_type, d.notes, d.created_at,
                    u.name AS uploaded_by_name
             FROM lease_documents d
             LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE d.lease_id = ? ORDER BY d.created_at DESC",
            [$id]
        );

        $lease['renewals'] = $this->fetchAll(
            "SELECT r.id, r.old_end_date, r.new_end_date,
                    r.old_monthly_rent, r.new_monthly_rent,
                    r.notes, r.status, r.created_at,
                    u.name AS initiated_by_name
             FROM lease_renewals r
             LEFT JOIN users u ON u.id = r.initiated_by
             WHERE r.original_lease_id = ? ORDER BY r.created_at DESC",
            [$id]
        );

        return $lease;
    }

    public function create(array $data): array
    {
        $missing = $this->requireFields($data, ['unit_id','tenant_id','start_date','end_date','monthly_rent']);
        if ($missing) return ['success' => false, 'errors' => $missing, 'message' => 'Missing required fields.'];

        $unit = $this->fetchOne("SELECT id, status FROM units WHERE id = ?", [(int)$data['unit_id']]);
        if (!$unit) return ['success' => false, 'message' => 'Unit not found.'];
        if ($unit['status'] !== 'available') {
            return ['success' => false, 'message' => "Unit is currently {$unit['status']}."];
        }

        $overlap = $this->fetchColumn(
            "SELECT COUNT(*) FROM leases
             WHERE unit_id = ? AND status = 'active'
               AND NOT (end_date < ? OR start_date > ?)",
            [(int)$data['unit_id'], $data['start_date'], $data['end_date']]
        );
        if ($overlap > 0) {
            return ['success' => false, 'message' => 'Unit already has an active lease in that period.'];
        }

        $allowed = $this->only($data, [
            'unit_id', 'tenant_id', 'start_date', 'end_date', 'monthly_rent',
            'deposit_amount', 'deposit_paid_date', 'payment_day', 'grace_period_days',
            'penalty_rate', 'terms', 'notes',
            'lease_type', 'template_id', 'renewed_from_id', 'notice_period_days',
            'escalation_type', 'escalation_rate', 'escalation_frequency', 'next_escalation_date',
        ]);
        // Placeholder satisfies NOT NULL + UNIQUE; real number is written inside
        // the transaction once we have the guaranteed-unique auto-increment id.
        // This replaces the previous SELECT MAX(id)+1 which had a race condition
        // under concurrent lease creation.
        $allowed['lease_number'] = 'PENDING-' . bin2hex(random_bytes(8));
        $allowed['status']       = 'active';

        // Auto-compute first escalation date when escalation is configured
        if (
            empty($allowed['next_escalation_date']) &&
            !empty($allowed['escalation_type']) &&
            $allowed['escalation_type'] !== 'none'
        ) {
            $freq = $allowed['escalation_frequency'] ?? 'annually';
            $base = $data['start_date'];
            $allowed['next_escalation_date'] = match($freq) {
                'quarterly'  => date('Y-m-d', strtotime($base . ' +3 months')),
                'biannually' => date('Y-m-d', strtotime($base . ' +6 months')),
                default      => date('Y-m-d', strtotime($base . ' +1 year')),
            };
        }

        $cols   = implode(', ', array_keys($allowed));
        $places = implode(', ', array_fill(0, count($allowed), '?'));

        $this->db->beginTransaction();
        try {
            $id           = $this->insert("INSERT INTO leases ($cols) VALUES ($places)", array_values($allowed));
            $lease_number = sprintf('LSE-%04d-%05d', (int)date('Y'), $id);
            $this->execute("UPDATE leases SET lease_number = ? WHERE id = ?", [$lease_number, $id]);
            $this->execute("UPDATE units SET status = 'occupied' WHERE id = ?", [(int)$data['unit_id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to create lease: ' . $e->getMessage()];
        }

        return ['success' => true, 'id' => $id, 'lease_number' => $lease_number, 'message' => 'Lease created.'];
    }

    public function terminate(int $id, string $reason = ''): array
    {
        $lease = $this->find($id);
        if (!$lease) return ['success' => false, 'message' => 'Lease not found.'];
        if ($lease['status'] !== 'active') return ['success' => false, 'message' => 'Lease is not active.'];

        $this->db->beginTransaction();
        try {
            $this->execute(
                "UPDATE leases SET status = 'terminated', termination_reason = ?, terminated_at = NOW() WHERE id = ?",
                [$reason, $id]
            );
            $this->execute("UPDATE units SET status = 'available' WHERE id = ?", [$lease['unit_id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to terminate lease.'];
        }

        return ['success' => true, 'message' => 'Lease terminated.'];
    }

    // ── Renewal (extension model — same lease, new end_date/rent) ──
    public function renew(int $id, array $data): array
    {
        $lease = $this->find($id);
        if (!$lease)                          return ['success' => false, 'message' => 'Lease not found.'];
        if ($lease['status'] !== 'active')    return ['success' => false, 'message' => 'Only active leases can be renewed.'];

        $newEndDate  = trim($data['new_end_date'] ?? '');
        $newRent     = (float)($data['new_monthly_rent'] ?? $lease['monthly_rent']);
        $notes       = trim($data['notes'] ?? '');

        if (!$newEndDate) return ['success' => false, 'message' => 'new_end_date is required.'];
        if ($newEndDate <= $lease['end_date']) {
            return ['success' => false, 'message' => 'New end date must be after the current end date.'];
        }

        $user = ApiAuth::user();

        $this->db->beginTransaction();
        try {
            $renewalId = $this->insert(
                "INSERT INTO lease_renewals
                 (original_lease_id, initiated_by, old_end_date, new_end_date,
                  old_monthly_rent, new_monthly_rent, notes, status)
                 VALUES (?,?,?,?,?,?,?,'completed')",
                [$id, $user['id'], $lease['end_date'], $newEndDate,
                 $lease['monthly_rent'], $newRent, $notes ?: null]
            );

            $notesAppend = $notes
                ? "\n[Renewal " . date('Y-m-d') . "]: $notes"
                : "\n[Renewed " . date('Y-m-d') . "]";

            $this->execute(
                "UPDATE leases
                 SET end_date = ?, monthly_rent = ?,
                     notes = CONCAT(COALESCE(notes,''), ?)
                 WHERE id = ?",
                [$newEndDate, $newRent, $notesAppend, $id]
            );

            $this->execute(
                "UPDATE lease_renewals SET new_lease_id = ? WHERE id = ?",
                [$id, $renewalId]
            );

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Renewal failed: ' . $e->getMessage()];
        }

        return ['success' => true, 'renewal_id' => $renewalId, 'message' => 'Lease renewed successfully.'];
    }

    // ── Rent escalation ───────────────────────────────────────
    public function applyEscalation(int $id): array
    {
        $lease = $this->find($id);
        if (!$lease)                       return ['success' => false, 'message' => 'Lease not found.'];
        if ($lease['status'] !== 'active') return ['success' => false, 'message' => 'Only active leases can be escalated.'];

        $escalationType = $lease['escalation_type'] ?? 'none';
        if ($escalationType === 'none') {
            return ['success' => false, 'message' => 'No escalation rule configured for this lease.'];
        }

        $currentRent = (float)$lease['monthly_rent'];
        $rate        = (float)($lease['escalation_rate'] ?? 0);

        $newRent = $escalationType === 'fixed'
            ? round($currentRent + $rate, 2)
            : round($currentRent * (1 + $rate / 100), 2);

        $freq     = $lease['escalation_frequency'] ?? 'annually';
        $nextDate = match($freq) {
            'quarterly'  => date('Y-m-d', strtotime('+3 months')),
            'biannually' => date('Y-m-d', strtotime('+6 months')),
            default      => date('Y-m-d', strtotime('+1 year')),
        };

        $this->db->beginTransaction();
        try {
            $this->execute(
                "UPDATE leases SET monthly_rent = ?, next_escalation_date = ? WHERE id = ?",
                [$newRent, $nextDate, $id]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Escalation failed: ' . $e->getMessage()];
        }

        return [
            'success'              => true,
            'old_rent'             => $currentRent,
            'new_rent'             => $newRent,
            'next_escalation_date' => $nextDate,
            'message'              => sprintf(
                'Rent escalated from %s to %s. Next escalation: %s.',
                number_format($currentRent, 2),
                number_format($newRent, 2),
                $nextDate
            ),
        ];
    }

    public function getExpiring(int $days = 30): array
    {
        $rows = $this->fetchAll(
            "SELECT l.id, l.lease_number, l.status, l.end_date, l.monthly_rent,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name, t.phone AS tenant_phone,
                u.unit_number, pr.name AS property_name,
                DATEDIFF(l.end_date, CURDATE()) AS days_remaining
             FROM leases l
             JOIN tenants t     ON t.id = l.tenant_id
             JOIN units u       ON u.id = l.unit_id
             JOIN properties pr ON pr.id = u.property_id
             WHERE l.status = 'active'
               AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY l.end_date",
            [$days]
        );

        // Decrypt tenant phone
        foreach ($rows as &$row) {
            if (!empty($row['tenant_phone'])) {
                $row['tenant_phone'] = Encryptor::decrypt($row['tenant_phone']);
            }
        }

        return $rows;
    }
}
