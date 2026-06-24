<?php
/**
 * Tenants endpoints
 *
 * GET    /api/v1/tenants                     list (paginated, filterable)
 * POST   /api/v1/tenants                     create
 * GET    /api/v1/tenants/{id}                single + lease + payment summary
 * PUT    /api/v1/tenants/{id}                full update
 * PATCH  /api/v1/tenants/{id}                partial update
 * GET    /api/v1/tenants/{id}/statement      payment statement (date range)
 * GET    /api/v1/tenants/{id}/invoices       invoices for tenant
 * GET    /api/v1/tenants/{id}/payments       payments for tenant
 * GET    /api/v1/tenants/{id}/maintenance    maintenance requests for tenant
 */
function registerTenantRoutes(Router $router, PDO $db): void
{
    $svc = new TenantService($db);

    $router->get('tenants', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:tenants');
        ApiResponse::paginated($svc->list(
            filters: [
                'search'      => Router::strParam('search'),
                'status'      => Router::strParam('status'),
                'property_id' => Router::intParam('property_id'),
            ],
            page: Router::page(), perPage: Router::perPage()
        ));
    });

    $router->post('tenants', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:tenants');
        $res = $svc->create(Router::body());
        $res['success']
            ? ApiResponse::created(['id' => $res['id']], $res['message'])
            : ApiResponse::unprocessable($res['message'], $res['errors'] ?? []);
    });

    $router->get('tenants/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:tenants');

        // Tenants can only read their own record
        $user = ApiAuth::user();
        if ($user['role'] === 'tenant') {
            $own = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $own->execute([$user['id']]);
            $row = $own->fetch();
            if (!$row || (int)$row['id'] !== (int)$id) {
                ApiResponse::forbidden('Access denied.');
            }
        }

        $t = $svc->find((int)$id);
        $t ? ApiResponse::ok($t) : ApiResponse::notFound('Tenant not found.');
    });

    $router->put('tenants/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:tenants');
        $res = $svc->update((int)$id, Router::body());
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    $router->patch('tenants/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:tenants');
        $res = $svc->update((int)$id, Router::body());
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    $router->delete('tenants/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin');
        $res = $svc->delete((int)$id);
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    $router->get('tenants/{id}/statement', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:tenants');
        $from = Router::strParam('date_from', date('Y-m-01'));
        $to   = Router::strParam('date_to',   date('Y-m-d'));
        ApiResponse::ok($svc->getStatement((int)$id, $from, $to));
    });

    $router->get('tenants/{id}/invoices', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:invoices');
        $status = Router::strParam('status');
        $where  = $status ? "AND i.status = " . $db->quote($status) : '';
        $stmt   = $db->prepare(
            "SELECT i.*, u.unit_number, pr.name AS property_name
             FROM invoices i
             JOIN leases l      ON l.id  = i.lease_id
             JOIN units u       ON u.id  = l.unit_id
             JOIN properties pr ON pr.id = u.property_id
             WHERE i.tenant_id = ? $where ORDER BY i.invoice_date DESC LIMIT 50"
        );
        $stmt->execute([(int)$id]);
        ApiResponse::ok($stmt->fetchAll());
    });

    $router->get('tenants/{id}/payments', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:payments');
        $stmt = $db->prepare(
            "SELECT p.*, i.invoice_number, u.unit_number
             FROM payments p
             LEFT JOIN invoices i ON i.id = p.invoice_id
             LEFT JOIN leases l   ON l.id = p.lease_id
             LEFT JOIN units u    ON u.id = l.unit_id
             WHERE p.tenant_id = ? ORDER BY p.payment_date DESC LIMIT 50"
        );
        $stmt->execute([(int)$id]);
        ApiResponse::ok($stmt->fetchAll());
    });

    // GET  /tenants/{id}/kyc-documents ─────────────────────────
    $router->get('tenants/{id}/kyc-documents', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:tenants');
        $stmt = $db->prepare(
            "SELECT k.*, u.name AS uploaded_by_name
             FROM kyc_documents k
             LEFT JOIN users u ON u.id = k.uploaded_by
             WHERE k.tenant_id = ? ORDER BY k.created_at DESC"
        );
        $stmt->execute([(int)$id]);
        ApiResponse::ok($stmt->fetchAll());
    });

    // POST /tenants/{id}/kyc-documents ─────────────────────────
    $router->post('tenants/{id}/kyc-documents', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'write:tenants');
        $body = Router::body();

        $missing = array_filter(['document_type','original_name','file_path'], fn($f) => empty($body[$f]));
        if ($missing) ApiResponse::unprocessable('Missing: ' . implode(', ', $missing));

        $validTypes = ['national_id_front','national_id_back','passport','alien_id',
                       'driving_license','payslip','bank_statement','lease_agreement','other'];
        if (!in_array($body['document_type'], $validTypes, true)) {
            ApiResponse::badRequest('Invalid document_type.');
        }

        // Verify tenant exists
        $check = $db->prepare("SELECT id FROM tenants WHERE id = ?");
        $check->execute([(int)$id]);
        if (!$check->fetch()) ApiResponse::notFound('Tenant not found.');

        $db->prepare(
            "INSERT INTO kyc_documents
                (tenant_id, document_type, original_name, file_path, file_size, mime_type, notes, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            (int)$id,
            $body['document_type'],
            $body['original_name'],
            $body['file_path'],
            isset($body['file_size']) ? (int)$body['file_size'] : null,
            $body['mime_type'] ?? null,
            $body['notes'] ?? null,
            ApiAuth::userId(),
        ]);

        ApiResponse::created(['id' => (int)$db->lastInsertId()], 'Document record saved.');
    });

    // DELETE /tenants/{id}/kyc-documents/{doc_id} ──────────────
    $router->delete('tenants/{id}/kyc-documents/{doc_id}', function (string $id, string $doc_id) use ($db) {
        ApiAuth::requireScope($db, 'write:tenants');

        $stmt = $db->prepare("SELECT id, file_path FROM kyc_documents WHERE id = ? AND tenant_id = ?");
        $stmt->execute([(int)$doc_id, (int)$id]);
        $doc = $stmt->fetch();
        if (!$doc) ApiResponse::notFound('Document not found.');

        $db->prepare("DELETE FROM kyc_documents WHERE id = ?")->execute([(int)$doc_id]);
        ApiResponse::ok(['file_path' => $doc['file_path']], 'Document deleted.');
    });

    $router->get('tenants/{id}/maintenance', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:maintenance');
        $stmt = $db->prepare(
            "SELECT mr.*, u.unit_number, pr.name AS property_name
             FROM maintenance_requests mr
             LEFT JOIN units u      ON u.id  = mr.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             WHERE mr.tenant_id = ? ORDER BY mr.created_at DESC LIMIT 30"
        );
        $stmt->execute([(int)$id]);
        ApiResponse::ok($stmt->fetchAll());
    });
}
