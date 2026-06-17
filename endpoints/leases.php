<?php
/**
 * Leases endpoints
 *
 * GET    /api/v1/leases                            list (paginated, filterable)
 * GET    /api/v1/leases/expiring                   expiring within ?days=30
 * POST   /api/v1/leases                            create (marks unit occupied)
 * GET    /api/v1/leases/{id}                       single + invoices/payments/docs/renewals
 * PUT    /api/v1/leases/{id}                       update terms / escalation rules
 * POST   /api/v1/leases/{id}/terminate             terminate (marks unit available)
 * POST   /api/v1/leases/{id}/renew                 extend end_date, update rent
 * POST   /api/v1/leases/{id}/apply-escalation      apply configured escalation now
 * POST   /api/v1/leases/{id}/sign                  mark lease as signed
 * GET    /api/v1/leases/{id}/documents             list attached documents
 * POST   /api/v1/leases/{id}/documents             store document metadata
 * DELETE /api/v1/leases/{id}/documents/{doc_id}    remove document record
 */
function registerLeaseRoutes(Router $router, PDO $db): void
{
    $svc = new LeaseService($db);

    // ── List ──────────────────────────────────────────────────
    $router->get('leases', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:leases');
        $user    = ApiAuth::user();
        $filters = [
            'search'      => Router::strParam('search'),
            'status'      => Router::strParam('status', 'active'),
            'property_id' => Router::intParam('property_id'),
            'tenant_id'   => Router::intParam('tenant_id'),
            'unit_id'     => Router::intParam('unit_id'),
        ];

        // Tenants see only their own leases
        if ($user['role'] === 'tenant') {
            $row = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $row->execute([$user['id']]);
            $t = $row->fetch();
            $filters['tenant_id'] = $t ? (int)$t['id'] : 0;
        }

        ApiResponse::paginated($svc->list($filters, Router::page(), Router::perPage()));
    });

    // ── Expiring ──────────────────────────────────────────────
    $router->get('leases/expiring', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:leases');
        $days = Router::intParam('days', 30);
        ApiResponse::ok($svc->getExpiring($days), "Leases expiring within $days days.");
    });

    // ── Create ────────────────────────────────────────────────
    $router->post('leases', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:leases');
        $res = $svc->create(Router::body());
        $res['success']
            ? ApiResponse::created(
                ['id' => $res['id'], 'lease_number' => $res['lease_number']],
                $res['message']
              )
            : ApiResponse::unprocessable($res['message'], $res['errors'] ?? []);
    });

    // ── View single ───────────────────────────────────────────
    $router->get('leases/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:leases');
        $lease = $svc->find((int)$id);
        $lease ? ApiResponse::ok($lease) : ApiResponse::notFound('Lease not found.');
    });

    // ── Update terms / escalation ─────────────────────────────
    $router->put('leases/{id}', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'write:leases');
        $body    = Router::body();
        $allowed = array_intersect_key($body, array_flip([
            'end_date', 'monthly_rent', 'payment_day', 'grace_period_days',
            'penalty_rate', 'terms', 'notes', 'notice_period_days',
            'escalation_type', 'escalation_rate', 'escalation_frequency',
            'next_escalation_date',
        ]));
        if (!$allowed) ApiResponse::badRequest('No valid fields to update.');

        $set  = implode(', ', array_map(fn($k) => "$k = ?", array_keys($allowed)));
        $vals = [...array_values($allowed), (int)$id];
        $db->prepare("UPDATE leases SET $set WHERE id = ?")->execute($vals);
        ApiResponse::ok(null, 'Lease updated.');
    });

    // ── Terminate ─────────────────────────────────────────────
    $router->post('leases/{id}/terminate', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $body   = Router::body();
        $reason = trim($body['reason'] ?? '');
        $res    = $svc->terminate((int)$id, $reason);
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    // ── Renew ─────────────────────────────────────────────────
    $router->post('leases/{id}/renew', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $res = $svc->renew((int)$id, Router::body());
        $res['success']
            ? ApiResponse::ok(['renewal_id' => $res['renewal_id'] ?? null], $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    // ── Apply escalation ──────────────────────────────────────
    $router->post('leases/{id}/apply-escalation', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $res = $svc->applyEscalation((int)$id);
        $res['success']
            ? ApiResponse::ok($res, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    // ── Mark as signed ────────────────────────────────────────
    $router->post('leases/{id}/sign', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $user = ApiAuth::user();
        $db->prepare(
            "UPDATE leases SET signed_at = NOW(), signed_by = ? WHERE id = ? AND signed_at IS NULL"
        )->execute([$user['id'], (int)$id]);
        ApiResponse::ok(null, 'Lease marked as signed.');
    });

    // ── Documents: list ───────────────────────────────────────
    $router->get('leases/{id}/documents', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:leases');
        $stmt = $db->prepare(
            "SELECT d.*, u.name AS uploaded_by_name
             FROM lease_documents d
             LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE d.lease_id = ?
             ORDER BY d.created_at DESC"
        );
        $stmt->execute([(int)$id]);
        ApiResponse::ok($stmt->fetchAll());
    });

    // ── Documents: attach ─────────────────────────────────────
    $router->post('leases/{id}/documents', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'write:leases');
        $body = Router::body();
        foreach (['original_name', 'file_path'] as $f) {
            if (empty($body[$f])) ApiResponse::unprocessable("Field '$f' is required.");
        }
        $user = ApiAuth::user();
        $db->prepare(
            "INSERT INTO lease_documents
             (lease_id, document_type, original_name, file_path, file_size, mime_type, notes, uploaded_by)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            (int)$id,
            $body['document_type'] ?? 'contract',
            $body['original_name'],
            $body['file_path'],
            $body['file_size']  ?? null,
            $body['mime_type']  ?? null,
            $body['notes']      ?? null,
            $user['id'],
        ]);
        ApiResponse::created(['id' => (int)$db->lastInsertId()], 'Document attached.');
    });

    // ── Documents: delete ─────────────────────────────────────
    $router->delete('leases/{id}/documents/{doc_id}', function (string $id, string $doc_id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $stmt = $db->prepare(
            "SELECT file_path FROM lease_documents WHERE id = ? AND lease_id = ?"
        );
        $stmt->execute([(int)$doc_id, (int)$id]);
        $doc = $stmt->fetch();
        if (!$doc) ApiResponse::notFound('Document not found.');

        $db->prepare("DELETE FROM lease_documents WHERE id = ?")->execute([(int)$doc_id]);
        ApiResponse::ok(['file_path' => $doc['file_path']], 'Document removed.');
    });
}
