<?php
/**
 * Broadcast Messages endpoints
 *
 * GET   /api/v1/broadcasts           paginated list
 * POST  /api/v1/broadcasts           create broadcast (draft)
 * GET   /api/v1/broadcasts/{id}      find broadcast
 * POST  /api/v1/broadcasts/{id}/send execute broadcast
 * PATCH /api/v1/broadcasts/{id}/cancel cancel draft broadcast
 */
function registerBroadcastRoutes(Router $router, PDO $db): void
{
    $svc = fn() => new NotificationService($db);

    // ── List ─────────────────────────────────────────────────
    $router->get('broadcasts', function () use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $result = $svc()->getBroadcasts(Router::page(), Router::perPage());
        ApiResponse::paginated($result);
    });

    // ── Create ───────────────────────────────────────────────
    $router->post('broadcasts', function () use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $user   = ApiAuth::user();
        $result = $svc()->createBroadcast(Router::body(), $user['id']);

        if (!$result['success']) {
            ApiResponse::unprocessable('Missing fields: ' . implode(', ', $result['errors'] ?? []));
            return;
        }
        ApiResponse::created(['id' => $result['id']], 'Broadcast created as draft.');
    });

    // ── Find ─────────────────────────────────────────────────
    $router->get('broadcasts/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $row = $svc()->findBroadcast((int)$id);
        $row ? ApiResponse::ok($row) : ApiResponse::notFound('Broadcast not found.');
    });

    // ── Send ─────────────────────────────────────────────────
    $router->post('broadcasts/{id}/send', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $user   = ApiAuth::user();
        $result = $svc()->sendBroadcast((int)$id, $user['id']);

        if (!$result['success']) {
            ApiResponse::badRequest($result['error'] ?? 'Could not send broadcast.');
            return;
        }
        ApiResponse::ok([
            'total'  => $result['total'],
            'sent'   => $result['sent'],
            'failed' => $result['failed'],
        ], "Broadcast sent to {$result['sent']} of {$result['total']} recipients.");
    });

    // ── Cancel ───────────────────────────────────────────────
    $router->patch('broadcasts/{id}/cancel', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $ok = $svc()->cancelBroadcast((int)$id);
        $ok
            ? ApiResponse::ok(null, 'Broadcast cancelled.')
            : ApiResponse::badRequest('Broadcast cannot be cancelled (not in draft state).');
    });
}
