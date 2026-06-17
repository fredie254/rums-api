<?php
/**
 * Payments endpoints
 *
 * GET    /api/v1/payments                list (filterable by date, tenant, method, property, status)
 * GET    /api/v1/payments/summary        aggregated totals for a period
 * GET    /api/v1/payments/export         CSV export (same filters as list, max 5000)
 * POST   /api/v1/payments                record a payment
 * GET    /api/v1/payments/{id}           single payment + receipt data
 * POST   /api/v1/payments/{id}/reverse   reverse a completed payment
 * PATCH  /api/v1/payments/{id}           update notes / date / reference
 */
function registerPaymentRoutes(Router $router, PDO $db): void
{
    $svc = new PaymentService($db);

    // Static routes before parameterised
    $router->get('payments/summary', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:payments');
        $from  = Router::strParam('date_from', date('Y-m-01'));
        $to    = Router::strParam('date_to',   date('Y-m-d'));
        $propId= Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->summary($from, $to, $propId));
    });

    $router->get('payments', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:payments');
        $user    = ApiAuth::user();
        $filters = [
            'tenant_id'   => Router::intParam('tenant_id'),
            'lease_id'    => Router::intParam('lease_id'),
            'invoice_id'  => Router::intParam('invoice_id'),
            'property_id' => Router::intParam('property_id'),
            'landlord_id' => Router::intParam('landlord_id'),
            'method'      => Router::strParam('method'),
            'status'      => Router::strParam('status'),
            'type'        => Router::strParam('type'),
            'date_from'   => Router::strParam('date_from'),
            'date_to'     => Router::strParam('date_to'),
            'no_invoice'  => Router::strParam('no_invoice') === '1' ? 1 : 0,
        ];

        if ($user['role'] === 'tenant') {
            $row = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $row->execute([$user['id']]);
            $t = $row->fetch();
            $filters['tenant_id'] = $t ? (int)$t['id'] : 0;
        }

        ApiResponse::paginated($svc->list($filters, Router::page(), Router::perPage()));
    });

    $router->post('payments', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:payments');
        $res = $svc->record(Router::body());
        $res['success']
            ? ApiResponse::created(
                ['id' => $res['id'], 'payment_ref' => $res['payment_ref']],
                $res['message']
              )
            : ApiResponse::unprocessable($res['message'], $res['errors'] ?? []);
    });

    // ── Export CSV ────────────────────────────────────────────────
    $router->get('payments/export', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:payments');
        $user    = ApiAuth::user();
        $filters = [
            'tenant_id'   => Router::intParam('tenant_id'),
            'lease_id'    => Router::intParam('lease_id'),
            'invoice_id'  => Router::intParam('invoice_id'),
            'property_id' => Router::intParam('property_id'),
            'method'      => Router::strParam('method'),
            'status'      => Router::strParam('status'),
            'date_from'   => Router::strParam('date_from'),
            'date_to'     => Router::strParam('date_to'),
        ];

        if ($user['role'] === 'tenant') {
            $row = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $row->execute([$user['id']]);
            $t = $row->fetch();
            $filters['tenant_id'] = $t ? (int)$t['id'] : 0;
        }

        $result = $svc->list($filters, 1, 5000);
        $rows   = $result['data'] ?? [];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="payments_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Reference','Tenant','Unit','Property','Amount','Method','Type','M-Pesa Code','Date','Status','Notes']);
        foreach ($rows as $p) {
            fputcsv($out, [
                $p['id'],
                $p['payment_ref'] ?? '',
                $p['tenant_name'] ?? '',
                $p['unit_number'] ?? '',
                $p['property_name'] ?? '',
                $p['amount'],
                $p['payment_method'] ?? '',
                $p['payment_type'] ?? '',
                $p['mpesa_transaction_id'] ?? '',
                $p['payment_date'] ?? '',
                $p['status'] ?? '',
                $p['notes'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    });

    // ── Single payment ─────────────────────────────────────────────
    $router->get('payments/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:payments');
        $p = $svc->find((int)$id);
        $p ? ApiResponse::ok($p) : ApiResponse::notFound('Payment not found.');
    });

    // ── Reverse payment ───────────────────────────────────────────
    $router->post('payments/{id}/reverse', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');

        $stmt = $db->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([(int)$id]);
        $pay = $stmt->fetch();

        if (!$pay) ApiResponse::notFound('Payment not found.');
        if ($pay['status'] !== 'completed') {
            ApiResponse::unprocessable('Only completed payments can be reversed.');
        }

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE payments SET status='reversed' WHERE id=?")
               ->execute([(int)$id]);

            if ($pay['invoice_id']) {
                $paid  = (float)$db->prepare(
                    "SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND status='completed'"
                )->execute([$pay['invoice_id']]) ? $db->query(
                    "SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id={$pay['invoice_id']} AND status='completed'"
                )->fetchColumn() : 0;

                $inv  = $db->prepare("SELECT total_amount FROM invoices WHERE id=?");
                $inv->execute([$pay['invoice_id']]);
                $total = (float)($inv->fetchColumn() ?: 0);

                $status = match(true) {
                    $paid <= 0      => 'unpaid',
                    $paid >= $total => 'paid',
                    default         => 'partial',
                };
                $db->prepare("UPDATE invoices SET amount_paid=?, status=? WHERE id=?")
                   ->execute([$paid, $status, $pay['invoice_id']]);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            ApiResponse::serverError('Reversal failed: ' . $e->getMessage());
        }

        ApiResponse::ok(null, 'Payment reversed successfully.');
    });

    // ── Partial update ────────────────────────────────────────────
    $router->patch('payments/{id}', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $body    = Router::body();
        $allowed = array_intersect_key($body, array_flip([
            'notes', 'payment_date', 'cheque_number', 'mpesa_transaction_id',
        ]));
        if (!$allowed) ApiResponse::badRequest('No valid fields to update.');

        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($allowed)));
        $db->prepare("UPDATE payments SET $set WHERE id=?")
           ->execute([...array_values($allowed), (int)$id]);
        ApiResponse::ok(null, 'Payment updated.');
    });
}
