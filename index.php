<?php
/**
 * RUMS Standalone REST API — Front Controller
 *
 * All requests are routed here via .htaccess.
 *
 * URL format:   /api/v1/<resource>
 * Auth:         Authorization: Bearer <token>
 * Response:     JSON  { success, data?, meta?, message?, errors?, _ms }
 * Versioning:   URI path  /api/v1/...
 *
 * Architecture: Multi-tier SOA
 *   Request  → Router (this file)
 *   Handler  → endpoints/*.php  (thin controllers)
 *   Logic    → services/*.php   (business rules)
 *   Data     → PDO via getDB()  (persistence)
 */

declare(strict_types=1);

// ── Bootstrap (env, constants, DB) ────────────────────────────
require_once __DIR__ . '/config/bootstrap.php';

// ── Core API classes ──────────────────────────────────────────
require_once __DIR__ . '/src/Encryptor.php';
require_once __DIR__ . '/src/ApiResponse.php';

// ── Global error handler — ensures all crashes return JSON, never empty ──
set_exception_handler(function (Throwable $e) {
    error_log('[API Uncaught] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'message' => env('APP_DEBUG', false)
            ? get_class($e) . ': ' . $e->getMessage()
            : 'An unexpected error occurred.',
    ]);
    exit;
});
require_once __DIR__ . '/src/ApiAuth.php';
require_once __DIR__ . '/src/Router.php';

// ── Core helpers ──────────────────────────────────────────────
require_once __DIR__ . '/src/TOTP.php';

// ── Service layer ─────────────────────────────────────────────
require_once __DIR__ . '/services/BaseService.php';
require_once __DIR__ . '/services/PropertyService.php';
require_once __DIR__ . '/services/TenantService.php';
require_once __DIR__ . '/services/LeaseService.php';
require_once __DIR__ . '/services/PaymentService.php';
require_once __DIR__ . '/services/MaintenanceService.php';
require_once __DIR__ . '/services/ReportService.php';
require_once __DIR__ . '/services/ReconciliationService.php';
require_once __DIR__ . '/services/SmsService.php';
require_once __DIR__ . '/services/MailService.php';
require_once __DIR__ . '/services/NotificationService.php';
require_once __DIR__ . '/services/DocumentService.php';
require_once __DIR__ . '/services/GdprService.php';

// ── Initialise CORS + response headers ────────────────────────
ApiResponse::init();

