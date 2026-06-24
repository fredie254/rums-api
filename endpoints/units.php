<?php
/**
 * Units endpoints
 *
 * GET    /api/v1/units                list (filter by property_id, status, type)
 * POST   /api/v1/units                create
 * GET    /api/v1/units/{id}           single + current tenant/lease
 * PUT    /api/v1/units/{id}           full update
 * PATCH  /api/v1/units/{id}           partial update
 * PATCH  /api/v1/units/{id}/status    change status only
 */
function registerUnitRoutes(Router $router, PDO $db): void
{
    // GET /units ───────────────────────────────────────────────
    $router->get('units', function () use ($db) {
        ApiAuth::requireScope($db, 'read:units');

        $where  = ['1=1'];
        $params = [];

        $propId = Router::intParam('property_id');
        $status = Router::strParam('status');
        $type   = Router::strParam('type');

        if ($propId) { $where[] = 'u.property_id = ?'; $params[] = $propId; }
        if ($status) { $where[] = 'u.status = ?';       $params[] = $status; }
        if ($type)   { $where[] = 'u.unit_type = ?';    $params[] = $type; }

        $w       = 'WHERE ' . implode(' AND ', $where);
        $page    = Router::page();
        $perPage = Router::perPage();
        $offset  = ($page - 1) * $perPage;

        $cntStmt = $db->prepare("SELECT COUNT(*) FROM units u $w");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT u.*,
                pr.name AS property_name,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                t.phone AS tenant_phone,
                l.id AS lease_id, l.end_date AS lease_end
             FROM units u
             LEFT JOIN properties pr ON pr.id = u.property_id
             LEFT JOIN leases l      ON l.unit_id = u.id AND l.status = 'active'
             LEFT JOIN tenants t     ON t.id = l.tenant_id
             $w ORDER BY pr.name, u.unit_number
             LIMIT ? OFFSET ?"
        );
        foreach ($params as $k => $v) $stmt->bindValue($k + 1, $v);
        $stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset,  PDO::PARAM_INT);
        $stmt->execute();

        ApiResponse::ok($stmt->fetchAll(), '', [
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'total_pages'  => max(1, (int)ceil($total / $perPage)),
        ]);
    });

    // POST /units ──────────────────────────────────────────────
    $router->post('units', function () use ($db) {
        ApiAuth::requireScope($db, 'write:units');
        $body    = Router::body();
        $missing = array_filter(
            ['property_id', 'unit_number', 'unit_type', 'rent_amount'],
            fn($f) => empty($body[$f])
        );
        if ($missing) ApiResponse::unprocessable('Missing required fields: ' . implode(', ', $missing));

        $exists = $db->prepare("SELECT COUNT(*) FROM units WHERE property_id = ? AND unit_number = ?");
        $exists->execute([(int)$body['property_id'], $body['unit_number']]);
        if ($exists->fetchColumn() > 0) {
            ApiResponse::conflict('Unit number already exists in this property.');
        }

        $allowed = array_intersect_key($body, array_flip([
            'property_id','unit_number','unit_type','floor','block_number','bedrooms','bathrooms',
            'size_sqft','rent_amount','deposit_amount','furnished',
            'water_included','electricity_included','utility_charge',
            'amenities','description','status',
        ]));
        $allowed['status'] = $allowed['status'] ?? 'available';

        $cols   = implode(', ', array_keys($allowed));
        $places = implode(', ', array_fill(0, count($allowed), '?'));
        try {
            $db->prepare("INSERT INTO units ($cols) VALUES ($places)")->execute(array_values($allowed));
        } catch (Throwable $e) {
            ApiResponse::serverError('Failed to create unit.', $e);
        }

        ApiResponse::created(['id' => (int)$db->lastInsertId()], 'Unit created.');
    });

    // GET /units/{id} ──────────────────────────────────────────
    $router->get('units/{id}', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:units');

        $stmt = $db->prepare(
            "SELECT u.*, pr.name AS property_name,
                l.id AS lease_id, l.start_date, l.end_date, l.monthly_rent, l.status AS lease_status,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                t.email AS tenant_email, t.phone AS tenant_phone
             FROM units u
             LEFT JOIN properties pr ON pr.id = u.property_id
             LEFT JOIN leases l      ON l.unit_id = u.id AND l.status = 'active'
             LEFT JOIN tenants t     ON t.id = l.tenant_id
             WHERE u.id = ?"
        );
        $stmt->execute([(int)$id]);
        $unit = $stmt->fetch();
        if (!$unit) ApiResponse::notFound('Unit not found.');

        if ($unit['lease_id']) {
            $ps = $db->prepare(
                "SELECT id, payment_ref, amount, payment_date, payment_method
                 FROM payments WHERE lease_id = ? ORDER BY payment_date DESC LIMIT 6"
            );
            $ps->execute([$unit['lease_id']]);
            $unit['recent_payments'] = $ps->fetchAll();
        } else {
            $unit['recent_payments'] = [];
        }

        ApiResponse::ok($unit);
    });

    // PUT /units/{id} ──────────────────────────────────────────
    $router->put('units/{id}', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'write:units');
        $body  = Router::body();
        $check = $db->prepare("SELECT id FROM units WHERE id = ?");
        $check->execute([(int)$id]);
        if (!$check->fetch()) ApiResponse::notFound('Unit not found.');

        $allowed = array_intersect_key($body, array_flip([
            'unit_number','unit_type','floor','block_number','bedrooms','bathrooms',
            'size_sqft','rent_amount','deposit_amount','furnished',
            'water_included','electricity_included','utility_charge',
            'amenities','description','status',
        ]));
        if (!$allowed) ApiResponse::badRequest('No valid fields to update.');

        $set  = implode(', ', array_map(fn($k) => "$k = ?", array_keys($allowed)));
        $vals = [...array_values($allowed), (int)$id];
        $db->prepare("UPDATE units SET $set WHERE id = ?")->execute($vals);
        ApiResponse::ok(null, 'Unit updated.');
    });

    // PATCH /units/{id} ────────────────────────────────────────
    $router->patch('units/{id}', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'write:units');
        $body    = Router::body();
        $allowed = array_intersect_key($body, array_flip([
            'unit_number','unit_type','floor','block_number','bedrooms','bathrooms',
            'size_sqft','rent_amount','deposit_amount','furnished',
            'water_included','electricity_included','utility_charge',
            'amenities','description','status',
        ]));
        if (!$allowed) ApiResponse::badRequest('No valid fields to update.');

        $set  = implode(', ', array_map(fn($k) => "$k = ?", array_keys($allowed)));
        $vals = [...array_values($allowed), (int)$id];
        $db->prepare("UPDATE units SET $set WHERE id = ?")->execute($vals);
        ApiResponse::ok(null, 'Unit updated.');
    });

    // PATCH /units/{id}/status ─────────────────────────────────
    $router->patch('units/{id}/status', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'write:units');
        $status = Router::body()['status'] ?? '';
        $valid  = ['available', 'occupied', 'maintenance', 'inactive'];

        if (!in_array($status, $valid, true)) {
            ApiResponse::badRequest('status must be one of: ' . implode(', ', $valid));
        }

        $db->prepare("UPDATE units SET status = ? WHERE id = ?")->execute([$status, (int)$id]);
        ApiResponse::ok(['status' => $status], 'Unit status updated.');
    });
}
