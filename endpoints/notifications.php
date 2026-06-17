<?php
/**
 * Notifications endpoints
 *
 * GET   /api/v1/notifications                    list for current user (paginated)
 * GET   /api/v1/notifications/unread-count        unread count for current user
 * POST  /api/v1/notifications                    create in-app (admin/manager only)
 * PATCH /api/v1/notifications/{id}/read           mark one as read
 * POST  /api/v1/notifications/read-all            mark all as read for current user
 *
 * Communication send shortcuts (admin/manager):
 * POST  /api/v1/notifications/send-sms            send a single SMS
 * POST  /api/v1/notifications/send-email          send a single email
 * POST  /api/v1/notifications/payment-reminders   run payment reminder batch
 * POST  /api/v1/notifications/lease-reminders     run lease expiry reminder batch
 */
function registerNotificationRoutes(Router $router, PDO $db): void
{
    // ── Unread count (before list so the pattern is matched first) ──
    $router->get('notifications/unread-count', function () use ($db) {
        $user = ApiAuth::user();
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user['id']]);
        ApiResponse::ok(['count' => (int)$stmt->fetchColumn()]);
    });

    // ── List ─────────────────────────────────────────────────
    $router->get('notifications', function () use ($db) {
        $user    = ApiAuth::user();
        $page    = Router::page();
        $perPage = Router::perPage();

        $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
        $countStmt->execute([$user['id']]);
        $total = (int)$countStmt->fetchColumn();

        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        $offset     = ($page - 1) * $perPage;

        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$user['id'], $perPage, $offset]);
        $rows = $stmt->fetchAll();

        ApiResponse::paginated([
            'data' => $rows,
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'total_pages'  => $totalPages,
            ],
        ]);
    });

    // ── Create (push) ─────────────────────────────────────────
    $router->post('notifications', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');

        $body = Router::body();
        if (empty($body['user_id']) || empty($body['title']) || empty($body['message'])) {
            ApiResponse::unprocessable('user_id, title and message are required.');
            return;
        }

        $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?,?,?,?,?)")
           ->execute([
               (int)$body['user_id'],
               $body['title'],
               $body['message'],
               $body['type'] ?? 'info',
               $body['link'] ?? '',
           ]);

        ApiResponse::created(['id' => (int)$db->lastInsertId()], 'Notification sent.');
    });

    // ── Mark one as read ──────────────────────────────────────
    $router->patch('notifications/{id}/read', function (string $id) use ($db) {
        $user = ApiAuth::user();
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
           ->execute([(int)$id, $user['id']]);
        ApiResponse::ok(null, 'Marked as read.');
    });

    // ── Mark all as read ──────────────────────────────────────
    $router->post('notifications/read-all', function () use ($db) {
        $user = ApiAuth::user();
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")
           ->execute([$user['id']]);
        ApiResponse::ok(null, 'All notifications marked as read.');
    });

    // ── Send single SMS ───────────────────────────────────────
    $router->post('notifications/send-sms', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $body = Router::body();
        if (empty($body['phone']) || empty($body['message'])) {
            ApiResponse::unprocessable('phone and message are required.');
            return;
        }
        $user   = ApiAuth::user();
        $svc    = new NotificationService($db);
        $result = $svc->sendSms(
            $body['phone'],
            $body['message'],
            !empty($body['tenant_id'])   ? (int)$body['tenant_id']   : null,
            !empty($body['template_id']) ? (int)$body['template_id'] : null,
            null,
            $user['id']
        );
        $result['success']
            ? ApiResponse::ok($result, 'SMS sent.')
            : ApiResponse::serverError($result['error'] ?? 'SMS failed.');
    });

    // ── Send single email ─────────────────────────────────────
    $router->post('notifications/send-email', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $body = Router::body();
        if (empty($body['email']) || empty($body['subject']) || empty($body['html_body'])) {
            ApiResponse::unprocessable('email, subject and html_body are required.');
            return;
        }
        $user   = ApiAuth::user();
        $svc    = new NotificationService($db);
        $result = $svc->sendEmail(
            $body['email'],
            $body['subject'],
            $body['html_body'],
            !empty($body['tenant_id'])   ? (int)$body['tenant_id']   : null,
            !empty($body['template_id']) ? (int)$body['template_id'] : null,
            null,
            $user['id'],
            $body['text_body'] ?? null
        );
        $result['success']
            ? ApiResponse::ok($result, 'Email sent.')
            : ApiResponse::serverError($result['error'] ?? 'Email failed.');
    });

    // ── Payment reminders batch ───────────────────────────────
    $router->post('notifications/payment-reminders', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $body    = Router::body();
        $dueDays = isset($body['due_days']) ? max(1, (int)$body['due_days']) : 3;
        $user    = ApiAuth::user();
        $svc     = new NotificationService($db);
        $result  = $svc->sendPaymentReminders($dueDays, $user['id']);
        ApiResponse::ok($result, "Payment reminders sent: {$result['sent']} succeeded, {$result['failed']} failed.");
    });

    // ── Lease expiry reminders batch ──────────────────────────
    $router->post('notifications/lease-reminders', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $body       = Router::body();
        $expiryDays = isset($body['expiry_days']) ? max(1, (int)$body['expiry_days']) : 30;
        $user       = ApiAuth::user();
        $svc        = new NotificationService($db);
        $result     = $svc->sendLeaseReminders($expiryDays, $user['id']);
        ApiResponse::ok($result, "Lease reminders sent: {$result['sent']} succeeded, {$result['failed']} failed.");
    });
}
