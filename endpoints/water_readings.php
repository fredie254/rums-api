<?php
/**
 * Water Readings endpoints
 *
 * GET    /api/v1/water-readings              list (paginated)
 * POST   /api/v1/water-readings              create new reading
 * GET    /api/v1/water-readings/{id}         view single
 * PUT    /api/v1/water-readings/{id}         edit
 *   — admin/super_admin: any reading, any time
 *   — manager/landlord/owner: only the current (latest) reading for the unit
 * DELETE /api/v1/water-readings/{id}         admin only
 */
function registerWaterReadingRoutes(Router $router, PDO $db): void
{
    // Shared helper: fetch one reading with enrichment
    $findReading = static function (int $id) use ($db): ?array {
        $stmt = $db->prepare(
            "SELECT wr.*,
                u.unit_number, u.water_rate AS current_unit_rate,
                pr.name AS property_name, pr.id AS property_id,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                rec.name AS recorded_by_name,
                (wr.id = (SELECT MAX(id2) FROM water_readings wr2 WHERE wr2.unit_id = wr.unit_id)) AS is_current,
                (SELECT prev.reading_value FROM water_readings prev
                 WHERE prev.unit_id = wr.unit_id
                   AND (prev.reading_date < wr.reading_date
                        OR (prev.reading_date = wr.reading_date AND prev.id < wr.id))
                 ORDER BY prev.reading_date DESC, prev.id DESC LIMIT 1) AS prev_reading
             FROM water_readings wr
             JOIN units u       ON u.id  = wr.unit_id
             JOIN properties pr ON pr.id = u.property_id
             LEFT JOIN leases l  ON l.id  = wr.lease_id
             LEFT JOIN tenants t ON t.id  = l.tenant_id
             LEFT JOIN users rec ON rec.id = wr.recorded_by
             WHERE wr.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $prev = $row['prev_reading'] !== null ? (float)$row['prev_reading'] : null;
        $curr = (float)$row['reading_value'];
        $row['prev_reading']  = $prev;
        $row['consumption']   = ($prev !== null && !$row['is_initial']) ? max(0, $curr - $prev) : 0;
        $row['amount']        = round($row['consumption'] * (float)$row['water_rate'], 2);
        $row['is_current']    = (bool)$row['is_current'];
        $row['is_initial']    = (bool)$row['is_initial'];
        $row['invoiced']      = (bool)$row['invoiced'];
        return $row;
    };

    // Permission check: can the current user edit this reading?
    $canEdit = static function (array $reading): bool {
        $user = ApiAuth::user();
        $role = $user['role'] ?? '';
        if ($role === 'admin' || $role === 'super_admin') return true;
        // manager / landlord / owner may only edit the current reading
        if (in_array($role, ['manager', 'property_manager', 'landlord', 'owner'], true)) {
            return (bool)$reading['is_current'];
        }
        return false;
    };

    // ── List ──────────────────────────────────────────────────
    $router->get('water-readings', function () use ($db) {
        ApiAuth::requireScope($db, 'read:leases');
        $page       = Router::intParam('page', 1);
        $perPage    = Router::intParam('per_page', 20);
        $unitId     = Router::intParam('unit_id');
        $propertyId = Router::intParam('property_id');
        $leaseId    = Router::intParam('lease_id');
        $landlordId = ApiAuth::landlordId($db);

        $where  = ['1=1'];
        $params = [];

        if ($unitId)     { $where[] = 'wr.unit_id = ?';    $params[] = $unitId; }
        if ($propertyId) { $where[] = 'pr.id = ?';         $params[] = $propertyId; }
        if ($leaseId)    { $where[] = 'wr.lease_id = ?';   $params[] = $leaseId; }
        if ($landlordId) { $where[] = 'pr.landlord_id = ?'; $params[] = $landlordId; }

        $w = 'WHERE ' . implode(' AND ', $where);

        $total = (int)$db->prepare(
            "SELECT COUNT(*) FROM water_readings wr
             JOIN units u ON u.id = wr.unit_id
             JOIN properties pr ON pr.id = u.property_id $w"
        )->execute($params) ? $db->query(
            "SELECT COUNT(*) FROM water_readings wr
             JOIN units u ON u.id = wr.unit_id
             JOIN properties pr ON pr.id = u.property_id $w"
        )->fetchColumn() : 0;

        // Use a proper fetch
        $cntStmt = $db->prepare(
            "SELECT COUNT(*) FROM water_readings wr
             JOIN units u ON u.id = wr.unit_id
             JOIN properties pr ON pr.id = u.property_id $w"
        );
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $perPage = max(1, min($perPage, 100));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $sql = "SELECT
                    wr.id, wr.unit_id, wr.lease_id, wr.reading_date,
                    wr.reading_value, wr.water_rate, wr.is_initial,
                    wr.invoiced, wr.notes, wr.created_at,
                    u.unit_number, pr.name AS property_name, pr.id AS property_id,
                    CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                    rec.name AS recorded_by_name,
                    (wr.id = (SELECT MAX(id2) FROM water_readings wr2 WHERE wr2.unit_id = wr.unit_id)) AS is_current,
                    (SELECT prev.reading_value FROM water_readings prev
                     WHERE prev.unit_id = wr.unit_id
                       AND (prev.reading_date < wr.reading_date
                            OR (prev.reading_date = wr.reading_date AND prev.id < wr.id))
                     ORDER BY prev.reading_date DESC, prev.id DESC LIMIT 1) AS prev_reading
                FROM water_readings wr
                JOIN units u       ON u.id  = wr.unit_id
                JOIN properties pr ON pr.id = u.property_id
                LEFT JOIN leases l  ON l.id  = wr.lease_id
                LEFT JOIN tenants t ON t.id  = l.tenant_id
                LEFT JOIN users rec ON rec.id = wr.recorded_by
                $w
                ORDER BY wr.reading_date DESC, wr.id DESC
                LIMIT ? OFFSET ?";

        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k + 1, $v);
        }
        $stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $prev = $r['prev_reading'] !== null ? (float)$r['prev_reading'] : null;
            $curr = (float)$r['reading_value'];
            $r['prev_reading'] = $prev;
            $r['consumption']  = ($prev !== null && !$r['is_initial']) ? max(0, $curr - $prev) : 0;
            $r['amount']       = round($r['consumption'] * (float)$r['water_rate'], 2);
            $r['is_current']   = (bool)$r['is_current'];
            $r['is_initial']   = (bool)$r['is_initial'];
            $r['invoiced']     = (bool)$r['invoiced'];
        }

        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        ApiResponse::ok($rows, null, [
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'total_pages'  => $totalPages,
            'from'         => $offset + 1,
            'to'           => min($offset + $perPage, $total),
        ]);
    });

    // ── Create ────────────────────────────────────────────────
    $router->post('water-readings', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin', 'manager', 'property_manager', 'landlord', 'owner');
        $body = Router::body();

        if (empty($body['unit_id']))       ApiResponse::unprocessable('unit_id is required.');
        if (!isset($body['reading_value'])) ApiResponse::unprocessable('reading_value is required.');
        if (empty($body['reading_date']))  ApiResponse::unprocessable('reading_date is required.');

        $unitId       = (int)$body['unit_id'];
        $readingValue = (float)$body['reading_value'];
        $readingDate  = $body['reading_date'];

        // Validate date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $readingDate)) {
            ApiResponse::unprocessable('reading_date must be YYYY-MM-DD.');
        }

        // Landlord scope: ensure unit belongs to their portfolio
        $landlordId = ApiAuth::landlordId($db);
        if ($landlordId) {
            $ok = $db->prepare(
                "SELECT 1 FROM units u JOIN properties pr ON pr.id = u.property_id
                 WHERE u.id = ? AND pr.landlord_id = ? LIMIT 1"
            );
            $ok->execute([$unitId, $landlordId]);
            if (!$ok->fetchColumn()) ApiResponse::forbidden('Unit does not belong to your portfolio.');
        }

        // Snapshot water rate from unit
        $unitRow = $db->prepare("SELECT water_rate, id FROM units WHERE id = ? LIMIT 1");
        $unitRow->execute([$unitId]);
        $unit = $unitRow->fetch();
        if (!$unit) ApiResponse::notFound('Unit not found.');

        $waterRate = (float)($body['water_rate'] ?? $unit['water_rate']);

        // Find active lease for unit (optional — just for linking)
        $leaseStmt = $db->prepare(
            "SELECT id FROM leases WHERE unit_id = ? AND status = 'active' ORDER BY start_date DESC LIMIT 1"
        );
        $leaseStmt->execute([$unitId]);
        $leaseId = $leaseStmt->fetchColumn() ?: null;

        // Ensure new reading > previous reading (guards against data entry errors)
        $prevStmt = $db->prepare(
            "SELECT reading_value FROM water_readings
             WHERE unit_id = ?
               AND (reading_date < ? OR (reading_date = ? AND id < 999999999))
             ORDER BY reading_date DESC, id DESC LIMIT 1"
        );
        $prevStmt->execute([$unitId, $readingDate, $readingDate]);
        $prevVal = $prevStmt->fetchColumn();
        if ($prevVal !== false && $readingValue < (float)$prevVal) {
            ApiResponse::unprocessable("Reading value ($readingValue) cannot be less than the previous reading ($prevVal).");
        }

        $user = ApiAuth::user();
        $db->prepare(
            "INSERT INTO water_readings (unit_id, lease_id, reading_date, reading_value, water_rate, is_initial, notes, recorded_by)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?)"
        )->execute([
            $unitId,
            $leaseId,
            $readingDate,
            $readingValue,
            $waterRate,
            $body['notes'] ?? null,
            $user['id'],
        ]);

        ApiResponse::created(['id' => (int)$db->lastInsertId()], 'Water reading recorded.');
    });

    // ── View single ───────────────────────────────────────────
    $router->get('water-readings/{id}', function (string $id) use ($db, $findReading) {
        ApiAuth::requireScope($db, 'read:leases');
        $reading = $findReading((int)$id);
        if (!$reading) ApiResponse::notFound('Water reading not found.');

        // Landlord: restrict to own portfolio
        $landlordId = ApiAuth::landlordId($db);
        if ($landlordId) {
            $ok = $db->prepare(
                "SELECT 1 FROM units u JOIN properties pr ON pr.id = u.property_id
                 WHERE u.id = ? AND pr.landlord_id = ? LIMIT 1"
            );
            $ok->execute([$reading['unit_id'], $landlordId]);
            if (!$ok->fetchColumn()) ApiResponse::notFound('Water reading not found.');
        }

        ApiResponse::ok($reading);
    });

    // ── Update ────────────────────────────────────────────────
    $router->put('water-readings/{id}', function (string $id) use ($db, $findReading, $canEdit) {
        ApiAuth::require($db);
        $reading = $findReading((int)$id);
        if (!$reading) ApiResponse::notFound('Water reading not found.');

        if (!$canEdit($reading)) {
            $user = ApiAuth::user();
            $role = $user['role'] ?? '';
            if (in_array($role, ['manager', 'property_manager', 'landlord', 'owner'], true)) {
                ApiResponse::forbidden('Only the current (most recent) reading can be edited. Contact a super admin to edit historical records.');
            }
            ApiResponse::forbidden('You do not have permission to edit this reading.');
        }

        $body    = Router::body();
        $allowed = [];

        if (isset($body['reading_value'])) $allowed['reading_value'] = (float)$body['reading_value'];
        if (isset($body['reading_date']))  $allowed['reading_date']  = $body['reading_date'];
        if (isset($body['notes']))         $allowed['notes']         = $body['notes'];

        // Only admin can change the water rate
        $userRole = ApiAuth::user()['role'] ?? '';
        if (isset($body['water_rate']) && in_array($userRole, ['admin', 'super_admin'], true)) {
            $allowed['water_rate'] = (float)$body['water_rate'];
        }

        if (!$allowed) ApiResponse::badRequest('No valid fields to update.');

        $set  = implode(', ', array_map(fn($k) => "$k = ?", array_keys($allowed)));
        $vals = [...array_values($allowed), (int)$id];
        $db->prepare("UPDATE water_readings SET $set WHERE id = ?")->execute($vals);

        ApiResponse::ok(null, 'Water reading updated.');
    });

    // ── Delete ────────────────────────────────────────────────
    $router->delete('water-readings/{id}', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin');
        $stmt = $db->prepare("SELECT id FROM water_readings WHERE id = ?");
        $stmt->execute([(int)$id]);
        if (!$stmt->fetch()) ApiResponse::notFound('Water reading not found.');
        $db->prepare("DELETE FROM water_readings WHERE id = ?")->execute([(int)$id]);
        ApiResponse::ok(null, 'Water reading deleted.');
    });
}