$db     = getDB();
$router = new Router($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

// ── Root ──────────────────────────────────────────────────────
$router->get('', function () {
    ApiResponse::ok([
        'name'    => APP_NAME,
        'version' => 'v' . APP_VERSION,
        'docs'    => rtrim(env('APP_URL', ''), '/') . '/docs.html',
        'health'  => rtrim(env('APP_URL', ''), '/') . '/api/v1/health',
    ], 'RUMS API is running.');
});

// ────────────────────────────────────────────────────────────
// PUBLIC — no auth required
// ────────────────────────────────────────────────────────────

$router->get('health', function () use ($db) {
    $dbOk = false;
    try { $dbOk = (bool)$db->query('SELECT 1')->fetchColumn(); } catch (Throwable) {}

    $status = $dbOk ? 'ok' : 'degraded';
    http_response_code($dbOk ? 200 : 503);

    ApiResponse::ok([
        'status'    => $status,
        'version'   => APP_VERSION,
        'app'       => APP_NAME,
        'env'       => APP_ENV,
        'timestamp' => date('c'),
        'services'  => ['database' => $dbOk ? 'up' : 'down'],
    ], $status === 'ok' ? 'System healthy.' : 'Degraded mode — database unreachable.');
});

// ── Auth (login is public; others require a token internally) ─
require_once __DIR__ . '/endpoints/auth.php';
registerAuthRoutes($router, $db);

// ── M-Pesa callback (public — Safaricom calls this without auth) ──
require_once __DIR__ . '/endpoints/mpesa.php';
registerMpesaPublicRoutes($router, $db);

// ────────────────────────────────────────────────────────────
// PROTECTED — all routes below require a valid Bearer token
// ────────────────────────────────────────────────────────────
$router->guard(fn() => ApiAuth::require($db));
$router->guard(fn() => ApiAuth::rateLimit($db));

// ── Properties ────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/properties.php';
registerPropertyRoutes($router, $db);

// ── Units ─────────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/units.php';
registerUnitRoutes($router, $db);

// ── Tenants ───────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/tenants.php';
registerTenantRoutes($router, $db);

// ── Lease Templates ───────────────────────────────────────────
require_once __DIR__ . '/endpoints/lease_templates.php';
registerLeaseTemplateRoutes($router, $db);

// ── Leases ────────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/leases.php';
registerLeaseRoutes($router, $db);

// ── Payments ──────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/payments.php';
registerPaymentRoutes($router, $db);

// ── Invoices ──────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/invoices.php';
registerInvoiceRoutes($router, $db);

// ── Maintenance ───────────────────────────────────────────────
require_once __DIR__ . '/endpoints/maintenance.php';
registerMaintenanceRoutes($router, $db);

// ── Reports ───────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/reports.php';
registerReportRoutes($router, $db);

// ── Users & Tokens  (admin) ───────────────────────────────────
require_once __DIR__ . '/endpoints/users.php';
registerUserRoutes($router, $db);

// ── Landlords ─────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/landlords.php';
registerLandlordRoutes($router, $db);

// ── Settings ──────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/settings.php';
registerSettingRoutes($router, $db);

// ── Expenses ──────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/expenses.php';
registerExpenseRoutes($router, $db);

// ── Security: Visitors ────────────────────────────────────────
require_once __DIR__ . '/endpoints/visitors.php';
registerVisitorRoutes($router, $db);

// ── Security: Incidents ───────────────────────────────────────
require_once __DIR__ . '/endpoints/security_incidents.php';
registerSecurityIncidentRoutes($router, $db);

// ── Security: Occupancy Logs ──────────────────────────────────
require_once __DIR__ . '/endpoints/occupancy_logs.php';
registerOccupancyLogRoutes($router, $db);

// ── Notifications (in-app + send shortcuts) ───────────────────
require_once __DIR__ . '/endpoints/notifications.php';
registerNotificationRoutes($router, $db);

// ── Message Templates ─────────────────────────────────────────
require_once __DIR__ . '/endpoints/message_templates.php';
registerMessageTemplateRoutes($router, $db);

// ── Communication Logs ────────────────────────────────────────
require_once __DIR__ . '/endpoints/communication_logs.php';
registerCommunicationLogRoutes($router, $db);

// ── Broadcasts ────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/broadcasts.php';
registerBroadcastRoutes($router, $db);

// ── Report Schedules ──────────────────────────────────────────
require_once __DIR__ . '/endpoints/report_schedules.php';
registerReportScheduleRoutes($router, $db);

// ── Audit Logs ────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/audit_logs.php';
registerAuditLogRoutes($router, $db);

// ── M-Pesa Transactions ───────────────────────────────────────
require_once __DIR__ . '/endpoints/mpesa_transactions.php';
registerMpesaTransactionRoutes($router, $db);

// ── M-Pesa STK Push (protected) ───────────────────────────────
registerMpesaProtectedRoutes($router, $db);

// ── Bank Reconciliation ───────────────────────────────────────
require_once __DIR__ . '/endpoints/reconciliation.php';
registerReconciliationRoutes($router, $db);

// ── Document Management ───────────────────────────────────────
require_once __DIR__ . '/endpoints/documents.php';
registerDocumentRoutes($router, $db);

// ── MFA ───────────────────────────────────────────────────────
require_once __DIR__ . '/endpoints/mfa.php';
registerMfaRoutes($router, $db);

// ── GDPR & Privacy ────────────────────────────────────────────
require_once __DIR__ . '/endpoints/gdpr.php';
registerGdprRoutes($router, $db);

// ── Dispatch ──────────────────────────────────────────────────
$router->dispatch();
