<?php
/**
 * Occupancy log endpoints
 *
 * GET  /api/v1/occupancy-logs   list (date_from, date_to, property_id, event_type)
 * POST /api/v1/occupancy-logs   create event
 */
function registerOccupancyLogRoutes(Router $router, PDO $db): void
{
    $router->get('occupancy-logs', function () use ($db) {
        ApiAuth::requireScope($db, 'read:properties');

        $dateFrom  = Router::strParam('date_from') ?: date('Y-m-01');
        $dateTo    = Router::strParam('date_to')   ?: date('Y-m-d');
        $propId    = Router::intParam('property_id') ?: 0;
        $eventType = Router::strParam('event_type');
        $page      = Router::page();
        $perPage   = Router::perPage(100);
        $offset    = ($page - 1) * $perPage;

        $where  = ['ol.event_date BETWEEN ? AND ?'];
        $params = [$dateFrom, $dateTo];

        if ($propId)    { $where[] = 'ol.property_id = ?'; $params[] = $propId; }
        if ($eventType) { $where[] = 'ol.event_type = ?';  $params[] = $eventType; }

        $w = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM occupancy_logs ol $w");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT ol.*,
                p.name AS property_name,
                u.unit_number,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                lu.name AS logged_by_name
             FROM occupancy_logs ol
             LEFT JOIN properties p ON p.id = ol.property_id
             LEFT JOIN units u      ON u.id = ol.unit_id
             LEFT JOIN tenants t    ON t.id = ol.tenant_id
             LEFT JOIN users lu     ON lu.id = ol.logged_by
             $w ORDER BY ol.event_date DESC, ol.event_time DESC, ol.id DESC
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

    $router->post('occupancy-logs', function () use ($db) {
        ApiAuth::requireScope($db, 'read:properties');
        $body    = Router::body();
        $user    = ApiAuth::user();
        $unitId  = (int)($body['unit_id'] ?? 0) ?: null;

        // Auto-resolve tenant from unit
        $tenantId = null;
        if ($unitId) {
            $ts = $db->prepare("SELECT tenant_id FROM leases WHERE unit_id = ? AND status='active' LIMIT 1");
            $ts->execute([$unitId]);
            $tr = $ts->fetch();
            $tenantId = $tr ? (int)$tr['tenant_id'] : null;
        }

        $db->prepare(
            "INSERT INTO occupancy_logs
             (property_id, unit_id, tenant_id, event_type, event_date, event_time,
              description, persons_count, authorized_by, reference_no, logged_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            (int)($body['property_id'] ?? 0) ?: null,
            $unitId,
            $tenantId,
            $body['event_type']     ?? 'other',
            $body['event_date']     ?? date('Y-m-d'),
            $body['event_time']     ?? null,
            $body['description']    ?? null,
            (int)($body['persons_count'] ?? 1),
            $body['authorized_by']  ?? null,
            $body['reference_no']   ?? null,
            $user['id'],
        ]);

        ApiResponse::created(['id' => (int)$db->lastInsertId()], 'Occupancy event logged.');
    });
}
