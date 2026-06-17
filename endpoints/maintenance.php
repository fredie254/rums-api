<?php
/**
 * Maintenance endpoints
 *
 * GET    /api/v1/maintenance                   list work orders (filterable)
 * GET    /api/v1/maintenance/summary            stats summary
 * POST   /api/v1/maintenance                   create work order
 * GET    /api/v1/maintenance/{id}              single work order
 * PUT    /api/v1/maintenance/{id}              full update
 * PATCH  /api/v1/maintenance/{id}              partial update
 * POST   /api/v1/maintenance/{id}/assign       assign to staff member
 * POST   /api/v1/maintenance/{id}/start        mark as in_progress
 * POST   /api/v1/maintenance/{id}/complete     complete with cost data
 */
function registerMaintenanceRoutes(Router $router, PDO $db): void
{
    $svc = new MaintenanceService($db);

    // Static routes first
    $router->get('maintenance/summary', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:maintenance');
        $pid = Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->summary($pid));
    });

    $router->get('maintenance', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:maintenance');
        $user    = ApiAuth::user();
        $filters = [
            'status'      => Router::strParam('status'),
            'priority'    => Router::strParam('priority'),
            'property_id' => Router::intParam('property_id'),
            'unit_id'     => Router::intParam('unit_id'),
            'assigned_to' => Router::intParam('assigned_to'),
        ];

        // Maintenance staff see only their assignments
        if ($user['role'] === 'maintenance') {
            $filters['assigned_to'] = $user['id'];
        }
        // Tenants see only their own requests
        if ($user['role'] === 'tenant') {
            $row = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $row->execute([$user['id']]);
            $t = $row->fetch();
            $filters['tenant_id'] = $t ? (int)$t['id'] : 0;
        }

        ApiResponse::paginated($svc->list($filters, Router::page(), Router::perPage()));
    });

    $router->post('maintenance', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:maintenance');
        $body = Router::body();
        $user = ApiAuth::user();

        // Tenants can only submit for their own active unit
        if ($user['role'] === 'tenant') {
            $row = $db->prepare(
                "SELECT l.unit_id FROM leases l
                 JOIN tenants t ON t.id = l.tenant_id
                 WHERE t.user_id = ? AND l.status = 'active' LIMIT 1"
            );
            $row->execute([$user['id']]);
            $r = $row->fetch();
            if (!$r) ApiResponse::forbidden('No active lease found.');
            $body['unit_id'] = $r['unit_id'];
        }

        $res = $svc->create($body);
        $res['success']
            ? ApiResponse::created(
                ['id' => $res['id'], 'request_number' => $res['request_number']],
                $res['message']
              )
            : ApiResponse::unprocessable($res['message'], $res['errors'] ?? []);
    });

    $router->get('maintenance/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:maintenance');
        $wo = $svc->find((int)$id);
        $wo ? ApiResponse::ok($wo) : ApiResponse::notFound('Work order not found.');
    });

    $router->put('maintenance/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:maintenance');
        $res = $svc->update((int)$id, Router::body());
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    $router->patch('maintenance/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:maintenance');
        $res = $svc->update((int)$id, Router::body());
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    $router->post('maintenance/{id}/assign', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $body       = Router::body();
        $assignedTo = (int)($body['assigned_to'] ?? 0);
        if (!$assignedTo) ApiResponse::badRequest('assigned_to is required.');

        $db->prepare(
            "UPDATE maintenance_requests
             SET assigned_to = ?,
                 status = IF(status = 'open', 'in_progress', status)
             WHERE id = ?"
        )->execute([$assignedTo, (int)$id]);

        ApiResponse::ok(null, 'Work order assigned.');
    });

    $router->post('maintenance/{id}/start', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'write:maintenance');
        $db->prepare(
            "UPDATE maintenance_requests
             SET status = 'in_progress',
                 work_started = COALESCE(work_started, NOW())
             WHERE id = ?"
        )->execute([(int)$id]);
        ApiResponse::ok(null, 'Work order started.');
    });

    $router->post('maintenance/{id}/complete', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'write:maintenance');
        $body = Router::body();
        $db->prepare(
            "UPDATE maintenance_requests SET
                status = 'completed', work_completed = NOW(),
                labour_hours = ?, materials_cost = ?, labour_cost = ?,
                contractor_name = ?,
                notes = CONCAT(COALESCE(notes,''), IF(notes IS NOT NULL,'\n',''), COALESCE(?,'')),
                updated_at = NOW()
             WHERE id = ?"
        )->execute([
            (float)($body['labour_hours']    ?? 0),
            (float)($body['materials_cost']  ?? 0),
            (float)($body['labour_cost']     ?? 0),
            $body['contractor_name']   ?? null,
            $body['completion_notes']  ?? null,
            (int)$id,
        ]);
        ApiResponse::ok(null, 'Work order completed.');
    });
}
