<?php
require_once __DIR__ . '/BaseService.php';

class ReportService extends BaseService
{
    // ── Financial ─────────────────────────────────────────────

    public function financial(string $dateFrom, string $dateTo, ?int $propertyId = null): array
    {
        $pf = $propertyId ? "AND u.property_id = $propertyId" : '';

        $income = $this->fetchAll(
            "SELECT DATE_FORMAT(p.payment_date,'%Y-%m') AS period,
                p.payment_type AS category,
                SUM(p.amount) AS amount, COUNT(*) AS count
             FROM payments p
             LEFT JOIN leases l ON l.id = p.lease_id
             LEFT JOIN units u  ON u.id = l.unit_id
             WHERE p.payment_date BETWEEN ? AND ? $pf
             GROUP BY period, p.payment_type
             ORDER BY period",
            [$dateFrom, $dateTo]
        );

        $expenses = $this->fetchAll(
            "SELECT DATE_FORMAT(e.expense_date,'%Y-%m') AS period,
                e.category, SUM(e.amount) AS amount, COUNT(*) AS count
             FROM expenses e
             WHERE e.expense_date BETWEEN ? AND ?
               AND e.status IN ('approved','paid')
               " . ($propertyId ? "AND e.property_id = $propertyId" : '') . "
             GROUP BY period, e.category ORDER BY period",
            [$dateFrom, $dateTo]
        );

        $summary = $this->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN p.payment_date BETWEEN ? AND ? THEN p.amount END), 0) AS total_income,
                (SELECT COALESCE(SUM(amount), 0) FROM expenses
                 WHERE expense_date BETWEEN ? AND ? AND status IN ('approved','paid')
                 " . ($propertyId ? "AND property_id = $propertyId" : '') . ") AS total_expenses,
                (SELECT COALESCE(SUM(total_amount - amount_paid), 0) FROM invoices
                 WHERE status IN ('unpaid','partial','overdue')) AS outstanding_ar
             FROM payments p
             LEFT JOIN leases l ON l.id = p.lease_id
             LEFT JOIN units u  ON u.id = l.unit_id
             WHERE 1=1 $pf",
            [$dateFrom, $dateTo, $dateFrom, $dateTo]
        );

        return [
            'summary'  => $summary,
            'income'   => $income,
            'expenses' => $expenses,
            'net'      => (float)($summary['total_income'] ?? 0) - (float)($summary['total_expenses'] ?? 0),
        ];
    }

    // ── Occupancy ─────────────────────────────────────────────

    public function occupancy(?int $propertyId = null): array
    {
        $where = $propertyId ? "WHERE p.id = $propertyId" : '';

        $by_property = $this->fetchAll(
            "SELECT p.name AS property_name,
                COUNT(u.id)                                         AS total_units,
                SUM(u.status = 'occupied')                          AS occupied,
                SUM(u.status = 'available')                         AS available,
                SUM(u.status = 'maintenance')                       AS maintenance,
                ROUND(SUM(u.status='occupied') / COUNT(u.id) * 100, 1) AS occupancy_rate
             FROM properties p
             LEFT JOIN units u ON u.property_id = p.id
             $where GROUP BY p.id ORDER BY p.name",
            []
        );

        $by_type = $this->fetchAll(
            "SELECT u.unit_type,
                COUNT(*) AS total,
                SUM(u.status='occupied')  AS occupied,
                SUM(u.status='available') AS available
             FROM units u
             " . ($propertyId ? "WHERE u.property_id = $propertyId" : '') . "
             GROUP BY u.unit_type",
            []
        );

        $trend = $this->fetchAll(
            "SELECT DATE_FORMAT(l.start_date,'%Y-%m') AS month,
                COUNT(DISTINCT l.id) AS new_leases
             FROM leases l JOIN units u ON u.id = l.unit_id
             WHERE l.start_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
             " . ($propertyId ? "AND u.property_id = $propertyId" : '') . "
             GROUP BY month ORDER BY month",
            []
        );

        $totals = $this->fetchOne(
            "SELECT COUNT(*) AS total,
                SUM(status='occupied')    AS occupied,
                SUM(status='available')   AS available,
                SUM(status='maintenance') AS maintenance
             FROM units " . ($propertyId ? "WHERE property_id = $propertyId" : ''),
            []
        );

        return compact('totals', 'by_property', 'by_type', 'trend');
    }

    // ── Maintenance ───────────────────────────────────────────

    public function maintenance(string $dateFrom, string $dateTo, ?int $propertyId = null): array
    {
        $pf = $propertyId ? "AND u.property_id = $propertyId" : '';

        $summary = $this->fetchOne(
            "SELECT COUNT(*) AS total,
                SUM(mr.status='open')        AS open,
                SUM(mr.status='in_progress') AS in_progress,
                SUM(mr.status='completed')   AS completed,
                SUM(mr.priority='urgent')    AS urgent,
                SUM(mr.priority='high')      AS high,
                COALESCE(SUM(mr.materials_cost + mr.labour_cost), 0) AS total_cost,
                AVG(CASE WHEN mr.status='completed'
                    THEN DATEDIFF(mr.work_completed, mr.created_at) END) AS avg_days
             FROM maintenance_requests mr
             LEFT JOIN units u ON u.id = mr.unit_id
             WHERE DATE(mr.created_at) BETWEEN ? AND ? $pf",
            [$dateFrom, $dateTo]
        );

        $byCategory = $this->fetchAll(
            "SELECT mr.category, COUNT(*) AS count,
                COALESCE(SUM(mr.materials_cost + mr.labour_cost), 0) AS total_cost
             FROM maintenance_requests mr
             LEFT JOIN units u ON u.id = mr.unit_id
             WHERE DATE(mr.created_at) BETWEEN ? AND ? $pf
             GROUP BY mr.category ORDER BY count DESC",
            [$dateFrom, $dateTo]
        );

        $byProperty = $this->fetchAll(
            "SELECT pr.name, COUNT(*) AS count,
                SUM(mr.status='completed') AS resolved,
                COALESCE(SUM(mr.materials_cost + mr.labour_cost), 0) AS cost
             FROM maintenance_requests mr
             LEFT JOIN units u      ON u.id  = mr.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             WHERE DATE(mr.created_at) BETWEEN ? AND ?
             GROUP BY pr.id ORDER BY count DESC",
            [$dateFrom, $dateTo]
        );

        $trend = $this->fetchAll(
            "SELECT DATE_FORMAT(mr.created_at,'%Y-%m') AS month, COUNT(*) AS count
             FROM maintenance_requests mr
             LEFT JOIN units u ON u.id = mr.unit_id
             WHERE DATE(mr.created_at) BETWEEN ? AND ? $pf
             GROUP BY month ORDER BY month",
            [$dateFrom, $dateTo]
        );

        return [
            'summary'     => $summary,
            'by_category' => $byCategory,
            'by_property' => $byProperty,
            'trend'       => $trend,
        ];
    }

    // ── Tenant Ledger ─────────────────────────────────────────
    // Returns a chronological debit/credit statement for one tenant.

    public function ledger(int $tenantId, string $dateFrom, string $dateTo): array
    {
        // Debits = invoices issued in the period
        $debits = $this->fetchAll(
            "SELECT
                i.invoice_date         AS entry_date,
                'debit'                AS entry_type,
                i.invoice_number       AS reference,
                CONCAT('Invoice — ', IFNULL(CONCAT(MONTHNAME(STR_TO_DATE(i.period_month,'%m')),' ',i.period_year), DATE_FORMAT(i.invoice_date,'%b %Y'))) AS description,
                i.total_amount         AS amount,
                i.status               AS status,
                i.id                   AS source_id
             FROM invoices i
             WHERE i.tenant_id = ?
               AND i.invoice_date BETWEEN ? AND ?
               AND i.status != 'cancelled'
             ORDER BY i.invoice_date, i.id",
            [$tenantId, $dateFrom, $dateTo]
        );

        // Credits = completed payments in the period
        $credits = $this->fetchAll(
            "SELECT
                p.payment_date         AS entry_date,
                'credit'               AS entry_type,
                p.payment_ref          AS reference,
                CONCAT('Payment — ', REPLACE(p.payment_method,'_',' ')) AS description,
                p.amount               AS amount,
                p.status               AS status,
                p.id                   AS source_id
             FROM payments p
             WHERE p.tenant_id = ?
               AND p.payment_date BETWEEN ? AND ?
               AND p.status = 'completed'
             ORDER BY p.payment_date, p.id",
            [$tenantId, $dateFrom, $dateTo]
        );

        // Merge and sort chronologically
        $rows = array_merge($debits, $credits);
        usort($rows, fn($a, $b) =>
            strcmp($a['entry_date'], $b['entry_date']) ?: ($a['entry_type'] <=> $b['entry_type'])
        );

        // Compute running balance (debits increase, credits decrease)
        $balance = 0.0;
        foreach ($rows as &$row) {
            if ($row['entry_type'] === 'debit') {
                $balance += (float)$row['amount'];
            } else {
                $balance -= (float)$row['amount'];
            }
            $row['running_balance'] = round($balance, 2);
        }
        unset($row);

        // Totals
        $totalDebits  = array_sum(array_column(array_filter($rows, fn($r) => $r['entry_type'] === 'debit'),  'amount'));
        $totalCredits = array_sum(array_column(array_filter($rows, fn($r) => $r['entry_type'] === 'credit'), 'amount'));

        return [
            'tenant_id'     => $tenantId,
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'total_debits'  => round($totalDebits,  2),
            'total_credits' => round($totalCredits, 2),
            'closing_balance' => round($totalDebits - $totalCredits, 2),
            'entries'       => $rows,
        ];
    }

    // ── AR Aging ──────────────────────────────────────────────
    // Groups all outstanding invoices into standard aging buckets.

    public function aging(?int $propertyId = null): array
    {
        $pf = $propertyId ? "AND u.property_id = $propertyId" : '';

        $rows = $this->fetchAll(
            "SELECT
                i.id, i.invoice_number, i.due_date, i.invoice_date,
                i.total_amount, i.amount_paid,
                (i.total_amount - i.amount_paid) AS balance,
                i.status,
                DATEDIFF(CURDATE(), DATE_ADD(i.due_date, INTERVAL l.grace_period_days DAY)) AS days_overdue,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                u.unit_number, pr.name AS property_name
             FROM invoices i
             JOIN leases l       ON l.id  = i.lease_id
             JOIN tenants t      ON t.id  = i.tenant_id
             JOIN units u        ON u.id  = l.unit_id
             JOIN properties pr  ON pr.id = u.property_id
             WHERE i.status IN ('unpaid','partial','overdue') $pf
             ORDER BY days_overdue DESC",
            []
        );

        $buckets = [
            'current'  => ['label' => 'Current (not yet due)', 'rows' => [], 'total' => 0.0],
            '1_30'     => ['label' => '1–30 days',             'rows' => [], 'total' => 0.0],
            '31_60'    => ['label' => '31–60 days',            'rows' => [], 'total' => 0.0],
            '61_90'    => ['label' => '61–90 days',            'rows' => [], 'total' => 0.0],
            'over_90'  => ['label' => 'Over 90 days',          'rows' => [], 'total' => 0.0],
        ];

        foreach ($rows as $row) {
            $d = (int)$row['days_overdue'];
            if ($d <= 0)       $key = 'current';
            elseif ($d <= 30)  $key = '1_30';
            elseif ($d <= 60)  $key = '31_60';
            elseif ($d <= 90)  $key = '61_90';
            else               $key = 'over_90';

            $buckets[$key]['rows'][]  = $row;
            $buckets[$key]['total']  += (float)$row['balance'];
        }

        $grandTotal = array_sum(array_column($rows, 'balance'));

        return [
            'grand_total' => round($grandTotal, 2),
            'buckets'     => $buckets,
        ];
    }

    // ── Deposit Summary ───────────────────────────────────────

    public function deposits(?int $propertyId = null): array
    {
        $pf = $propertyId ? "AND u.property_id = $propertyId" : '';

        $rows = $this->fetchAll(
            "SELECT
                l.id AS lease_id, l.lease_number,
                l.deposit_amount AS expected_deposit,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                t.email AS tenant_email,
                u.unit_number, pr.name AS property_name,
                l.status AS lease_status,
                l.start_date, l.end_date,
                COALESCE(SUM(CASE WHEN p.payment_type='deposit' AND p.status='completed' THEN p.amount END),0) AS paid_deposit,
                COALESCE(SUM(CASE WHEN p.payment_type='deposit_refund' AND p.status='completed' THEN p.amount END),0) AS refunded_deposit
             FROM leases l
             JOIN tenants t      ON t.id  = l.tenant_id
             JOIN units u        ON u.id  = l.unit_id
             JOIN properties pr  ON pr.id = u.property_id
             LEFT JOIN payments p ON p.lease_id = l.id
               AND p.payment_type IN ('deposit','deposit_refund')
             WHERE l.status IN ('active','terminated') $pf
             GROUP BY l.id
             ORDER BY pr.name, u.unit_number",
            []
        );

        // Tag each row with outstanding balance
        foreach ($rows as &$row) {
            $row['deposit_balance'] = round(
                (float)$row['paid_deposit'] - (float)$row['refunded_deposit'],
                2
            );
            $row['deposit_outstanding'] = round(
                (float)$row['expected_deposit'] - (float)$row['paid_deposit'],
                2
            );
        }
        unset($row);

        $summary = [
            'total_expected'    => round(array_sum(array_column($rows, 'expected_deposit')), 2),
            'total_collected'   => round(array_sum(array_column($rows, 'paid_deposit')),     2),
            'total_refunded'    => round(array_sum(array_column($rows, 'refunded_deposit')), 2),
            'total_outstanding' => round(array_sum(array_column($rows, 'deposit_outstanding')), 2),
            'total_held'        => round(array_sum(array_column($rows, 'deposit_balance')), 2),
        ];

        return compact('summary', 'rows');
    }

    // ── Arrears Analysis ──────────────────────────────────────
    // Monthly arrears trend + worst-offender list.

    public function arrears(int $months = 12, ?int $propertyId = null): array
    {
        $pf = $propertyId ? "AND u.property_id = $propertyId" : '';

        // Monthly billed vs collected over past N months
        $trend = $this->fetchAll(
            "SELECT
                DATE_FORMAT(i.invoice_date,'%Y-%m') AS month,
                SUM(i.total_amount)                                          AS billed,
                SUM(COALESCE(i.amount_paid, 0))                              AS collected,
                SUM(i.total_amount - COALESCE(i.amount_paid, 0))             AS outstanding,
                SUM(i.status IN ('unpaid','partial','overdue'))              AS invoices_overdue
             FROM invoices i
             JOIN leases l       ON l.id  = i.lease_id
             JOIN units u        ON u.id  = l.unit_id
             WHERE i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
               AND i.status != 'cancelled' $pf
             GROUP BY month
             ORDER BY month",
            [$months]
        );

        // Worst offenders (tenants with highest outstanding balance)
        $worstOffenders = $this->fetchAll(
            "SELECT
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                t.email, t.phone,
                u.unit_number, pr.name AS property_name,
                SUM(i.total_amount - COALESCE(i.amount_paid,0)) AS total_outstanding,
                COUNT(i.id)                                     AS overdue_count,
                MIN(i.due_date)                                 AS oldest_due,
                MAX(DATEDIFF(CURDATE(), i.due_date))            AS max_days_overdue
             FROM invoices i
             JOIN tenants t      ON t.id  = i.tenant_id
             JOIN leases l       ON l.id  = i.lease_id
             JOIN units u        ON u.id  = l.unit_id
             JOIN properties pr  ON pr.id = u.property_id
             WHERE i.status IN ('unpaid','partial','overdue') $pf
             GROUP BY i.tenant_id
             HAVING total_outstanding > 0
             ORDER BY total_outstanding DESC
             LIMIT 20",
            []
        );

        // By property summary
        $byProperty = $this->fetchAll(
            "SELECT
                pr.name AS property_name,
                SUM(i.total_amount - COALESCE(i.amount_paid,0)) AS outstanding,
                COUNT(DISTINCT i.tenant_id) AS tenants_owing,
                COUNT(i.id)                 AS invoice_count
             FROM invoices i
             JOIN leases l       ON l.id  = i.lease_id
             JOIN units u        ON u.id  = l.unit_id
             JOIN properties pr  ON pr.id = u.property_id
             WHERE i.status IN ('unpaid','partial','overdue')
             GROUP BY pr.id
             ORDER BY outstanding DESC",
            []
        );

        // Collection effectiveness (last 3 months)
        $effectiveness = $this->fetchOne(
            "SELECT
                SUM(i.total_amount)                              AS total_billed,
                SUM(COALESCE(i.amount_paid,0))                   AS total_collected,
                SUM(i.total_amount - COALESCE(i.amount_paid,0)) AS total_outstanding,
                ROUND(SUM(COALESCE(i.amount_paid,0)) / NULLIF(SUM(i.total_amount),0) * 100, 1) AS collection_rate
             FROM invoices i
             JOIN leases l ON l.id = i.lease_id
             JOIN units u  ON u.id = l.unit_id
             WHERE i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
               AND i.status != 'cancelled' $pf",
            []
        );

        return compact('trend', 'worstOffenders', 'byProperty', 'effectiveness');
    }

    // ── Tenant Analytics ──────────────────────────────────────

    public function tenantAnalytics(?int $propertyId = null): array
    {
        $pf = $propertyId ? "AND u.property_id = $propertyId" : '';

        // New tenants per month (last 12 months)
        $newPerMonth = $this->fetchAll(
            "SELECT DATE_FORMAT(l.start_date,'%Y-%m') AS month,
                COUNT(DISTINCT l.tenant_id) AS new_tenants
             FROM leases l
             JOIN units u ON u.id = l.unit_id
             WHERE l.start_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $pf
             GROUP BY month ORDER BY month",
            []
        );

        // Lease terminations per month (last 12 months)
        $terminatedPerMonth = $this->fetchAll(
            "SELECT DATE_FORMAT(l.end_date,'%Y-%m') AS month,
                COUNT(*) AS terminated
             FROM leases l
             JOIN units u ON u.id = l.unit_id
             WHERE l.status IN ('terminated','expired')
               AND l.end_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $pf
             GROUP BY month ORDER BY month",
            []
        );

        // Average tenure (months) for completed leases
        $avgTenure = $this->fetchOne(
            "SELECT
                ROUND(AVG(DATEDIFF(IFNULL(end_date, CURDATE()), start_date) / 30.44), 1) AS avg_months,
                MIN(DATEDIFF(IFNULL(end_date, CURDATE()), start_date) / 30.44)            AS min_months,
                MAX(DATEDIFF(IFNULL(end_date, CURDATE()), start_date) / 30.44)            AS max_months
             FROM leases l
             JOIN units u ON u.id = l.unit_id
             WHERE l.status IN ('active','terminated','expired') $pf",
            []
        );

        // Tenant status distribution
        $statusDist = $this->fetchAll(
            "SELECT t.status, COUNT(*) AS count
             FROM tenants t
             " . ($propertyId
                ? "JOIN leases l ON l.tenant_id = t.id JOIN units u ON u.id = l.unit_id AND u.property_id = $propertyId"
                : '') . "
             GROUP BY t.status",
            []
        );

        // Leases expiring in 30/60/90 days
        $expiringSoon = $this->fetchAll(
            "SELECT
                l.id, l.lease_number, l.end_date,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                u.unit_number, pr.name AS property_name,
                DATEDIFF(l.end_date, CURDATE()) AS days_remaining
             FROM leases l
             JOIN tenants t      ON t.id  = l.tenant_id
             JOIN units u        ON u.id  = l.unit_id
             JOIN properties pr  ON pr.id = u.property_id
             WHERE l.status = 'active'
               AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) $pf
             ORDER BY l.end_date",
            []
        );

        // Top tenants by total payments
        $topTenants = $this->fetchAll(
            "SELECT
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                u.unit_number, pr.name AS property_name,
                SUM(p.amount) AS total_paid,
                COUNT(p.id)   AS payment_count,
                MAX(p.payment_date) AS last_payment
             FROM tenants t
             JOIN leases l       ON l.tenant_id = t.id
             JOIN units u        ON u.id  = l.unit_id
             JOIN properties pr  ON pr.id = u.property_id
             JOIN payments p     ON p.tenant_id = t.id AND p.status = 'completed'
             WHERE 1=1 $pf
             GROUP BY t.id
             ORDER BY total_paid DESC
             LIMIT 10",
            []
        );

        return compact('newPerMonth', 'terminatedPerMonth', 'avgTenure', 'statusDist', 'expiringSoon', 'topTenants');
    }

    // ── CSV Export ────────────────────────────────────────────
    // Returns [headers => [], rows => []] ready for fputcsv.

    public function exportCsv(string $reportType, array $params): array
    {
        return match ($reportType) {
            'financial'        => $this->exportFinancial($params),
            'occupancy'        => $this->exportOccupancy($params),
            'rent_collection'  => $this->exportRentCollection($params),
            'arrears'          => $this->exportArrears($params),
            'tenant_analytics' => $this->exportTenants($params),
            'maintenance'      => $this->exportMaintenance($params),
            'aging'            => $this->exportAging($params),
            'deposits'         => $this->exportDeposits($params),
            default            => ['headers' => [], 'rows' => []],
        };
    }

    private function exportFinancial(array $p): array
    {
        $from  = $p['date_from'] ?? date('Y-01-01');
        $to    = $p['date_to']   ?? date('Y-m-d');
        $propId = isset($p['property_id']) ? (int)$p['property_id'] : null;
        $pf    = $propId ? "AND u.property_id = $propId" : '';

        $rows = $this->fetchAll(
            "SELECT DATE_FORMAT(p.payment_date,'%Y-%m') AS Period,
                p.payment_ref AS Reference,
                CONCAT(t.first_name,' ',t.last_name) AS Tenant,
                u.unit_number AS Unit,
                pr.name AS Property,
                p.payment_type AS Type,
                p.payment_method AS Method,
                p.amount AS Amount,
                p.status AS Status,
                p.payment_date AS Date
             FROM payments p
             LEFT JOIN tenants t ON t.id = p.tenant_id
             LEFT JOIN leases l  ON l.id = p.lease_id
             LEFT JOIN units u   ON u.id = l.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             WHERE p.payment_date BETWEEN ? AND ?
               AND p.status = 'completed' $pf
             ORDER BY p.payment_date",
            [$from, $to]
        );

        $headers = ['Period','Reference','Tenant','Unit','Property','Type','Method','Amount','Status','Date'];
        return compact('headers', 'rows');
    }

    private function exportOccupancy(array $p): array
    {
        $propId = isset($p['property_id']) ? (int)$p['property_id'] : null;
        $where  = $propId ? "WHERE u.property_id = $propId" : '';

        $rows = $this->fetchAll(
            "SELECT pr.name AS Property, u.unit_number AS Unit, u.unit_type AS Type,
                u.bedrooms AS Bedrooms, u.rent_amount AS 'Rent Amount',
                u.status AS Status,
                CONCAT(t.first_name,' ',t.last_name) AS 'Current Tenant',
                l.start_date AS 'Lease Start', l.end_date AS 'Lease End'
             FROM units u
             JOIN properties pr ON pr.id = u.property_id
             LEFT JOIN leases l ON l.unit_id = u.id AND l.status = 'active'
             LEFT JOIN tenants t ON t.id = l.tenant_id
             $where ORDER BY pr.name, u.unit_number",
            []
        );

        $headers = ['Property','Unit','Type','Bedrooms','Rent Amount','Status','Current Tenant','Lease Start','Lease End'];
        return compact('headers', 'rows');
    }

    private function exportRentCollection(array $p): array
    {
        $year   = (int)($p['year']  ?? date('Y'));
        $month  = (int)($p['month'] ?? date('n'));
        $propId = isset($p['property_id']) ? (int)$p['property_id'] : null;
        $data   = $this->rentCollection($year, $month, $propId);

        $headers = ['Property','Unit','Tenant','Expected','Collected','Balance','Invoice Status','Days Overdue'];
        $rows    = array_map(fn($r) => [
            $r['property_name'], $r['unit_number'], $r['tenant_name'],
            $r['expected'], $r['collected'],
            round((float)$r['expected'] - (float)$r['collected'], 2),
            $r['invoice_status'], $r['days_overdue'] ?? 0,
        ], $data['rows']);

        return compact('headers', 'rows');
    }

    private function exportArrears(array $p): array
    {
        $propId = isset($p['property_id']) ? (int)$p['property_id'] : null;
        $data   = $this->arrears(12, $propId);

        $headers = ['Tenant','Property','Unit','Total Outstanding','Overdue Invoices','Oldest Due','Max Days Overdue','Email','Phone'];
        $rows    = array_map(fn($r) => [
            $r['tenant_name'], $r['property_name'], $r['unit_number'],
            $r['total_outstanding'], $r['overdue_count'],
            $r['oldest_due'], $r['max_days_overdue'],
            $r['email'], $r['phone'],
        ], $data['worstOffenders']);

        return compact('headers', 'rows');
    }

    private function exportTenants(array $p): array
    {
        $propId = isset($p['property_id']) ? (int)$p['property_id'] : null;
        $pf     = $propId ? "AND u.property_id = $propId" : '';

        $rows = $this->fetchAll(
            "SELECT CONCAT(t.first_name,' ',t.last_name) AS 'Full Name',
                t.email AS Email, t.phone AS Phone, t.status AS Status,
                u.unit_number AS Unit, pr.name AS Property,
                l.lease_number AS 'Lease No.', l.start_date AS 'Start Date',
                l.end_date AS 'End Date', l.monthly_rent AS 'Monthly Rent',
                l.status AS 'Lease Status'
             FROM tenants t
             LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
             LEFT JOIN units u  ON u.id  = l.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             WHERE 1=1 $pf
             ORDER BY pr.name, u.unit_number",
            []
        );

        $headers = ['Full Name','Email','Phone','Status','Unit','Property','Lease No.','Start Date','End Date','Monthly Rent','Lease Status'];
        return compact('headers', 'rows');
    }

    private function exportMaintenance(array $p): array
    {
        $from   = $p['date_from'] ?? date('Y-01-01');
        $to     = $p['date_to']   ?? date('Y-m-d');
        $propId = isset($p['property_id']) ? (int)$p['property_id'] : null;
        $pf     = $propId ? "AND u.property_id = $propId" : '';

        $rows = $this->fetchAll(
            "SELECT mr.id AS ID, pr.name AS Property, u.unit_number AS Unit,
                mr.category AS Category, mr.priority AS Priority, mr.status AS Status,
                mr.description AS Description,
                (mr.materials_cost + mr.labour_cost) AS 'Total Cost',
                mr.created_at AS 'Reported', mr.work_completed AS 'Completed',
                CONCAT(t.first_name,' ',t.last_name) AS Tenant
             FROM maintenance_requests mr
             LEFT JOIN units u      ON u.id  = mr.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             LEFT JOIN leases l     ON l.unit_id = mr.unit_id AND l.status = 'active'
             LEFT JOIN tenants t    ON t.id = l.tenant_id
             WHERE DATE(mr.created_at) BETWEEN ? AND ? $pf
             ORDER BY mr.created_at DESC",
            [$from, $to]
        );

        $headers = ['ID','Property','Unit','Category','Priority','Status','Description','Total Cost','Reported','Completed','Tenant'];
        return compact('headers', 'rows');
    }

    private function exportAging(array $p): array
    {
        $propId = isset($p['property_id']) ? (int)$p['property_id'] : null;
        $data   = $this->aging($propId);

        $headers = ['Invoice No.','Property','Unit','Tenant','Invoice Date','Due Date','Total Amount','Paid','Balance','Days Overdue','Bucket'];
        $rows    = [];

        foreach ($data['buckets'] as $key => $bucket) {
            foreach ($bucket['rows'] as $r) {
                $rows[] = [
                    $r['invoice_number'], $r['property_name'], $r['unit_number'],
                    $r['tenant_name'], $r['invoice_date'], $r['due_date'],
                    $r['total_amount'], $r['amount_paid'], $r['balance'],
                    $r['days_overdue'], $bucket['label'],
                ];
            }
        }

        return compact('headers', 'rows');
    }

    private function exportDeposits(array $p): array
    {
        $propId = isset($p['property_id']) ? (int)$p['property_id'] : null;
        $data   = $this->deposits($propId);

        $headers = ['Property','Unit','Tenant','Lease No.','Lease Status','Expected Deposit','Paid','Refunded','Held Balance','Outstanding'];
        $rows    = array_map(fn($r) => [
            $r['property_name'], $r['unit_number'], $r['tenant_name'],
            $r['lease_number'], $r['lease_status'],
            $r['expected_deposit'], $r['paid_deposit'], $r['refunded_deposit'],
            $r['deposit_balance'], $r['deposit_outstanding'],
        ], $data['rows']);

        return compact('headers', 'rows');
    }

    // ── Rent Collection ───────────────────────────────────────

    public function rentCollection(int $year, int $month, ?int $propertyId = null): array
    {
        $pf       = $propertyId ? "AND u.property_id = $propertyId" : '';
        $dateFrom = "$year-$month-01";
        $dateTo   = date('Y-m-t', strtotime($dateFrom));

        $expected = (float)$this->fetchColumn(
            "SELECT COALESCE(SUM(u.rent_amount), 0)
             FROM leases l JOIN units u ON u.id = l.unit_id
             WHERE l.status = 'active' $pf",
            []
        );

        $collected = (float)$this->fetchColumn(
            "SELECT COALESCE(SUM(p.amount), 0) FROM payments p
             LEFT JOIN leases l ON l.id = p.lease_id
             LEFT JOIN units u  ON u.id = l.unit_id
             WHERE p.payment_date BETWEEN ? AND ? $pf",
            [$dateFrom, $dateTo]
        );

        $rows = $this->fetchAll(
            "SELECT CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                u.unit_number, pr.name AS property_name,
                u.rent_amount AS expected,
                COALESCE(SUM(p.amount), 0)      AS collected,
                COALESCE(i.status,'no_invoice') AS invoice_status,
                DATEDIFF(NOW(), i.due_date)      AS days_overdue
             FROM leases l
             JOIN tenants t      ON t.id  = l.tenant_id
             JOIN units u        ON u.id  = l.unit_id
             JOIN properties pr  ON pr.id = u.property_id
             LEFT JOIN invoices i ON i.lease_id = l.id
               AND YEAR(i.invoice_date) = ? AND MONTH(i.invoice_date) = ?
             LEFT JOIN payments p ON p.invoice_id = i.id
               AND p.payment_date BETWEEN ? AND ?
             WHERE l.status = 'active' $pf
             GROUP BY l.id ORDER BY pr.name, u.unit_number",
            [$year, $month, $dateFrom, $dateTo]
        );

        return [
            'period'          => "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT),
            'expected'        => $expected,
            'collected'       => $collected,
            'outstanding'     => $expected - $collected,
            'collection_rate' => $expected > 0 ? round($collected / $expected * 100, 1) : 0,
            'rows'            => $rows,
        ];
    }
}
