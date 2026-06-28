<?php
require_once __DIR__ . '/BaseService.php';

class PaymentService extends BaseService
{
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['tenant_id']))   { $where[] = 'p.tenant_id = ?';       $params[] = (int)$filters['tenant_id']; }
        if (!empty($filters['lease_id']))    { $where[] = 'p.lease_id = ?';        $params[] = (int)$filters['lease_id']; }
        if (!empty($filters['invoice_id']))  { $where[] = 'p.invoice_id = ?';      $params[] = (int)$filters['invoice_id']; }
        if (!empty($filters['method']))      { $where[] = 'p.payment_method = ?';  $params[] = $filters['method']; }
        if (!empty($filters['status']))      { $where[] = 'p.status = ?';          $params[] = $filters['status']; }
        if (!empty($filters['type']))        { $where[] = 'p.payment_type = ?';    $params[] = $filters['type']; }
        if (!empty($filters['date_from']))   { $where[] = 'p.payment_date >= ?';   $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))     { $where[] = 'p.payment_date <= ?';   $params[] = $filters['date_to']; }
        if (!empty($filters['property_id']))  { $where[] = 'u.property_id = ?';    $params[] = (int)$filters['property_id']; }
        if (!empty($filters['landlord_id']))  { $where[] = 'pr.landlord_id = ?';   $params[] = (int)$filters['landlord_id']; }
        if (!empty($filters['no_invoice']))   { $where[] = 'p.invoice_id IS NULL'; }

        $w = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT p.*,
            CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
            i.invoice_number, i.total_amount AS invoice_amount,
            i.amount_paid AS invoice_paid, i.status AS invoice_status,
            u.unit_number, pr.id AS property_id, pr.name AS property_name
            FROM payments p
            LEFT JOIN tenants t      ON t.id  = p.tenant_id
            LEFT JOIN invoices i     ON i.id  = p.invoice_id
            LEFT JOIN leases l       ON l.id  = p.lease_id
            LEFT JOIN units u        ON u.id  = l.unit_id
            LEFT JOIN properties pr  ON pr.id = u.property_id
            $w ORDER BY p.payment_date DESC, p.id DESC";

        $countSql = "SELECT COUNT(*) FROM payments p
            LEFT JOIN leases l      ON l.id  = p.lease_id
            LEFT JOIN units u       ON u.id  = l.unit_id
            LEFT JOIN properties pr ON pr.id = u.property_id $w";

        return $this->paginatedQuery($sql, $params, $countSql, $params, $page, $perPage);
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT p.*,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name, t.phone AS tenant_phone,
                i.invoice_number, i.total_amount AS invoice_total,
                u.unit_number, pr.name AS property_name
             FROM payments p
             LEFT JOIN tenants t     ON t.id  = p.tenant_id
             LEFT JOIN invoices i    ON i.id  = p.invoice_id
             LEFT JOIN leases l      ON l.id  = p.lease_id
             LEFT JOIN units u       ON u.id  = l.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             WHERE p.id = ?",
            [$id]
        );
    }

    public function record(array $data): array
    {
        $missing = $this->requireFields($data, ['lease_id', 'amount', 'payment_date', 'payment_method']);
        if ($missing) return ['success' => false, 'errors' => $missing, 'message' => 'Missing required fields.'];

        $amount   = (float)$data['amount'];
        $lease_id = (int)$data['lease_id'];

        if ($amount <= 0) return ['success' => false, 'message' => 'Amount must be greater than zero.'];

        $lease = $this->fetchOne("SELECT tenant_id FROM leases WHERE id = ?", [$lease_id]);
        if (!$lease) return ['success' => false, 'message' => 'Lease not found.'];

        $payment_ref = 'PAY-' . strtoupper(bin2hex(random_bytes(4)));

        $allowed = $this->only($data, [
            'lease_id', 'invoice_id', 'payment_method', 'payment_date',
            'payment_type', 'notes', 'mpesa_transaction_id', 'cheque_number',
        ]);
        $allowed['amount']      = $amount;
        $allowed['tenant_id']   = $lease['tenant_id'];
        $allowed['payment_ref'] = $payment_ref;
        $allowed['status']      = 'completed';

        $this->db->beginTransaction();
        try {
            $cols   = implode(', ', array_keys($allowed));
            $places = implode(', ', array_fill(0, count($allowed), '?'));
            $pay_id = $this->insert("INSERT INTO payments ($cols) VALUES ($places)", array_values($allowed));

            if (!empty($data['invoice_id'])) {
                $this->reconcileInvoice((int)$data['invoice_id']);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Payment recording failed: ' . $e->getMessage()];
        }

        return ['success' => true, 'id' => $pay_id, 'payment_ref' => $payment_ref, 'message' => 'Payment recorded.'];
    }

    public function reconcileInvoice(int $invoiceId): void
    {
        $inv = $this->fetchOne("SELECT total_amount FROM invoices WHERE id = ?", [$invoiceId]);
        if (!$inv) return;

        $paid  = (float)$this->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = 'completed'",
            [$invoiceId]
        );
        $total = (float)$inv['total_amount'];

        $status = match (true) {
            $paid <= 0      => 'unpaid',
            $paid >= $total => 'paid',
            default         => 'partial',
        };

        $this->execute(
            "UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?",
            [$paid, $status, $invoiceId]
        );
    }

    public function summary(string $dateFrom, string $dateTo, ?int $propertyId = null): array
    {
        $where  = "p.payment_date BETWEEN ? AND ?";
        $params = [$dateFrom, $dateTo];

        if ($propertyId) {
            $where .= " AND u.property_id = ?";
            $params[] = $propertyId;
        }

        $join = "LEFT JOIN leases l ON l.id = p.lease_id LEFT JOIN units u ON u.id = l.unit_id";

        return $this->fetchOne(
            "SELECT
                COUNT(*) AS count,
                COALESCE(SUM(p.amount), 0) AS total,
                COALESCE(SUM(CASE WHEN p.payment_method = 'mpesa' THEN p.amount END), 0) AS mpesa_total,
                COALESCE(SUM(CASE WHEN p.payment_method IN ('bank','bank_transfer') THEN p.amount END), 0) AS bank_total,
                COALESCE(SUM(CASE WHEN p.payment_method = 'cash'   THEN p.amount END), 0) AS cash_total,
                COALESCE(SUM(CASE WHEN p.payment_method = 'cheque' THEN p.amount END), 0) AS cheque_total,
                COALESCE(SUM(CASE WHEN p.payment_method = 'card'   THEN p.amount END), 0) AS card_total,
                COALESCE(SUM(CASE WHEN p.status = 'pending'  THEN p.amount END), 0) AS pending_total,
                COALESCE(SUM(CASE WHEN p.status = 'reversed' THEN p.amount END), 0) AS reversed_total,
                COUNT(CASE WHEN p.status = 'pending'  THEN 1 END) AS pending_count,
                COUNT(CASE WHEN p.status = 'reversed' THEN 1 END) AS reversed_count
             FROM payments p $join WHERE $where",
            $params
        ) ?: [];
    }
}
