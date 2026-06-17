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
        $from   = Router::strParam('date_from', date('Y-m-01'));
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
        $validReports = ['financial','occupancy','rent_collection','arrears','tenant_analytics','maintenance','aging','deposits'];
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

        $units = $db->query(
            "SELECT
                COUNT(*)                          AS total,
                SUM(status = 'occupied')          AS occupied,
                SUM(status = 'available')         AS available,
                SUM(status = 'maintenance')       AS maintenance
             FROM units"
        )->fetch();

        $revenue = $db->query(
            "SELECT
                COALESCE(SUM(CASE
                    WHEN MONTH(payment_date) = MONTH(NOW()) AND YEAR(payment_date) = YEAR(NOW())
                    THEN amount END), 0) AS current_month,
                COALESCE(SUM(CASE
                    WHEN YEAR(payment_date) = YEAR(NOW())
                    THEN amount END), 0) AS current_year
             FROM payments WHERE status = 'completed'"
        )->fetch();

        $ar = $db->query(
            "SELECT COUNT(*) AS count,
                COALESCE(SUM(total_amount - amount_paid), 0) AS balance
             FROM invoices WHERE status IN ('unpaid','partial','overdue')"
        )->fetch();

        $maint = $db->query(
            "SELECT
                SUM(status NOT IN ('completed','resolved','cancelled')) AS open,
                SUM(priority = 'urgent' AND status NOT IN ('completed','resolved')) AS urgent
             FROM maintenance_requests"
        )->fetch();

        $leases = $db->query(
            "SELECT
                SUM(status = 'active') AS active,
                SUM(status = 'active'
                    AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                ) AS expiring_30d
             FROM leases"
        )->fetch();

        ApiResponse::ok([
            'units'               => $units,
            'revenue'             => $revenue,
            'accounts_receivable' => $ar,
            'maintenance'         => $maint,
            'leases'              => $leases,
            'occupancy_rate'      => $units['total'] > 0
                ? round($units['occupied'] / $units['total'] * 100, 1)
                : 0,
        ]);
    });
}
