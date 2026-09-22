<?php
/**
 * Security incidents endpoints
 *
 * GET    /api/v1/security/dashboard               KPI snapshot for security role
 * GET    /api/v1/security-incidents               list (date_from, date_to, property_id, severity, resolved)
 * GET    /api/v1/security-incidents/{id}          single
 * POST   /api/v1/security-incidents               create
 * PATCH  /api/v1/security-incidents/{id}          update notes/police_ref
 * POST   /api/v1/security-incidents/{id}/resolve  mark as resolved
 */
function registerSecurityIncidentRoutes(Router $router, PDO $db): void
{
    // ── Security Dashboard ─────────────────────────────────────
    $router->get('security/dashboard', function () use ($db) {
        ApiAuth::requireScope($db, 'read:properties');

        $today    = date('Y-m-d');
        $month0   = date('Y-m-01');
        $propId   = Router::intParam('property_id') ?: 0;
        $pf       = $propId ? "AND property_id = $propId" : '';
        $pfSi     = $propId ? "AND si.property_id = $propId" : '';
        $pfVl     = $propId ? "AND vl.property_id = $propId" : '';

        // ── Visitors: today's snapshot ─────────────────────────
        $visitors = $db->query(
            "SELECT
                COALESCE(COUNT(*), 0)                                         AS total_today,
                COALESCE(SUM(status = 'in'), 0)                               AS currently_inside,
                COALESCE(SUM(status = 'overstay'), 0)                         AS overstays,
                COALESCE(SUM(status = 'out'), 0)                              AS checked_out_today,
                COALESCE(SUM(DATE(check_in) = '$today'), 0)                   AS new_checkins_today
             FROM visitor_logs vl
             WHERE DATE(vl.check_in) = '$today' $pfVl"
        )->fetch();

        // ── Visitors: recent (last 7 days) ─────────────────────
        $visitorTrend = $db->query(
            "SELECT DATE(check_in) AS day, COUNT(*) AS count
             FROM visitor_logs vl
             WHERE DATE(check_in) >= DATE_SUB('$today', INTERVAL 6 DAY) $pfVl
             GROUP BY day ORDER BY day"
        )->fetchAll();

        // ── Incidents: current month ───────────────────────────
        $incidents = $db->query(
            "SELECT
                COALESCE(COUNT(*), 0)                               AS total_this_month,
                COALESCE(SUM(si.resolved = 0), 0)                   AS unresolved,
                COALESCE(SUM(si.severity = 'critical' AND si.resolved = 0), 0) AS critical_open,
                COALESCE(SUM(si.severity = 'high'     AND si.resolved = 0), 0) AS high_open,
                COALESCE(SUM(si.severity = 'medium'   AND si.resolved = 0), 0) AS medium_open,
                COALESCE(SUM(si.severity = 'low'      AND si.resolved = 0), 0) AS low_open
             FROM security_incidents si
             WHERE DATE(si.incident_date) BETWEEN '$month0' AND '$today' $pfSi"
        )->fetch();

        // ── Recent unresolved incidents (latest 5) ─────────────
        $recentIncidents = $db->query(
            "SELECT si.id, si.incident_type, si.severity, si.incident_date,
                    si.description, p.name AS property_name, si.resolved
             FROM security_incidents si
             LEFT JOIN properties p ON p.id = si.property_id
             WHERE si.resolved = 0 $pfSi
             ORDER BY FIELD(si.severity,'critical','high','medium','low'), si.incident_date DESC
             LIMIT 5"
        )->fetchAll();

        // ── Unit occupancy snapshot ────────────────────────────
        $occupancy = $db->query(
            "SELECT
                COALESCE(COUNT(*), 0)                      AS total_units,
                COALESCE(SUM(status = 'occupied'), 0)      AS occupied,
                COALESCE(SUM(status = 'available'), 0)     AS available,
                COALESCE(SUM(status = 'maintenance'), 0)   AS maintenance
             FROM units" . ($propId ? " WHERE property_id = $propId" : '')
        )->fetch();

        // ── Today's activity feed ──────────────────────────────
        $activityToday = $db->query(
            "SELECT 'visitor_checkin' AS type, visitor_name AS label,
                    check_in AS event_time, p.name AS property_name
             FROM visitor_logs vl
             LEFT JOIN properties p ON p.id = vl.property_id
             WHERE DATE(vl.check_in) = '$today' $pfVl
             UNION ALL
             SELECT 'incident' AS type, incident_type AS label,
                    incident_date AS event_time, p.name AS property_name
             FROM security_incidents si
             LEFT JOIN properties p ON p.id = si.property_id
             WHERE DATE(si.incident_date) = '$today' $pfSi
             ORDER BY event_time DESC
             LIMIT 20"
        )->fetchAll();

        ApiResponse::ok([
            'visitors'         => $visitors,
            'visitor_trend'    => $visitorTrend,
            'incidents'        => $incidents,
            'recent_incidents' => $recentIncidents,
            'occupancy'        => $occupancy,
            'activity_today'   => $activityToday,
        ]);
    });


    // ── Shared handlers ────────────────────────────────────────
    $listIncidents = function () use ($db) {
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

        try {
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
        } catch (Throwable $e) {
            ApiResponse::serverError('Failed to load incidents.', $e);
        }

        ApiResponse::ok($stmt->fetchAll(), '', [
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'total_pages'  => max(1, (int)ceil($total / $perPage)),
        ]);
    };

    $getIncident = function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:properties');
        try {
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
        } catch (Throwable $e) {
            ApiResponse::serverError('Failed to load incident.', $e);
        }
        $row ? ApiResponse::ok($row) : ApiResponse::notFound('Incident not found.');
    };

    $createIncident = function () use ($db) {
        ApiAuth::requireScope($db, 'read:properties');
        $body = Router::body();
        $user = ApiAuth::user();

        if (empty($body['incident_type'])) ApiResponse::unprocessable('incident_type is required.');
        if (empty($body['description']))   ApiResponse::unprocessable('description is required.');

        try {
            $db->prepare(
                "INSERT INTO security_incidents
                 (property_id, unit_id, incident_type, severity, incident_date,
                  description, persons_involved, action_taken, police_ref, logged_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                (int)($body['property_id'] ?? 0) ?: null,
                (int)($body['unit_id']     ?? 0) ?: null,
                $body['incident_type']    ?? 'other',
                $body['severity']         ?? 'medium',
                $body['incident_date']    ?? date('Y-m-d H:i:s'),
                $body['description']      ?? '',
                $body['persons_involved'] ?? null,
                $body['action_taken']     ?? null,
                $body['police_ref']       ?? null,
                $user['id'],
            ]);
        } catch (Throwable $e) {
            ApiResponse::serverError('Failed to save incident.', $e);
        }

        ApiResponse::created(['id' => (int)$db->lastInsertId()], 'Incident reported.');
    };

    $updateIncident = function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:properties');
        $body    = Router::body();
        $allowed = array_intersect_key($body, array_flip(['action_taken', 'police_ref', 'persons_involved']));
        if (!$allowed) ApiResponse::badRequest('No valid fields to update.');
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($allowed)));
        try {
            $db->prepare("UPDATE security_incidents SET $set WHERE id = ?")
               ->execute([...array_values($allowed), (int)$id]);
        } catch (Throwable $e) {
            ApiResponse::serverError('Failed to update incident.', $e);
        }
        ApiResponse::ok(null, 'Incident updated.');
    };

    $resolveIncident = function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:properties');
        $notes = Router::body()['resolution_notes'] ?? null;
        try {
            $db->prepare(
                "UPDATE security_incidents
                 SET resolved=1, resolved_at=NOW(),
                     action_taken = CONCAT(COALESCE(action_taken,''), IF(action_taken IS NOT NULL AND action_taken != '','\n',''), COALESCE(?,''))
                 WHERE id=?"
            )->execute([$notes, (int)$id]);
        } catch (Throwable $e) {
            ApiResponse::serverError('Failed to resolve incident.', $e);
        }
        ApiResponse::ok(null, 'Incident resolved.');
    };

    // ── Register under both canonical and security/ prefix ───────
    $router->get('security-incidents',              $listIncidents);
    $router->get('security/incidents',              $listIncidents);

    $router->get('security-incidents/{id}',         $getIncident);
    $router->get('security/incidents/{id}',         $getIncident);

    $router->post('security-incidents',             $createIncident);
    $router->post('security/incidents',             $createIncident);

    $router->patch('security-incidents/{id}',       $updateIncident);
    $router->patch('security/incidents/{id}',       $updateIncident);

    $router->post('security-incidents/{id}/resolve', $resolveIncident);
    $router->post('security/incidents/{id}/resolve', $resolveIncident);
}
