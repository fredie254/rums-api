<?php
require_once __DIR__ . '/BaseService.php';

class ReconciliationService extends BaseService
{
    // ── Import CSV rows ───────────────────────────────────────────
    // Accepts an array of parsed rows from a bank statement CSV.
    // Each row: statement_date, value_date?, description?, debit, credit, balance?, reference?

    public function import(array $rows, int $userId, string $batch = ''): array
    {
        if (empty($batch)) {
            $batch = 'IMPORT-' . date('Ymd-His');
        }

        $inserted = 0;
        $stmt = $this->db->prepare(
            "INSERT INTO bank_statement_entries
                (import_batch, statement_date, value_date, description, debit, credit, balance, reference, imported_by)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );

        foreach ($rows as $row) {
            $date = trim($row['statement_date'] ?? $row['date'] ?? '');
            if (!$date || !strtotime($date)) continue; // skip invalid rows

            $stmt->execute([
                $batch,
                date('Y-m-d', strtotime($date)),
                !empty($row['value_date'])  ? date('Y-m-d', strtotime($row['value_date'])) : null,
                trim($row['description'] ?? $row['narration'] ?? '') ?: null,
                round((float)($row['debit']   ?? 0), 2),
                round((float)($row['credit']  ?? 0), 2),
                isset($row['balance']) && $row['balance'] !== '' ? round((float)$row['balance'], 2) : null,
                trim($row['reference'] ?? $row['ref'] ?? '') ?: null,
                $userId,
            ]);
            $inserted++;
        }

        return ['inserted' => $inserted, 'batch' => $batch];
    }

    // ── Auto-match ────────────────────────────────────────────────
    // Matches unmatched bank credits to RUMS bank/bank_transfer payments
    // by amount + date proximity (±2 days).

    public function autoMatch(string $dateFrom, string $dateTo): int
    {
        $stmt = $this->db->prepare(
            "UPDATE bank_statement_entries e
             JOIN payments p
               ON  p.amount  = e.credit
               AND ABS(DATEDIFF(p.payment_date, e.statement_date)) <= 2
               AND p.payment_method IN ('bank','bank_transfer')
               AND p.status = 'completed'
             SET e.payment_id = p.id,
                 e.matched_at = NOW()
             WHERE e.credit > 0
               AND e.payment_id IS NULL
               AND e.statement_date BETWEEN ? AND ?"
        );
        $stmt->execute([$dateFrom, $dateTo]);
        return $stmt->rowCount();
    }

    // ── Reconciliation report ─────────────────────────────────────

    public function report(string $dateFrom, string $dateTo, ?int $propertyId = null): array
    {
        $pf = $propertyId ? "AND u.property_id = $propertyId" : '';

        // Bank totals
        $bank = $this->fetchOne(
            "SELECT
                COALESCE(SUM(credit), 0) AS bank_total_credits,
                COALESCE(SUM(debit),  0) AS bank_total_debits,
                COUNT(CASE WHEN credit > 0 AND payment_id IS NULL THEN 1 END) AS unmatched_bank_count,
                COALESCE(SUM(CASE WHEN credit > 0 AND payment_id IS NULL THEN credit END), 0) AS unmatched_bank_amount,
                COUNT(CASE WHEN credit > 0 AND payment_id IS NOT NULL THEN 1 END) AS matched_count
             FROM bank_statement_entries
             WHERE statement_date BETWEEN ? AND ?",
            [$dateFrom, $dateTo]
        ) ?: [];

        // RUMS bank payments total
        $rums = $this->fetchOne(
            "SELECT
                COALESCE(SUM(p.amount), 0) AS rums_total,
                COUNT(*) AS rums_count
             FROM payments p
             LEFT JOIN leases l  ON l.id = p.lease_id
             LEFT JOIN units u   ON u.id = l.unit_id
             WHERE p.payment_date BETWEEN ? AND ?
               AND p.payment_method IN ('bank','bank_transfer')
               AND p.status = 'completed'
               $pf",
            [$dateFrom, $dateTo]
        ) ?: [];

        // Unmatched RUMS payments (no statement entry linked to them)
        $unmatchedRums = $this->fetchOne(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(p.amount),0) AS total
             FROM payments p
             LEFT JOIN leases l  ON l.id = p.lease_id
             LEFT JOIN units u   ON u.id = l.unit_id
             LEFT JOIN bank_statement_entries e ON e.payment_id = p.id
             WHERE p.payment_date BETWEEN ? AND ?
               AND p.payment_method IN ('bank','bank_transfer')
               AND p.status = 'completed'
               AND e.id IS NULL
               $pf",
            [$dateFrom, $dateTo]
        ) ?: ['cnt' => 0, 'total' => 0];

        $bankCredits = (float)($bank['bank_total_credits'] ?? 0);
        $rumsTotal   = (float)($rums['rums_total'] ?? 0);

