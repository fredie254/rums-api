<?php
/**
 * Properties endpoints
 *
 * GET    /api/v1/properties              list (paginated, filterable)
 * POST   /api/v1/properties              create
 * GET    /api/v1/properties/{id}         single + units + stats
 * PUT    /api/v1/properties/{id}         full update
 * PATCH  /api/v1/properties/{id}         partial update
 * DELETE /api/v1/properties/{id}         soft delete
 * GET    /api/v1/properties/{id}/units   units for a property
 * GET    /api/v1/properties/{id}/stats   stats summary
 */
function registerPropertyRoutes(Router $router, PDO $db): void
{
    $svc = new PropertyService($db);

    $router->get('properties', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:properties');
        ApiResponse::paginated($svc->list(
            filters: [
                'search'        => Router::strParam('search'),
                'status'        => Router::strParam('status'),
                'property_type' => Router::strParam('type'),
                'landlord_id'   => Router::intParam('landlord_id'),
            ],
            page:    Router::page(),
            perPage: Router::perPage()
        ));
    });

    $router->post('properties', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:properties');
        $res = $svc->create(Router::body());
        $res['success']
            ? ApiResponse::created(['id' => $res['id']], $res['message'])
            : ApiResponse::unprocessable($res['message'], $res['errors'] ?? []);
    });

    $router->get('properties/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:properties');
        $prop = $svc->find((int)$id);
        $prop ? ApiResponse::ok($prop) : ApiResponse::notFound('Property not found.');
    });

    $router->put('properties/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:properties');
        $res = $svc->update((int)$id, Router::body());
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    $router->patch('properties/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:properties');
        $res = $svc->update((int)$id, Router::body());
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    $router->delete('properties/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $res = $svc->delete((int)$id);
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::conflict($res['message']);
    });

    $router->get('properties/{id}/units', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:units');
        $stmt = $db->prepare(
            "SELECT u.*, CONCAT(t.first_name,' ',t.last_name) AS tenant_name
             FROM units u
             LEFT JOIN leases l  ON l.unit_id = u.id AND l.status = 'active'
             LEFT JOIN tenants t ON t.id = l.tenant_id
             WHERE u.property_id = ?
             ORDER BY u.unit_number"
        );
        $stmt->execute([(int)$id]);
        ApiResponse::ok($stmt->fetchAll());
    });

    $router->get('properties/{id}/stats', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:properties');
        ApiResponse::ok($svc->stats((int)$id));
    });
}
