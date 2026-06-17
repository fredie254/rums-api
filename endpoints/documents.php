<?php
/**
 * Document Management endpoints
 *
 * GET    /api/v1/documents                     list (filterable, paginated)
 * GET    /api/v1/documents/stats               repository summary counts
 * POST   /api/v1/documents/upload              upload new document (multipart/form-data)
 * GET    /api/v1/documents/{uuid}              document metadata
 * PATCH  /api/v1/documents/{uuid}              update title/description/category/access_level
 * DELETE /api/v1/documents/{uuid}              soft delete
 * GET    /api/v1/documents/{uuid}/download     stream file (auth required)
 * GET    /api/v1/documents/{uuid}/versions     version chain
 * POST   /api/v1/documents/{uuid}/version      upload a new version (multipart/form-data)
 * GET    /api/v1/documents/{uuid}/access-log   access audit trail
 */
function registerDocumentRoutes(Router $router, PDO $db): void
{
    // Shared: resolve tenant ID for tenant-role isolation
    $resolveTenant = function (string $role, int $userId) use ($db): ?int {
        if ($role !== 'tenant') return null;
        $stmt = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    };

    // ── Stats ─────────────────────────────────────────────────
    $router->get('documents/stats', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager', 'accountant', 'auditor');
        $svc = new DocumentService($db);
        ApiResponse::ok($svc->stats());
    });

    // ── List ──────────────────────────────────────────────────
    $router->get('documents', function () use ($db, $resolveTenant) {
        ApiAuth::require($db);
        $user = ApiAuth::user();

        $filters = [];
        foreach (['entity_type', 'entity_id', 'document_type', 'category', 'access_level', 'search'] as $k) {
            if (!empty($_GET[$k])) $filters[$k] = $_GET[$k];
        }

        // Tenant isolation
        if ($user['role'] === 'tenant') {
            $tenantId = $resolveTenant($user['role'], $user['id']);
            if ($tenantId) $filters['tenant_id'] = $tenantId;
        }

        $svc    = new DocumentService($db);
        $result = $svc->list($filters, Router::page(), Router::perPage());
        ApiResponse::paginated($result);
    });

    // ── Upload ────────────────────────────────────────────────
    $router->post('documents/upload', function () use ($db) {
        ApiAuth::require($db);
        $user = ApiAuth::user();

        // Tenants can only upload to their own entity
        if ($user['role'] === 'tenant') {
            $stmt = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            $t = $stmt->fetch();
            if (!$t) { ApiResponse::forbidden('Tenant record not found.'); return; }
            // Force entity to their own tenant record
            $_POST['entity_type'] = 'tenant';
            $_POST['entity_id']   = $t['id'];
            $_POST['access_level'] = 'private'; // tenant uploads default private
        }

        if (empty($_FILES['file'])) {
            ApiResponse::badRequest('No file uploaded. Use field name: file');
            return;
        }

        $meta = [
            'title'         => trim($_POST['title']         ?? ''),
            'description'   => trim($_POST['description']   ?? '') ?: null,
            'document_type' => trim($_POST['document_type'] ?? 'other'),
            'category'      => trim($_POST['category']      ?? '') ?: null,
            'entity_type'   => trim($_POST['entity_type']   ?? 'general'),
            'entity_id'     => !empty($_POST['entity_id']) ? (int)$_POST['entity_id'] : null,
            'access_level'  => trim($_POST['access_level']  ?? 'internal'),
        ];

        if (empty($meta['title'])) {
            // Auto-title from filename if not provided
            $meta['title'] = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
        }

        $svc    = new DocumentService($db);
        $result = $svc->upload($_FILES['file'], $meta, $user['id']);

        $result['success']
            ? ApiResponse::created($result, 'Document uploaded successfully.')
            : ApiResponse::unprocessable($result['error'] ?? 'Upload failed.');
    });

    // ── Metadata ──────────────────────────────────────────────
    $router->get('documents/{uuid}', function (string $uuid) use ($db, $resolveTenant) {
        ApiAuth::require($db);
        $user = ApiAuth::user();

        $svc = new DocumentService($db);
        $doc = $svc->find($uuid);

        if (!$doc) { ApiResponse::notFound('Document not found.'); return; }

        $tenantId = $resolveTenant($user['role'], $user['id']);
        if (!$svc->canAccess($doc, $user['role'], $tenantId)) {
            ApiResponse::forbidden('Access denied.');
            return;
        }

        // Log view
        $db->prepare("INSERT INTO document_access_logs (document_id, user_id, action, ip_address) VALUES (?,?,?,?)")
           ->execute([$doc['id'], $user['id'], 'view', $_SERVER['REMOTE_ADDR'] ?? null]);

        ApiResponse::ok($doc);
    });

    // ── Update metadata ────────────────────────────────────────
    $router->patch('documents/{uuid}', function (string $uuid) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $user   = ApiAuth::user();
        $svc    = new DocumentService($db);
        $result = $svc->update($uuid, Router::body(), $user['id']);

        $result['success']
            ? ApiResponse::ok(null, 'Document updated.')
            : ApiResponse::badRequest($result['error'] ?? 'Update failed.');
    });

    // ── Delete ────────────────────────────────────────────────
    $router->delete('documents/{uuid}', function (string $uuid) use ($db) {
        ApiAuth::require($db);
        $user   = ApiAuth::user();
        $svc    = new DocumentService($db);
        $result = $svc->delete($uuid, $user['id'], $user['role']);

        $result['success']
            ? ApiResponse::ok(null, 'Document deleted.')
            : ApiResponse::forbidden($result['error'] ?? 'Delete failed.');
    });

    // ── Download (stream file) ────────────────────────────────
    $router->get('documents/{uuid}/download', function (string $uuid) use ($db, $resolveTenant) {
        ApiAuth::require($db);
        $user = ApiAuth::user();

        $svc      = new DocumentService($db);
        $tenantId = $resolveTenant($user['role'], $user['id']);
        $result   = $svc->stream($uuid, $user['id'], $user['role'], $tenantId);

        // stream() calls exit on success; only reaches here on error
        $code = $result['code'] ?? 400;
        if ($code === 404) ApiResponse::notFound($result['error']);
        elseif ($code === 403) ApiResponse::forbidden($result['error']);
        else ApiResponse::badRequest($result['error'] ?? 'Download failed.');
    });

    // ── Version history ───────────────────────────────────────
    $router->get('documents/{uuid}/versions', function (string $uuid) use ($db, $resolveTenant) {
        ApiAuth::require($db);
        $user     = ApiAuth::user();
        $svc      = new DocumentService($db);
        $doc      = $svc->find($uuid);

        if (!$doc) { ApiResponse::notFound('Document not found.'); return; }

        $tenantId = $resolveTenant($user['role'], $user['id']);
        if (!$svc->canAccess($doc, $user['role'], $tenantId)) {
            ApiResponse::forbidden('Access denied.');
            return;
        }

        ApiResponse::ok(['data' => $svc->versions($uuid)]);
    });

    // ── Upload new version ────────────────────────────────────
    $router->post('documents/{uuid}/version', function (string $uuid) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager', 'accountant');
        $user = ApiAuth::user();

        if (empty($_FILES['file'])) {
            ApiResponse::badRequest('No file uploaded. Use field name: file');
            return;
        }

        $svc    = new DocumentService($db);
        $parent = $svc->find($uuid);
        if (!$parent) { ApiResponse::notFound('Parent document not found.'); return; }

        $meta = [
            'title'         => trim($_POST['title']       ?? '') ?: $parent['title'],
            'description'   => trim($_POST['description'] ?? '') ?: $parent['description'],
            'document_type' => $parent['document_type'],
            'category'      => $parent['category'],
            'entity_type'   => $parent['entity_type'],
            'entity_id'     => $parent['entity_id'],
            'access_level'  => $parent['access_level'],
        ];

        $result = $svc->upload($_FILES['file'], $meta, $user['id'], (int)$parent['id']);

        $result['success']
            ? ApiResponse::created($result, "Version {$result['version']} uploaded.")
            : ApiResponse::unprocessable($result['error'] ?? 'Version upload failed.');
    });

    // ── Access log ────────────────────────────────────────────
    $router->get('documents/{uuid}/access-log', function (string $uuid) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $svc  = new DocumentService($db);
        $logs = $svc->accessLogs($uuid);
        ApiResponse::ok(['data' => $logs, 'total' => count($logs)]);
    });
}
