<?php
/**
 * Message Templates endpoints
 *
 * Super admin / manager — manage global defaults (landlord_id IS NULL):
 *   GET    /api/v1/message-templates              list global defaults
 *   POST   /api/v1/message-templates              create global template
 *   GET    /api/v1/message-templates/{id}         fetch one
 *   PUT    /api/v1/message-templates/{id}         update
 *   DELETE /api/v1/message-templates/{id}         delete global template
 *
 * Landlord — customize per-property notification messages:
 *   GET    /api/v1/message-templates/effective     merged view (custom > global default)
 *   POST   /api/v1/message-templates/my            save custom override for a category+channel
 *   DELETE /api/v1/message-templates/my/{cat}/{ch} reset a category+channel to global default
 */
function registerMessageTemplateRoutes(Router $router, PDO $db): void
{
    $svc = fn() => new NotificationService($db);

    // ── Landlord: effective merged view ──────────────────────────────────────
    // Returns each global default + the landlord's custom version if they have one.
    // Available placeholders are appended to each row so the UI can show them.
    $router->get('message-templates/effective', function () use ($db, $svc) {
        ApiAuth::require($db);
        $landlordId = ApiAuth::landlordId($db);
        if (!$landlordId) ApiResponse::forbidden('Only landlords can access effective templates.');

        try {
            $rows = $svc()->listEffectiveTemplates($landlordId);
        } catch (Throwable) {
            ApiResponse::serverError('Message templates not yet migrated. Please run migrations 015 and 018 on the server.');
            return;
        }

        // Append placeholder reference to each row
        $vars = templatePlaceholders();
        foreach ($rows as &$r) {
            $r['placeholders'] = $vars[$r['category']] ?? $vars['general'];
        }
        unset($r);

        ApiResponse::ok($rows);
    });

    // ── Landlord: save / update custom override ───────────────────────────────
    $router->post('message-templates/my', function () use ($db, $svc) {
        ApiAuth::require($db);
        $landlordId = ApiAuth::landlordId($db);
        if (!$landlordId) ApiResponse::forbidden('Only landlords can save custom templates.');

        $body = Router::body();
        try {
            $result = $svc()->upsertLandlordTemplate($landlordId, ApiAuth::userId(), $body);
        } catch (Throwable) {
            ApiResponse::serverError('Message templates not yet migrated. Please run migration 015 on the server.');
            return;
        }

        if (!$result['success']) {
            ApiResponse::unprocessable('Missing fields: ' . implode(', ', $result['errors'] ?? []));
            return;
        }
        ApiResponse::ok(['id' => $result['id']], 'Custom template saved.');
    });

    // ── Landlord: reset category+channel to global default ────────────────────
    $router->delete('message-templates/my/{category}/{channel}', function (string $category, string $channel) use ($db, $svc) {
        ApiAuth::require($db);
        $landlordId = ApiAuth::landlordId($db);
        if (!$landlordId) ApiResponse::forbidden('Only landlords can reset their templates.');

        $svc()->resetLandlordTemplate($landlordId, $category, $channel);
        ApiResponse::ok([], 'Reset to default.');
    });

    // ── Global: List (admin/manager see global defaults; landlords see their own) ─
    $router->get('message-templates', function () use ($db, $svc) {
        $filters = [];
        if (!empty($_GET['category'])) $filters['category'] = $_GET['category'];
        if (!empty($_GET['channel']))  $filters['channel']  = $_GET['channel'];

        $rows = $svc()->listTemplates($filters);
        ApiResponse::ok(['data' => $rows, 'total' => count($rows)]);
    });

    // ── Global: Create (admin/manager) ───────────────────────────────────────
    $router->post('message-templates', function () use ($db, $svc) {
        ApiAuth::requireRole($db, 'admin', 'manager', 'super_admin', 'property_manager');
        $body   = Router::body();
        $user   = ApiAuth::user();
        // Force landlord_id to null so admins can't accidentally create scoped templates here
        unset($body['landlord_id']);
        $result = $svc()->createTemplate($body, $user['id']);

        if (!$result['success']) {
            ApiResponse::unprocessable('Missing fields: ' . implode(', ', $result['errors'] ?? []));
            return;
        }
        ApiResponse::created(['id' => $result['id']], 'Template created.');
    });

    // ── Find one ──────────────────────────────────────────────────────────────
    $router->get('message-templates/{id}', function (string $id) use ($svc) {
        $row = $svc()->findTemplate((int)$id);
        $row ? ApiResponse::ok($row) : ApiResponse::notFound('Template not found.');
    });

    // ── Update ────────────────────────────────────────────────────────────────
    $router->put('message-templates/{id}', function (string $id) use ($db, $svc) {
        ApiAuth::requireRole($db, 'admin', 'manager', 'super_admin', 'property_manager');
        $result = $svc()->updateTemplate((int)$id, Router::body());

        if (!$result['success']) {
            ApiResponse::badRequest($result['error'] ?? 'Update failed.');
            return;
        }
        ApiResponse::ok(null, 'Template updated.');
    });

    // ── Delete ────────────────────────────────────────────────────────────────
    $router->delete('message-templates/{id}', function (string $id) use ($db, $svc) {
        ApiAuth::requireRole($db, 'admin', 'super_admin');
        $ok = $svc()->deleteTemplate((int)$id);
        $ok ? ApiResponse::ok(null, 'Template deleted.') : ApiResponse::notFound('Template not found.');
    });

    // ── Notification Preferences (landlord) ──────────────────────────────────
    // GET  /notification-prefs   — fetch current landlord's preferences
    // PUT  /notification-prefs   — save preferences (upsert)

    $router->get('notification-prefs', function () use ($db) {
        ApiAuth::require($db);
        $landlordId = ApiAuth::landlordId($db);
        if (!$landlordId) ApiResponse::forbidden('Only landlords can access notification preferences.');

        try {
            $stmt = $db->prepare("SELECT * FROM notification_preferences WHERE landlord_id = ?");
            $stmt->execute([$landlordId]);
            $prefs = $stmt->fetch();
        } catch (Throwable) {
            $prefs = false; // table not yet migrated — fall through to defaults
        }

        // Return defaults if no row exists yet
        if (!$prefs) {
            $prefs = [
                'landlord_id'       => $landlordId,
                'email_enabled'     => 1,
                'sms_enabled'       => 1,
                'inapp_enabled'     => 1,
                'notify_payment'    => 1,
                'notify_maintenance'  => 1,
                'notify_lease_expiry' => 1,
                'notify_overdue'    => 1,
            ];
        }
        ApiResponse::ok($prefs);
    });

    $router->put('notification-prefs', function () use ($db) {
        ApiAuth::require($db);
        $landlordId = ApiAuth::landlordId($db);
        if (!$landlordId) ApiResponse::forbidden('Only landlords can update notification preferences.');

        $b = Router::body();
        $bool = fn($key, $default = 1) => isset($b[$key]) ? ($b[$key] ? 1 : 0) : $default;

        try {
            $db->prepare(
                "INSERT INTO notification_preferences
                    (landlord_id, email_enabled, sms_enabled, inapp_enabled,
                     notify_payment, notify_maintenance, notify_lease_expiry, notify_overdue)
                 VALUES (?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    email_enabled     = VALUES(email_enabled),
                    sms_enabled       = VALUES(sms_enabled),
                    inapp_enabled     = VALUES(inapp_enabled),
                    notify_payment    = VALUES(notify_payment),
                    notify_maintenance  = VALUES(notify_maintenance),
                    notify_lease_expiry = VALUES(notify_lease_expiry),
                    notify_overdue    = VALUES(notify_overdue)"
            )->execute([
                $landlordId,
                $bool('email_enabled'), $bool('sms_enabled'), $bool('inapp_enabled'),
                $bool('notify_payment'), $bool('notify_maintenance'),
                $bool('notify_lease_expiry'), $bool('notify_overdue'),
            ]);
        } catch (Throwable) {
            ApiResponse::serverError('Notification preferences table not yet migrated. Please run migration 018.');
            return;
        }

        ApiResponse::ok(null, 'Notification preferences saved.');
    });
}

/**
 * Available {{PLACEHOLDER}} tokens per category.
 * Shown in the UI so landlords know what variables they can use.
 */
function templatePlaceholders(): array
{
    $common = ['{{TENANT_NAME}}', '{{UNIT_NUMBER}}', '{{PROPERTY_NAME}}', '{{COMPANY_NAME}}'];
    return [
        'payment'     => array_merge($common, ['{{AMOUNT_DUE}}', '{{AMOUNT_PAID}}', '{{PAYMENT_DATE}}', '{{PAYMENT_REF}}', '{{INVOICE_NUMBER}}', '{{DUE_DATE}}', '{{BALANCE}}']),
        'lease'       => array_merge($common, ['{{LEASE_NUMBER}}', '{{START_DATE}}', '{{END_DATE}}', '{{DAYS_REMAINING}}', '{{MONTHLY_RENT}}']),
        'maintenance' => array_merge($common, ['{{STATUS}}', '{{PRIORITY}}', '{{DESCRIPTION}}']),
        'general'     => array_merge($common, ['{{MESSAGE}}']),
        'broadcast'   => $common,
    ];
}
