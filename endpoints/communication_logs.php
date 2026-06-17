<?php
/**
 * Communication Logs endpoints
 *
 * GET  /api/v1/communication-logs                  paginated list (admin/manager/accountant)
 * GET  /api/v1/communication-logs/stats            channel/status counts for a date range
 */
function registerCommunicationLogRoutes(Router $router, PDO $db): void
{
    $svc = fn() => new NotificationService($db);

    // ── Stats ────────────────────────────────────────────────
    $router->get('communication-logs/stats', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager', 'accountant');

        $from = $_GET['date_from'] ?? date('Y-m-01');
        $to   = $_GET['date_to']   ?? date('Y-m-d');

        $stmt = $db->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(channel = 'sms')    AS sms_count,
                SUM(channel = 'email')  AS email_count,
                SUM(channel = 'in_app') AS in_app_count,
                SUM(status = 'sent')      AS sent_count,
                SUM(status = 'delivered') AS delivered_count,
                SUM(status = 'failed')    AS failed_count,
                SUM(status = 'queued')    AS queued_count
             FROM communication_logs
             WHERE created_at BETWEEN ? AND ?"
        );
        $stmt->execute([$from, $to . ' 23:59:59']);
        $stats = $stmt->fetch() ?: [];

        ApiResponse::ok($stats);
    });

    // ── List ─────────────────────────────────────────────────
    $router->get('communication-logs', function () use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager', 'accountant');

        $filters = [];
        foreach (['tenant_id', 'channel', 'status', 'date_from', 'date_to'] as $k) {
            if (!empty($_GET[$k])) $filters[$k] = $_GET[$k];
        }

        $result = $svc()->getLogs($filters, Router::page(), Router::perPage());
        ApiResponse::paginated($result);
    });
}
