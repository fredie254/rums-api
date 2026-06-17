<?php
/**
 * M-Pesa Transaction endpoints
 *
 * GET  /api/v1/mpesa-transactions  list (admin & accountant only)
 */
function registerMpesaTransactionRoutes(Router $router, PDO $db): void
{
    $router->get('mpesa-transactions', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'accountant');

        $page    = Router::page();
        $perPage = Router::perPage(50);

        $dateFrom  = Router::strParam('date_from', date('Y-m-01'));
        $dateTo    = Router::strParam('date_to',   date('Y-m-d'));
        $status    = Router::strParam('status');
        $noPayment = Router::strParam('no_payment') === '1';

        $where  = ['DATE(m.created_at) BETWEEN ? AND ?'];
        $params = [$dateFrom, $dateTo];

        if ($status)    { $where[] = 'm.status = ?';    $params[] = $status; }
        if ($noPayment) { $where[] = 'm.payment_id IS NULL'; }

        $w = 'WHERE ' . implode(' AND ', $where);

        $cStmt = $db->prepare("SELECT COUNT(*) FROM mpesa_transactions m $w");
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt   = $db->prepare(
            "SELECT m.id, m.transaction_id, m.amount, m.msisdn, m.first_name, m.last_name,
                    m.created_at, m.status, m.payment_id, m.mpesa_receipt,
                    m.checkout_request_id, m.account_reference
             FROM mpesa_transactions m
             $w
             ORDER BY m.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $perPage, $offset]);
        $rows = $stmt->fetchAll();

        ApiResponse::paginated([
            'data' => $rows,
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'total_pages'  => $total > 0 ? (int)ceil($total / $perPage) : 1,
            ],
        ]);
    });
}
