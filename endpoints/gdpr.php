<?php
/**
 * GDPR endpoints
 *
 * Own-user (any authenticated):
 * POST   /api/v1/gdpr/consent                record consent
 * GET    /api/v1/gdpr/consents               own consent history
 * POST   /api/v1/gdpr/export/request         request data export (returns token)
 * GET    /api/v1/gdpr/export/download        download export JSON (uses one-time token)
 * POST   /api/v1/gdpr/deletion/request       submit a deletion request
 * GET    /api/v1/gdpr/deletion/status        check own deletion request status
 *
 * Admin only:
 * GET    /api/v1/gdpr/deletion/requests      list all deletion requests
 * POST   /api/v1/gdpr/deletion/{id}/process  approve or reject a deletion request
 */
function registerGdprRoutes(Router $router, PDO $db): void
{
    // ── Record consent ────────────────────────────────────────
    $router->post('gdpr/consent', function () use ($db) {
        ApiAuth::require($db);
        $userId = ApiAuth::userId();
        $body   = Router::body();
        $type   = trim($body['consent_type'] ?? '');
        $given  = (bool)($body['consented'] ?? true);
        $ver    = trim($body['version'] ?? '1.0');

        $allowed = ['terms', 'privacy', 'marketing'];
        if (!in_array($type, $allowed, true)) {
            ApiResponse::badRequest('consent_type must be: ' . implode(', ', $allowed));
        }

        $svc = new GdprService($db);
        $svc->recordConsent(
            $userId, $type, $given, $ver,
            $_SERVER['REMOTE_ADDR']     ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        ApiResponse::ok(null, 'Consent recorded.');
    });

    // ── Get own consent history ───────────────────────────────
    $router->get('gdpr/consents', function () use ($db) {
        ApiAuth::require($db);
        $svc  = new GdprService($db);
        $data = $svc->getConsentHistory(ApiAuth::userId());
        ApiResponse::ok(['data' => $data]);
    });

    // ── Request data export ───────────────────────────────────
    $router->post('gdpr/export/request', function () use ($db) {
        ApiAuth::require($db);
        $svc    = new GdprService($db);
        $result = $svc->createExportRequest(ApiAuth::userId());
        ApiResponse::ok($result, 'Export ready. Use the token with /gdpr/export/download within 1 hour.');
    });

    // ── Download export (one-time token in query string) ──────
    $router->get('gdpr/export/download', function () use ($db) {
        ApiAuth::require($db);
        $token = $_GET['token'] ?? '';

        if (!$token) ApiResponse::badRequest('token parameter is required.');

        $svc    = new GdprService($db);
        $userId = $svc->resolveExportToken($token);

        if (!$userId) ApiResponse::unauthorized('Invalid or expired export token.');

        // Only allow downloading own data (or admin)
        $requestingUser = ApiAuth::userId();
        $role           = ApiAuth::userRole();
        if ($userId !== $requestingUser && $role !== 'admin') {
            ApiResponse::forbidden('You can only download your own data.');
        }

        $data = $svc->exportUserData($userId);

        // Stream as JSON download
        $filename = 'rums_data_export_' . $userId . '_' . date('Ymd_His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    });

    // ── Submit deletion request ───────────────────────────────
    $router->post('gdpr/deletion/request', function () use ($db) {
        ApiAuth::require($db);
        $userId = ApiAuth::userId();
        $body   = Router::body();
        $reason = trim($body['reason'] ?? '') ?: null;

        $svc    = new GdprService($db);
        $result = $svc->createDeletionRequest($userId, $reason);

        $result['success']
            ? ApiResponse::created($result, 'Deletion request submitted. An administrator will review it.')
            : ApiResponse::conflict($result['error'] ?? 'Could not create request.');
    });

    // ── Own deletion request status ───────────────────────────
    $router->get('gdpr/deletion/status', function () use ($db) {
        ApiAuth::require($db);
        $userId = ApiAuth::userId();

        $stmt = $db->prepare(
            "SELECT id, status, reason, requested_at, processed_at, admin_notes
             FROM data_deletion_requests
             WHERE user_id = ?
             ORDER BY requested_at DESC
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        ApiResponse::ok($row ?: null, $row ? '' : 'No deletion request on file.');
    });

    // ── Admin: list all deletion requests ─────────────────────
    $router->get('gdpr/deletion/requests', function () use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $status = $_GET['status'] ?? 'all';
        $svc    = new GdprService($db);
        ApiResponse::paginated($svc->listDeletionRequests($status, Router::page(), Router::perPage()));
    });

    // ── Admin: process a deletion request ─────────────────────
    $router->post('gdpr/deletion/{id}/process', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $adminId = ApiAuth::userId();
        $body    = Router::body();
        $action  = trim($body['action'] ?? '');  // 'approve' | 'reject'
        $notes   = trim($body['notes']  ?? '') ?: null;

        if (!in_array($action, ['approve', 'reject'], true)) {
            ApiResponse::badRequest('action must be approve or reject.');
        }

        $svc    = new GdprService($db);
        $result = $svc->processDeletionRequest((int)$id, $action, $adminId, $notes);

        $result['success']
            ? ApiResponse::ok(null, $action === 'approve' ? 'User data anonymized.' : 'Request rejected.')
            : ApiResponse::badRequest($result['error'] ?? 'Processing failed.');
    });
}