        return [
            'date_from'               => $dateFrom,
            'date_to'                 => $dateTo,
            'bank_total_credits'      => $bankCredits,
            'bank_total_debits'       => (float)($bank['bank_total_debits'] ?? 0),
            'rums_total'              => $rumsTotal,
            'difference'              => round($bankCredits - $rumsTotal, 2),
            'matched_count'           => (int)($bank['matched_count'] ?? 0),
            'unmatched_bank_count'    => (int)($bank['unmatched_bank_count'] ?? 0),
            'unmatched_bank_amount'   => (float)($bank['unmatched_bank_amount'] ?? 0),
            'unmatched_rums_count'    => (int)($unmatchedRums['cnt'] ?? 0),
            'unmatched_rums_amount'   => (float)($unmatchedRums['total'] ?? 0),
        ];
    }

    // ── List entries ──────────────────────────────────────────────

    public function getEntries(
        string $dateFrom,
        string $dateTo,
        ?string $matchStatus,
        int $page,
        int $perPage
    ): array {
        $where  = ['e.statement_date BETWEEN ? AND ?'];
        $params = [$dateFrom, $dateTo];

        if ($matchStatus === 'matched')   $where[] = 'e.payment_id IS NOT NULL';
        if ($matchStatus === 'unmatched') $where[] = 'e.payment_id IS NULL';

        $w = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT e.*,
                    p.payment_ref, p.amount AS payment_amount,
                    p.payment_date, p.payment_method,
                    CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                    u.unit_number
                FROM bank_statement_entries e
                LEFT JOIN payments p  ON p.id  = e.payment_id
                LEFT JOIN leases l    ON l.id  = p.lease_id
                LEFT JOIN units u     ON u.id  = l.unit_id
                LEFT JOIN tenants t   ON t.id  = p.tenant_id
                $w ORDER BY e.statement_date DESC, e.id DESC";

        $countSql = "SELECT COUNT(*) FROM bank_statement_entries e $w";

        return $this->paginatedQuery($sql, $params, $countSql, $params, $page, $perPage);
    }

    // ── Manual match ──────────────────────────────────────────────

    public function matchEntry(int $entryId, int $paymentId, int $userId): bool
    {
        $entry   = $this->fetchOne("SELECT id FROM bank_statement_entries WHERE id = ?", [$entryId]);
        $payment = $this->fetchOne("SELECT id FROM payments WHERE id = ? AND status = 'completed'", [$paymentId]);

        if (!$entry || !$payment) return false;

        $this->execute(
            "UPDATE bank_statement_entries SET payment_id=?, matched_by=?, matched_at=NOW() WHERE id=?",
            [$paymentId, $userId, $entryId]
        );
        return true;
    }

    // ── Unmatch ───────────────────────────────────────────────────

    public function unmatchEntry(int $entryId): bool
    {
        $entry = $this->fetchOne("SELECT id FROM bank_statement_entries WHERE id = ?", [$entryId]);
        if (!$entry) return false;

        $this->execute(
            "UPDATE bank_statement_entries SET payment_id=NULL, matched_by=NULL, matched_at=NULL WHERE id=?",
            [$entryId]
        );
        return true;
    }

    // ── Import batches list ───────────────────────────────────────

    public function getBatches(): array
    {
        return $this->fetchAll(
            "SELECT import_batch, COUNT(*) AS entries,
                COALESCE(SUM(credit),0) AS total_credit,
                COALESCE(SUM(debit),0)  AS total_debit,
                MIN(statement_date)     AS from_date,
                MAX(statement_date)     AS to_date,
                COUNT(payment_id)       AS matched_count,
                u.name                  AS imported_by_name,
                MAX(e.created_at)       AS imported_at
             FROM bank_statement_entries e
             LEFT JOIN users u ON u.id = e.imported_by
             GROUP BY import_batch, u.name
             ORDER BY MAX(e.created_at) DESC",
            []
        );
    }

    // ── Unmatched RUMS bank payments in period ────────────────────

    public function getUnmatchedRumsPayments(string $dateFrom, string $dateTo): array
    {
        return $this->fetchAll(
            "SELECT p.id, p.payment_ref, p.amount, p.payment_date, p.payment_method,
                p.notes, p.cheque_number,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                u.unit_number, pr.name AS property_name
             FROM payments p
             LEFT JOIN leases l   ON l.id  = p.lease_id
             LEFT JOIN units u    ON u.id  = l.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             LEFT JOIN tenants t  ON t.id  = p.tenant_id
             LEFT JOIN bank_statement_entries e ON e.payment_id = p.id
             WHERE p.payment_date BETWEEN ? AND ?
               AND p.payment_method IN ('bank','bank_transfer')
               AND p.status = 'completed'
               AND e.id IS NULL
             ORDER BY p.payment_date DESC",
            [$dateFrom, $dateTo]
        );
    }
}
