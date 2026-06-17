<?php
/**
 * Message Templates endpoints
 *
 * GET    /api/v1/message-templates              list (filter: category, channel)
 * POST   /api/v1/message-templates              create (admin/manager)
 * GET    /api/v1/message-templates/{id}         find
 * PUT    /api/v1/message-templates/{id}         update (admin/manager)
 * DELETE /api/v1/message-templates/{id}         delete (admin/manager)
 */
function registerMessageTemplateRoutes(Router $router, PDO $db): void
{
    $svc = fn() => new NotificationService($db);

    // ── List ─────────────────────────────────────────────────
    $router->get('message-templates', function () use ($svc) {
        $filters = [];
        if (!empty($_GET['category'])) $filters['category'] = $_GET['category'];
        if (!empty($_GET['channel']))  $filters['channel']  = $_GET['channel'];

        $rows = $svc()->listTemplates($filters);
        ApiResponse::ok(['data' => $rows, 'total' => count($rows)]);
    });

    // ── Create ───────────────────────────────────────────────
    $router->post('message-templates', function () use ($db, $svc) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $body   = Router::body();
        $user   = ApiAuth::user();
        $result = $svc()->createTemplate($body, $user['id']);

        if (!$result['success']) {
            ApiResponse::unprocessable('Missing fields: ' . implode(', ', $result['errors'] ?? []));
            return;
        }
        ApiResponse::created(['id' => $result['id']], 'Template created.');
    });

    // ── Find ─────────────────────────────────────────────────
    $router->get('message-templates/{id}', function (string $id) use ($svc) {
        $row = $svc()->findTemplate((int)$id);
        $row ? ApiResponse::ok($row) : ApiResponse::notFound('Template not found.');
    });

    // ── Update ───────────────────────────────────────────────
    $router->put('message-templates/{id}', function (string $id) use ($db, $svc) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $result = $svc()->updateTemplate((int)$id, Router::body());

        if (!$result['success']) {
            ApiResponse::badRequest($result['error'] ?? 'Update failed.');
            return;
        }
        ApiResponse::ok(null, 'Template updated.');
    });

    // ── Delete ───────────────────────────────────────────────
    $router->delete('message-templates/{id}', function (string $id) use ($db, $svc) {
        ApiAuth::requireRole($db, 'admin');
        $ok = $svc()->deleteTemplate((int)$id);
        $ok ? ApiResponse::ok(null, 'Template deleted.') : ApiResponse::notFound('Template not found.');
    });
}
