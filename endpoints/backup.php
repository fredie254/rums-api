<?php
/**
 * Backup & Export endpoint
 *
 * POST /api/v1/backup/download
 *   Body (JSON): {
 *       datasets:    string[],
 *       date_from?:  string,   // empty = all time
 *       date_to?:    string,
 *       property_id?: int      // optional — filter by one property
 *   }
 *   Response: application/zip binary stream
 */
function registerBackupRoutes(Router $router, PDO $db): void
{
    $router->post('backup/download', function () use ($db) {
        ApiAuth::require($db);

        $body       = json_decode(file_get_contents('php://input'), true) ?? [];
        $datasets   = array_map('strval', (array)($body['datasets'] ?? []));
        $dateFrom   = trim((string)($body['date_from'] ?? ''));
        $dateTo     = trim((string)($body['date_to']   ?? ''));
        $propertyId = isset($body['property_id']) && $body['property_id'] ? (int)$body['property_id'] : null;

        if (empty($datasets)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No datasets selected.']);
            return;
        }

        $landlordId = ApiAuth::landlordId($db);
        $tenantId   = ApiAuth::tenantId($db);
        $from       = $dateFrom ?: null;
        $to         = $dateTo   ?: null;
        $allTime    = ($from === null && $to === null);

        // Resolve property name for ZIP filename
        $propertyLabel = 'All_Properties';
        if ($propertyId) {
            $propRow = $db->prepare('SELECT name FROM properties WHERE id = ? LIMIT 1');
            $propRow->execute([$propertyId]);
            $pn = $propRow->fetchColumn();
            if ($pn) $propertyLabel = preg_replace('/[^A-Za-z0-9_\-]/', '_', $pn);
        }

        // Build a property scope clause that can be embedded into WHERE
        // Returns [sql_fragment, params_array]
        $propScope = static function (string $propAlias) use ($propertyId, $landlordId): array {
            if ($propertyId) {
                return ["{$propAlias}.id = ?", [$propertyId]];
            }
            if ($landlordId) {
                return ["{$propAlias}.landlord_id = ?", [$landlordId]];
            }
            return ['1=1', []];
        };

        // ── CSV generators (keyed by dataset id) ──────────────────────────
        $generators = [];

        $generators['payments'] = static function () use ($db, $propScope, $tenantId, $from, $to): array {
            [$pCond, $pParams] = $propScope('pr');
            $where  = ['p.status = ?'];
            $params = ['completed'];
            if ($pCond !== '1=1') { $where[] = $pCond; $params = array_merge($params, $pParams); }
            if ($tenantId) { $where[] = 'p.tenant_id = ?'; $params[] = $tenantId; }
            if ($from) { $where[] = 'p.payment_date >= ?'; $params[] = $from; }
            if ($to)   { $where[] = 'p.payment_date <= ?'; $params[] = $to; }
            $stmt = $db->prepare(
                "SELECT p.payment_ref                                                  AS `Ref`,
                        CONCAT(COALESCE(t.first_name,''),' ',COALESCE(t.last_name,'')) AS `Tenant`,
                        u.unit_number  AS `Unit`,
                        pr.name        AS `Property`,
                        p.amount       AS `Amount`,
                        p.payment_method AS `Method`,
                        p.payment_date   AS `Date`,
                        p.status         AS `Status`
                   FROM payments p
                   LEFT JOIN leases      l  ON l.id  = p.lease_id
                   LEFT JOIN units       u  ON u.id  = l.unit_id
                   LEFT JOIN properties  pr ON pr.id = u.property_id
                   LEFT JOIN tenants     t  ON t.id  = p.tenant_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY p.payment_date DESC LIMIT 5000"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        $generators['invoices'] = static function () use ($db, $propScope, $tenantId, $from, $to): array {
            [$pCond, $pParams] = $propScope('pr');
            $where  = ['1=1'];
            $params = [];
            if ($pCond !== '1=1') { $where[] = $pCond; $params = array_merge($params, $pParams); }
            if ($tenantId) { $where[] = 'i.tenant_id = ?'; $params[] = $tenantId; }
            if ($from) { $where[] = 'i.due_date >= ?'; $params[] = $from; }
            if ($to)   { $where[] = 'i.due_date <= ?'; $params[] = $to; }
            $stmt = $db->prepare(
                "SELECT i.invoice_number                                                AS `Invoice #`,
                        CONCAT(COALESCE(t.first_name,''),' ',COALESCE(t.last_name,'')) AS `Tenant`,
                        u.unit_number     AS `Unit`,
                        pr.name           AS `Property`,
                        i.total_amount    AS `Total`,
                        i.amount_paid     AS `Paid`,
                        (i.total_amount - i.amount_paid) AS `Balance`,
                        i.due_date        AS `Due Date`,
                        i.status          AS `Status`
                   FROM invoices i
                   LEFT JOIN leases     l  ON l.id  = i.lease_id
                   LEFT JOIN units      u  ON u.id  = l.unit_id
                   LEFT JOIN properties pr ON pr.id = u.property_id
                   LEFT JOIN tenants    t  ON t.id  = i.tenant_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY i.due_date DESC LIMIT 5000"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        $generators['invoices_arrears'] = static function () use ($db, $propScope, $tenantId, $from, $to): array {
            [$pCond, $pParams] = $propScope('pr');
            $where  = ["i.status IN ('unpaid','partial','overdue')"];
            $params = [];
            if ($pCond !== '1=1') { $where[] = $pCond; $params = array_merge($params, $pParams); }
            if ($tenantId) { $where[] = 'i.tenant_id = ?'; $params[] = $tenantId; }
            if ($from) { $where[] = 'i.due_date >= ?'; $params[] = $from; }
            if ($to)   { $where[] = 'i.due_date <= ?'; $params[] = $to; }
            $stmt = $db->prepare(
                "SELECT i.invoice_number                                                AS `Invoice #`,
                        CONCAT(COALESCE(t.first_name,''),' ',COALESCE(t.last_name,'')) AS `Tenant`,
                        u.unit_number     AS `Unit`,
                        pr.name           AS `Property`,
                        i.total_amount    AS `Total`,
                        i.amount_paid     AS `Paid`,
                        (i.total_amount - i.amount_paid) AS `Balance`,
                        i.due_date        AS `Due Date`,
                        i.status          AS `Status`
                   FROM invoices i
                   LEFT JOIN leases     l  ON l.id  = i.lease_id
                   LEFT JOIN units      u  ON u.id  = l.unit_id
                   LEFT JOIN properties pr ON pr.id = u.property_id
                   LEFT JOIN tenants    t  ON t.id  = i.tenant_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY i.due_date DESC LIMIT 5000"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        $generators['leases'] = static function () use ($db, $propScope, $tenantId, $from, $to): array {
            [$pCond, $pParams] = $propScope('pr');
            $where  = ['1=1'];
            $params = [];
            if ($pCond !== '1=1') { $where[] = $pCond; $params = array_merge($params, $pParams); }
            if ($tenantId) { $where[] = 'l.tenant_id = ?'; $params[] = $tenantId; }
            if ($from) { $where[] = 'l.start_date >= ?'; $params[] = $from; }
            if ($to)   { $where[] = 'l.start_date <= ?'; $params[] = $to; }
            $stmt = $db->prepare(
                "SELECT CONCAT(COALESCE(t.first_name,''),' ',COALESCE(t.last_name,'')) AS `Tenant`,
                        u.unit_number AS `Unit`,
                        pr.name       AS `Property`,
                        l.start_date  AS `Start`,
                        l.end_date    AS `End`,
                        l.rent_amount AS `Rent`,
                        l.deposit     AS `Deposit`,
                        l.status      AS `Status`
                   FROM leases l
                   LEFT JOIN units      u  ON u.id  = l.unit_id
                   LEFT JOIN properties pr ON pr.id = u.property_id
                   LEFT JOIN tenants    t  ON t.id  = l.tenant_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY l.start_date DESC LIMIT 5000"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        $generators['tenants'] = static function () use ($db, $propScope, $tenantId): array {
            [$pCond, $pParams] = $propScope('pr');
            $where  = ['1=1'];
            $params = [];
            if ($tenantId) {
                $where[]  = 't.id = ?';
                $params[] = $tenantId;
            } elseif ($pCond !== '1=1') {
                $where[]  = $pCond;
                $params   = array_merge($params, $pParams);
            }
            $stmt = $db->prepare(
                "SELECT CONCAT(COALESCE(t.first_name,''),' ',COALESCE(t.last_name,'')) AS `Name`,
                        t.email       AS `Email`,
                        t.phone       AS `Phone`,
                        u.unit_number AS `Unit`,
                        pr.name       AS `Property`,
                        t.status      AS `Status`
                   FROM tenants t
                   LEFT JOIN leases     l  ON l.tenant_id = t.id AND l.status = 'active'
                   LEFT JOIN units      u  ON u.id  = l.unit_id
                   LEFT JOIN properties pr ON pr.id = u.property_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY t.last_name, t.first_name LIMIT 5000"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        $generators['water_readings'] = static function () use ($db, $propScope, $tenantId, $from, $to): array {
            [$pCond, $pParams] = $propScope('pr');
            $where  = ['1=1'];
            $params = [];
            if ($pCond !== '1=1') { $where[] = $pCond; $params = array_merge($params, $pParams); }
            if ($tenantId) { $where[] = 'l.tenant_id = ?'; $params[] = $tenantId; }
            if ($from) { $where[] = 'wr.reading_date >= ?'; $params[] = $from; }
            if ($to)   { $where[] = 'wr.reading_date <= ?'; $params[] = $to; }
            $stmt = $db->prepare(
                "SELECT u.unit_number    AS `Unit`,
                        pr.name          AS `Property`,
                        wr.reading_date  AS `Date`,
                        wr.reading_value AS `Reading (m3)`,
                        wr.water_rate    AS `Rate (KES/m3)`,
                        CASE WHEN wr.is_initial = 1 THEN 'Opening' ELSE 'Regular' END AS `Type`,
                        wr.notes         AS `Notes`
                   FROM water_readings wr
                   LEFT JOIN units      u  ON u.id  = wr.unit_id
                   LEFT JOIN properties pr ON pr.id = u.property_id
                   LEFT JOIN leases     l  ON l.id  = wr.lease_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY wr.reading_date DESC LIMIT 5000"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        $generators['maintenance'] = static function () use ($db, $propScope, $tenantId, $from, $to): array {
            [$pCond, $pParams] = $propScope('pr');
            $where  = ['1=1'];
            $params = [];
            if ($pCond !== '1=1') { $where[] = $pCond; $params = array_merge($params, $pParams); }
            if ($tenantId) { $where[] = 'm.tenant_id = ?'; $params[] = $tenantId; }
            if ($from) { $where[] = 'DATE(m.created_at) >= ?'; $params[] = $from; }
            if ($to)   { $where[] = 'DATE(m.created_at) <= ?'; $params[] = $to; }
            $stmt = $db->prepare(
                "SELECT m.title           AS `Title`,
                        u.unit_number     AS `Unit`,
                        pr.name           AS `Property`,
                        m.priority        AS `Priority`,
                        m.status          AS `Status`,
                        m.category        AS `Category`,
                        DATE(m.created_at)   AS `Created`,
                        DATE(m.resolved_at)  AS `Resolved`
                   FROM maintenance_requests m
                   LEFT JOIN units      u  ON u.id  = m.unit_id
                   LEFT JOIN properties pr ON pr.id = u.property_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY m.created_at DESC LIMIT 5000"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        // ── Build CSV from rows ─────────────────────────────────────────────
        $makeCsv = static function (array $rows): string {
            if (empty($rows)) return "No data found\r\n";
            $headers = array_keys($rows[0]);
            $lines   = [implode(',', array_map(fn($h) => '"' . str_replace('"', '""', (string)$h) . '"', $headers))];
            foreach ($rows as $row) {
                $lines[] = implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"', $row));
            }
            return "\xEF\xBB\xBF" . implode("\r\n", $lines);
        };

        $timestamp  = date('Y-m-d_Hi');
        $validSets  = array_filter(array_map(fn($d) => preg_replace('/[^a-z_]/', '', strtolower((string)$d)), $datasets),
                                   fn($d) => isset($generators[$d]));
        $validSets  = array_values($validSets);

        if (empty($validSets)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No valid datasets selected.']);
            return;
        }

        // ── Single dataset → plain CSV ──────────────────────────────────────
        if (count($validSets) === 1) {
            $ds = $validSets[0];
            try {
                $rows    = ($generators[$ds])();
                $csv     = $makeCsv($rows);
                $csvName = "RUMS_{$propertyLabel}_{$ds}_{$timestamp}.csv";
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $csvName . '"');
                header('Content-Length: ' . strlen($csv));
                header('Cache-Control: no-store, no-cache');
                echo $csv;
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }

        // ── Multiple datasets → ZIP ─────────────────────────────────────────
        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'ZIP export is unavailable on this server (php-zip extension not installed).']);
            return;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'rums_bk_');
        $zip     = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create ZIP archive.']);
            return;
        }

        foreach ($validSets as $ds) {
            try {
                $rows = ($generators[$ds])();
                $zip->addFromString("{$ds}.csv", $makeCsv($rows));
            } catch (Throwable $e) {
                $zip->addFromString("{$ds}_error.txt", 'Error: ' . $e->getMessage());
            }
        }

        $readme = implode("\r\n", [
            'RUMS Backup Export',
            'Generated : ' . date('Y-m-d H:i:s'),
            'Property  : ' . str_replace('_', ' ', $propertyLabel),
            'Period    : ' . ($allTime ? 'All time' : (($from ?? 'N/A') . ' to ' . ($to ?? 'N/A'))),
            'Datasets  : ' . implode(', ', $validSets),
            '',
            'Contact   : properties@vertexiot.co.ke | Hotline: 0140673232',
            'CONFIDENTIAL — for authorized use only.',
        ]);
        $zip->addFromString('README.txt', $readme);
        $zip->close();

        $zipName = "RUMS_{$propertyLabel}_{$timestamp}.zip";
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($tmpFile));
        header('Cache-Control: no-store, no-cache');
        header('Pragma: no-cache');
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    });
}
