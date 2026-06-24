<?php
/**
 * Security incidents endpoints
 *
 * GET    /api/v1/security-incidents               list (date_from, date_to, property_id, severity, resolved)
 * GET    /api/v1/security-incidents/{id}          single
 * POST   /api/v1/security-incidents               create
 * PATCH  /api/v1/security-incidents/{id}          update notes/police_ref
 * POST   /api/v1/security-incidents/{id}/resolve  mark as resolved
 */
function registerSecurityIncidentRoutes(Router $router, PDO $db): void
{
    $router->get('security-incidents', function () use ($db) {
        ApiAuth::requireScope($db, 'read:properties');

        $dateFrom = Router::strParam('date_from') ?: date('Y-m-01');
        $dateTo   = Router::strParam('date_to')   ?: date('Y-m-d');
        $propId   = Router::intParam('property_id') ?: 0;
        $severity = Router::strParam('severity');
        $resolved = Router::strParam('resolved', '0');
        $page     = Router::page();
        $perPage  = Router::perPage(50);
        $offset   = ($page - 1) * $perPage;

        $where  = ["DATE(si.incident_date) BETWEEN ? AND ?"];
        $params = [$dateFrom, $dateTo];

        if ($resolved !== 'all') { $where[] = 'si.resolved = ?'; $params[] = (int)$resolved; }
        if ($severity)           { $where[] = 'si.severity = ?'; $params[] = $severity; }
        if ($propId)             { $where[] = 'si.property_id = ?'; $params[] = $propId; }

        $w = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM security_incidents si $w");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT si.*, p.name AS property_name, u.unit_number, lu.name AS logged_by_name
             FROM security_incidents si
             LEFT JOIN properties p ON p.id = si.property_id
             LEFT JOIN units u      ON u.id = si.unit_id
             LEFT JOIN users lu     ON lu.id = si.logged_by
             $w
             ORDER BY FIELD(si.severity,'critical','high','medium','low'), si.incident_date DESC
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

    $router->get('security-incidents/{id}', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:properties');
        $stmt = $db->prepare(
            "SELECT si.*, p.name AS property_name, u.unit_number, lu.name AS logged_by_name
             FROM security_incidents si
             LEFT JOIN properties p ON p.id = si.property_id
             LEFT JOIN units u      ON u.id = si.unit_id
             LEFT JOIN users lu     ON lu.id = si.logged_by
             WHERE si.id = ?"
        );
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch();
        $row ? ApiResponse::ok($row) : ApiResponse::notFound('Incident not found.');
    });

    $router->post('security-incidents', function () use ($db) {
        ApiAuth::requireScope($db, 'read:properties');
        $body = Router::body();
        $user = ApiAuth::user();

        if (empty($body['incident_type'])) {
            ApiResponse::unprocessable('incident_type is required.');
        }
        if (empty($body['description'])) {
            ApiResponse::unprocessable('description is required.');
        }

        try {
            $db->prepare(
                "INSERT INTO security_incidents
                 (property_id, unit_id, incident_type, severity, incident_date,
                  description, persons_involved, action_taken, police_ref, logged_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                (int)($body['property_id'] ?? 0) ?: null,
                (int)($body['unit_id']     ?? 0) ?: null,
                $body['incident_type']       ?? 'other',
                $body['severity']            ?? 'medium',
                $body['incident_date']       ?? date('Y-m-d H:i:s'),
                $body['description']         ?? '',
                $body['persons_involved']    ?? null,
                $body['action_taken']        ?? null,
                $body['police_ref']          ?? null,
                $user['id'],
            ]);
        } catch (Throwable $e) {
            ApiResponse::serverError('Failed to save incident.', $e);
        }

        ApiResponse::created(['id' => (int)$db->lastInsertId()], 'Incident reported.');
    });

    $router->patch('security-incidents/{id}', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:properties');
        $body    = Router::body();
        $allowed = array_intersect_key($body, array_flip(['action_taken', 'police_ref', 'persons_involved']));
        if (!$allowed) ApiResponse::badRequest('No valid fields to update.');
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($allowed)));
        $db->prepare("UPDATE security_incidents SET $set WHERE id = ?")
           ->execute([...array_values($allowed), (int)$id]);
        ApiResponse::ok(null, 'Incident updated.');
    });

    $router->post('security-incidents/{id}/resolve', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:properties');
        $notes = Router::body()['resolution_notes'] ?? null;
        $db->prepare(
            "UPDATE security_incidents
             SET resolved=1, resolved_at=NOW(),
                 action_taken = CONCAT(COALESCE(action_taken,''), IF(action_taken IS NOT NULL AND action_taken != '','\n',''), COALESCE(?,''))
             WHERE id=?"
        )->execute([$notes, (int)$id]);
        ApiResponse::ok(null, 'Incident resolved.');
    });
}
