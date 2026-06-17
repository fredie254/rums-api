<?php
/**
 * Audit Logs endpoints
 *
 * GET  /api/v1/audit-logs        list with filters (admin & auditor only)
 * GET  /api/v1/audit-logs/meta   distinct actions, modules, users for filter UI
 * GET  /api/v1/audit-logs/stats  KPI aggregates: counts today, login trend, module activity
 */
function registerAuditLogRoutes(Router $router, PDO $db): void
{
    // ── Meta ──────────────────────────────────────────────────
    $router->get('audit-logs/meta', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'auditor');

        $actions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
        $modules = $db->query("SELECT DISTINCT module FROM audit_logs ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
        $users   = $db->query("SELECT id, name FROM users WHERE status = 'active' ORDER BY name")->fetchAll();

        ApiResponse::ok(['actions' => $actions, 'modules' => $modules, 'users' => $users]);
    });

    // ── Stats ──────────────────────────────────────────────────
    $router->get('audit-logs/stats', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'auditor');

        $kpi = $db->query("SELECT
            (SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()) AS logs_today,
            (SELECT COUNT(*) FROM audit_logs WHERE action = 'LOGIN' AND DATE(created_at) = CURDATE()) AS logins_today
        ")->fetch();

        $loginTrend = $db->query("
            SELECT DATE(created_at) AS day, COUNT(*) AS cnt
            FROM audit_logs
            WHERE action = 'LOGIN' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY day ORDER BY day
        ")->fetchAll();

        $moduleActivity = $db->query("
            SELECT module, COUNT(*) AS cnt
            FROM audit_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY module ORDER BY cnt DESC LIMIT 10
        ")->fetchAll();

        ApiResponse::ok([
            'logs_today'      => (int)($kpi['logs_today']   ?? 0),
            'logins_today'    => (int)($kpi['logins_today']  ?? 0),
            'login_trend'     => $loginTrend,
            'module_activity' => $moduleActivity,
        ]);
    });

    // ── List ──────────────────────────────────────────────────
    $router->get('audit-logs', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'auditor');

        $page    = Router::page();
        $perPage = Router::perPage(50);

        $dateFrom = Router::strParam('date_from', date('Y-m-01'));
        $dateTo   = Router::strParam('date_to',   date('Y-m-d'));
        $action   = Router::strParam('action');
        $module   = Router::strParam('module');
        $userId   = Router::intParam('user_id');
        $ip       = Router::strParam('ip');

        $where  = ['al.created_at BETWEEN ? AND ?'];
        $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

        if ($action) { $where[] = 'al.action = ?';     $params[] = $action; }
        if ($module) { $where[] = 'al.module = ?';     $params[] = $module; }
        if ($userId) { $where[] = 'al.user_id = ?';    $params[] = $userId; }
        if ($ip)     { $where[] = 'al.ip_address = ?'; $params[] = $ip; }

        $w = 'WHERE ' . implode(' AND ', $where);

        $cStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs al $w");
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt   = $db->prepare(
            "SELECT al.*, u.email AS user_email
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.user_id
             $w
             ORDER BY al.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $perPage, $offset]);
        $logs = $stmt->fetchAll();

        ApiResponse::paginated([
            'data' => $logs,
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'total_pages'  => $total > 0 ? (int)ceil($total / $perPage) : 1,
            ],
        ]);
    });
}
