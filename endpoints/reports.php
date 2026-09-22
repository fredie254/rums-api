<?php
/**
 * Reports endpoints  (read-only — requires read:reports scope)
 *
 * GET /api/v1/reports/financial          income vs expenses breakdown
 * GET /api/v1/reports/occupancy          unit occupancy by property and type
 * GET /api/v1/reports/maintenance        maintenance analysis by category
 * GET /api/v1/reports/rent-collection    monthly rent collection details
 * GET /api/v1/reports/dashboard          aggregated KPI dashboard
 * GET /api/v1/reports/ledger             per-tenant debit/credit ledger
 * GET /api/v1/reports/aging              AR aging buckets (current/30/60/90+)
 * GET /api/v1/reports/deposits           deposit held vs expected per lease
 * GET /api/v1/reports/arrears            arrears trend + worst offenders
 * GET /api/v1/reports/tenant-analytics   new/lost tenants, tenure, expiring leases
 * GET /api/v1/reports/unit_performance   per-unit income, collection rate & maintenance
 * GET /api/v1/reports/export             CSV download (report= & format=csv)
 */
function registerReportRoutes(Router $router, PDO $db): void
{
    $svc = new ReportService($db);

    $router->get('reports/financial', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:reports');
        $from   = Router::strParam('date_from', date('Y-01-01'));
        $to     = Router::strParam('date_to',   date('Y-m-d'));
        $propId = Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->financial($from, $to, $propId));
    });

    $router->get('reports/occupancy', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:reports');
        $propId = Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->occupancy($propId));
    });

    $router->get('reports/maintenance', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:reports');
        $from   = Router::strParam('date_from', date('Y-01-01'));
        $to     = Router::strParam('date_to',   date('Y-m-d'));
        $propId = Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->maintenance($from, $to, $propId));
    });

    $router->get('reports/rent-collection', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:reports');
        $year   = Router::intParam('year',  (int)date('Y'));
        $month  = Router::intParam('month', (int)date('n'));
        $propId = Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->rentCollection($year, $month, $propId));
    });

    $router->get('reports/ledger', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:reports');
        $tenantId = Router::intParam('tenant_id');
        if (!$tenantId) ApiResponse::badRequest('tenant_id is required.');
        $from = Router::strParam('date_from', date('Y-01-01'));
        $to   = Router::strParam('date_to',   date('Y-m-d'));
        ApiResponse::ok($svc->ledger($tenantId, $from, $to));
    });

    $router->get('reports/aging', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:reports');
        $propId = Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->aging($propId));
    });

    $router->get('reports/deposits', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:reports');
        $propId = Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->deposits($propId));
    });

    // ── Arrears ───────────────────────────────────────────────
    $router->get('reports/arrears', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:reports');
        $months = Router::intParam('months') ?: 12;
        $propId = Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->arrears($months, $propId));
    });

    // ── Unit performance (both _ and - forms accepted) ────────
    $unitPerformance = function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:reports');
        $from   = Router::strParam('date_from', date('Y-01-01'));
        $to     = Router::strParam('date_to',   date('Y-m-d'));
        $propId = Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->unitPerformance($from, $to, $propId));
    };
    $router->get('reports/unit_performance', $unitPerformance);
    $router->get('reports/unit-performance', $unitPerformance);

    // ── Tenant analytics ──────────────────────────────────────
    $router->get('reports/tenant-analytics', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:reports');
        $propId = Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->tenantAnalytics($propId));
    });

    // ── CSV Export ────────────────────────────────────────────
    $router->get('reports/export', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:reports');

        $report = $_GET['report'] ?? '';
        $validReports = ['financial','occupancy','rent_collection','arrears','tenant_analytics','maintenance','aging','deposits','unit_performance'];
        if (!in_array($report, $validReports, true)) {
            ApiResponse::badRequest('Invalid report type. Valid: ' . implode(', ', $validReports));
            return;
        }

        $params = $_GET;
        unset($params['report'], $params['format']);

        $data = $svc->exportCsv($report, $params);
        if (empty($data['headers'])) {
            ApiResponse::badRequest('No data to export.');
            return;
        }

        $filename = $report . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $out = fopen('php://output', 'w');
        // BOM for Excel UTF-8
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, $data['headers']);
        foreach ($data['rows'] as $row) {
            fputcsv($out, is_array($row) ? array_values($row) : [$row]);
        }
        fclose($out);
        exit;
    });

    $router->get('reports/dashboard', function () use ($db) {
        ApiAuth::requireScope($db, 'read:reports');

        // Scope all KPIs to the landlord's properties when the caller is a landlord/owner
        $landlordId = ApiAuth::landlordId($db);
        $lpf  = $landlordId ? "JOIN properties pr ON pr.id = u.property_id AND pr.landlord_id = $landlordId" : '';
        $lpfP = $landlordId ? "JOIN properties pr ON pr.id = p.id AND pr.landlord_id = $landlordId" : '';
        $lpfL = $landlordId ? "JOIN units ul ON ul.id = l.unit_id JOIN properties pr ON pr.id = ul.property_id AND pr.landlord_id = $landlordId" : '';
        $lpfM = $landlordId ? "JOIN units ul ON ul.id = mr.unit_id JOIN properties pr ON pr.id = ul.property_id AND pr.landlord_id = $landlordId" : '';
        $lpfI = $landlordId ? "JOIN leases li2 ON li2.id = i.lease_id JOIN units ui2 ON ui2.id = li2.unit_id JOIN properties pr ON pr.id = ui2.property_id AND pr.landlord_id = $landlordId" : '';
        $lpfPay = $landlordId ? "JOIN leases lp2 ON lp2.id = p.lease_id JOIN units up2 ON up2.id = lp2.unit_id JOIN properties pr ON pr.id = up2.property_id AND pr.landlord_id = $landlordId" : '';

        $units = $db->query(
            "SELECT
                COUNT(*)                          AS total,
                SUM(u.status = 'occupied')        AS occupied,
                SUM(u.status = 'available')       AS available,
                SUM(u.status = 'maintenance')     AS maintenance
             FROM units u $lpf"
        )->fetch();

        $revenue = $db->query(
            "SELECT
                COALESCE(SUM(CASE
                    WHEN MONTH(p.payment_date) = MONTH(NOW()) AND YEAR(p.payment_date) = YEAR(NOW())
                    THEN p.amount END), 0) AS current_month,
                COALESCE(SUM(CASE
                    WHEN YEAR(p.payment_date) = YEAR(NOW())
                    THEN p.amount END), 0) AS current_year
             FROM payments p $lpfPay WHERE p.status = 'completed'"
        )->fetch();

        $ar = $db->query(
            "SELECT COUNT(*) AS count,
                COALESCE(SUM(i.total_amount - i.amount_paid), 0) AS balance
             FROM invoices i $lpfI WHERE i.status IN ('unpaid','partial','overdue')"
        )->fetch();

        $maint = $db->query(
            "SELECT
                COALESCE(SUM(mr.status IN ('open','in_progress')), 0) AS open,
                COALESCE(SUM(mr.priority = 'urgent' AND mr.status IN ('open','in_progress')), 0) AS urgent,
                COALESCE(COUNT(*), 0) AS total
             FROM maintenance_requests mr $lpfM"
        )->fetch();

        $leases = $db->query(
            "SELECT
                SUM(l.status = 'active') AS active,
                SUM(l.status = 'active'
                    AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                ) AS expiring_30d
             FROM leases l $lpfL"
        )->fetch();

        $propCount = $landlordId
            ? (int)$db->query("SELECT COUNT(*) FROM properties WHERE landlord_id = $landlordId")->fetchColumn()
            : (int)$db->query("SELECT COUNT(*) FROM properties")->fetchColumn();

        $tenantCount = $landlordId
            ? (int)$db->query(
                "SELECT COUNT(DISTINCT t.id) FROM tenants t
                 JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
                 JOIN units u  ON u.id = l.unit_id
                 JOIN properties pr ON pr.id = u.property_id AND pr.landlord_id = $landlordId"
              )->fetchColumn()
            : (int)$db->query("SELECT COUNT(*) FROM tenants")->fetchColumn();

        ApiResponse::ok([
            'units'               => $units,
            'revenue'             => $revenue,
            'accounts_receivable' => $ar,
            'maintenance'         => $maint,
            'leases'              => $leases,
            'occupancy_rate'      => $units['total'] > 0
                ? round($units['occupied'] / $units['total'] * 100, 1)
                : 0,
            'properties_count'    => $propCount,
            'tenants_count'       => $tenantCount,
        ]);
    });

    // ── Per-unit reconciled balances ─────────────────────────────────────────
    // Returns every unit with its invoiced/paid/outstanding totals.
    // Landlords are automatically scoped to their own properties.
    // Super admin and managers can optionally filter by property_id.
    $router->get('reports/unit-balances', function () use ($db) {
        ApiAuth::requireScope($db, 'read:reports');

        $landlordId = ApiAuth::landlordId($db);
        $propId     = Router::intParam('property_id') ?: null;

        $where  = [];
        $params = [];

        if ($landlordId) {
            $where[]  = 'pr.landlord_id = ?';
            $params[] = $landlordId;
        }
        if ($propId) {
            $where[]  = 'pr.id = ?';
            $params[] = $propId;
        }

        $w   = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT
                    u.id            AS unit_id,
                    u.unit_number,
                    u.rent_amount,
                    u.status        AS unit_status,
                    pr.id           AS property_id,
                    pr.name         AS property_name,
                    l.id            AS lease_id,
                    l.start_date,
                    l.end_date,
                    l.status        AS lease_status,
                    CONCAT(t.first_name, ' ', t.last_name) AS tenant_name,
                    t.phone         AS tenant_phone,
                    t.id            AS tenant_id,
                    COALESCE(SUM(i.total_amount), 0)                                                        AS total_invoiced,
                    COALESCE(SUM(i.amount_paid), 0)                                                         AS total_paid,
                    COALESCE(SUM(i.total_amount - i.amount_paid), 0)                                        AS outstanding_balance,
                    COALESCE(SUM(i.status IN ('unpaid','overdue','partial')), 0)                            AS open_invoices,
                    COALESCE(SUM(i.status = 'overdue'), 0)                                                  AS overdue_invoices
                FROM units u
                JOIN properties pr ON pr.id = u.property_id
                LEFT JOIN leases l  ON l.unit_id  = u.id AND l.status = 'active'
                LEFT JOIN tenants t ON t.id = l.tenant_id
                LEFT JOIN invoices i ON i.lease_id = l.id
                $w
                GROUP BY u.id, u.unit_number, u.rent_amount, u.status,
                         pr.id, pr.name, l.id, l.start_date, l.end_date, l.status,
                         t.first_name, t.last_name, t.phone, t.id
                ORDER BY pr.name, u.unit_number";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Cast numeric strings
        foreach ($rows as &$r) {
            $r['rent_amount']        = (float)$r['rent_amount'];
            $r['total_invoiced']     = (float)$r['total_invoiced'];
            $r['total_paid']         = (float)$r['total_paid'];
            $r['outstanding_balance']= (float)$r['outstanding_balance'];
            $r['open_invoices']      = (int)$r['open_invoices'];
            $r['overdue_invoices']   = (int)$r['overdue_invoices'];
        }
        unset($r);

        ApiResponse::ok($rows);
    });

    // Single-query 6-month revenue chart — replaces 6 separate reports/financial calls
    $router->get('reports/revenue-chart', function () use ($db) {
        ApiAuth::requireScope($db, 'read:reports');
        $months     = max(1, min(24, Router::intParam('months') ?: 6));
        $landlordId = ApiAuth::landlordId($db);
        $lpfJoin    = $landlordId
            ? "JOIN leases lrc ON lrc.id = p.lease_id JOIN units urc ON urc.id = lrc.unit_id JOIN properties prc ON prc.id = urc.property_id AND prc.landlord_id = $landlordId"
            : '';

        $stmt = $db->prepare(
            "SELECT
                DATE_FORMAT(p.payment_date, '%Y-%m') AS ym,
                DATE_FORMAT(p.payment_date, '%b %y')  AS label,
                COALESCE(SUM(p.amount), 0)            AS revenue
             FROM payments p $lpfJoin
             WHERE p.status = 'completed'
               AND p.payment_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL ? MONTH), '%Y-%m-01')
             GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
             ORDER BY ym ASC"
        );
        $stmt->execute([$months]);
        $rows = $stmt->fetchAll();

        // Fill in missing months with 0 so the chart always shows a full range
        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $d   = date('Y-m', strtotime("-$i months"));
            $lbl = date('M y',  strtotime("-$i months"));
            $found = array_filter($rows, fn($r) => $r['ym'] === $d);
            $result[] = [
                'ym'      => $d,
                'label'   => $lbl,
                'revenue' => $found ? (float)array_values($found)[0]['revenue'] : 0.0,
            ];
        }

        ApiResponse::ok($result);
    });
}
