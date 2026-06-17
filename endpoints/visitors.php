<?php
/**
 * Visitor log endpoints
 *
 * GET    /api/v1/visitors                list (date, property_id, status, search)
 * POST   /api/v1/visitors                check-in
 * PATCH  /api/v1/visitors/{id}/checkout  check out
 * PATCH  /api/v1/visitors/{id}/overstay  flag as overstay
 */
function registerVisitorRoutes(Router $router, PDO $db): void
{
    $router->get('visitors', function () use ($db) {
        ApiAuth::requireScope($db, 'read:properties');

        $date       = Router::strParam('date');
        $propId     = Router::intParam('property_id') ?: 0;
        $status     = Router::strParam('status');
        $search     = Router::strParam('search');
        $page       = Router::page();
        $perPage    = Router::perPage(50);
        $offset     = ($page - 1) * $perPage;

        $where  = ['1=1'];
        $params = [];

        if ($date)   { $where[] = 'DATE(vl.check_in) = ?';  $params[] = $date; }
        if ($propId) { $where[] = 'vl.property_id = ?';     $params[] = $propId; }
        if ($status) { $where[] = 'vl.status = ?';          $params[] = $status; }
        if ($search) {
            $where[] = '(vl.visitor_name LIKE ? OR vl.visitor_phone LIKE ? OR vl.visitor_id_no LIKE ?)';
            $s = "%$search%"; $params[] = $s; $params[] = $s; $params[] = $s;
        }

        $w = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM visitor_logs vl $w");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT vl.*,
                p.name AS property_name,
                u.unit_number,
                lu.name AS logged_by_name,
                TIMESTAMPDIFF(MINUTE, vl.check_in, COALESCE(vl.check_out, NOW())) AS duration_mins
             FROM visitor_logs vl
             LEFT JOIN properties p ON p.id = vl.property_id
             LEFT JOIN units u      ON u.id = vl.unit_id
             LEFT JOIN users lu     ON lu.id = vl.logged_by
             $w ORDER BY vl.check_in DESC LIMIT ? OFFSET ?"
        );
        foreach ($params as $k => $v) $stmt->bindValue($k + 1, $v);
        $stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        ApiResponse::ok($rows, '', [
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'total_pages'  => max(1, (int)ceil($total / $perPage)),
        ]);
    });

    $router->post('visitors', function () use ($db) {
        ApiAuth::requireScope($db, 'read:properties');
        $body    = Router::body();
        $user    = ApiAuth::user();
        $propId  = (int)($body['property_id'] ?? 0) ?: null;
        $hostName= $body['host_name'] ?? null;

        // Resolve unit_id and tenant_id from property + host_name (treated as unit_number)
        $unitId   = null;
        $tenantId = null;
        if ($propId && $hostName) {
            $us = $db->prepare("SELECT id FROM units WHERE property_id = ? AND unit_number = ? LIMIT 1");
            $us->execute([$propId, $hostName]);
            $unitRow = $us->fetch();
            if ($unitRow) {
                $unitId = (int)$unitRow['id'];
                $ts = $db->prepare("SELECT tenant_id FROM leases WHERE unit_id = ? AND status='active' LIMIT 1");
                $ts->execute([$unitId]);
                $tr = $ts->fetch();
                $tenantId = $tr ? (int)$tr['tenant_id'] : null;
            }
        }

        $checkIn = $body['check_in'] ?? date('Y-m-d H:i:s');

        $db->prepare(
            "INSERT INTO visitor_logs
             (property_id, unit_id, tenant_id, visitor_name, visitor_phone, visitor_id_no,
              visitor_id_type, vehicle_reg, purpose, host_name, check_in, badge_no, notes,
              status, logged_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'in',?)"
        )->execute([
            $propId,
            $unitId,
            $tenantId,
            $body['visitor_name']    ?? '',
            $body['visitor_phone']   ?? null,
            $body['visitor_id_no']   ?? null,
            $body['visitor_id_type'] ?? 'national_id',
            $body['vehicle_reg']     ?? null,
            $body['purpose']         ?? '',
            $hostName,
            $checkIn,
            $body['badge_no']        ?? null,
            $body['notes']           ?? null,
            $user['id'],
        ]);

        $newId = (int)$db->lastInsertId();
        ApiResponse::created(['id' => $newId], 'Visitor checked in.');
    });

    $router->patch('visitors/{id}/checkout', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:properties');
        $db->prepare(
            "UPDATE visitor_logs SET status='out', check_out=NOW(), updated_at=NOW() WHERE id=? AND status='in'"
        )->execute([(int)$id]);
        ApiResponse::ok(null, 'Visitor checked out.');
    });

    $router->patch('visitors/{id}/overstay', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:properties');
        $db->prepare(
            "UPDATE visitor_logs SET status='overstay', updated_at=NOW() WHERE id=? AND status='in'"
        )->execute([(int)$id]);
        ApiResponse::ok(null, 'Visitor flagged as overstay.');
    });
}
