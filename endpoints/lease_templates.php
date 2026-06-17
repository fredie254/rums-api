<?php
/**
 * Lease Templates endpoints
 *
 * GET    /api/v1/lease-templates           list active templates
 * POST   /api/v1/lease-templates           create  (admin/manager)
 * GET    /api/v1/lease-templates/{id}      view single (includes body)
 * PUT    /api/v1/lease-templates/{id}      update  (admin/manager)
 * DELETE /api/v1/lease-templates/{id}      soft-delete (admin)
 *
 * Template body placeholders substituted by the UI before saving:
 *   {{TENANT_NAME}}, {{UNIT_NUMBER}}, {{PROPERTY_NAME}},
 *   {{START_DATE}}, {{END_DATE}}, {{MONTHLY_RENT}},
 *   {{DEPOSIT_AMOUNT}}, {{PAYMENT_DAY}}, {{NOTICE_PERIOD_DAYS}},
 *   {{GRACE_PERIOD_DAYS}}, {{PENALTY_RATE}}, {{LEASE_TYPE}},
 *   {{LANDLORD_NAME}}, {{LEASE_NUMBER}}, {{TODAY}}
 */

function registerLeaseTemplateRoutes(Router $router, PDO $db): void
{
    // ── List ─────────────────────────────────────────────────
    $router->get('lease-templates', function () use ($db) {
        ApiAuth::requireScope($db, 'read:leases');
        $rows = $db->query(
            "SELECT id, name, lease_type, is_default, is_active, created_at, updated_at
             FROM lease_templates
             WHERE is_active = 1
             ORDER BY is_default DESC, name"
        )->fetchAll();
        ApiResponse::ok($rows);
    });

    // ── Create ────────────────────────────────────────────────
    $router->post('lease-templates', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $body = Router::body();
        foreach (['name', 'body'] as $field) {
            if (empty($body[$field])) ApiResponse::unprocessable("Field '$field' is required.");
        }
        $user      = ApiAuth::user();
        $leaseType = $body['lease_type'] ?? 'fixed-term';
        $isDefault = !empty($body['is_default']) ? 1 : 0;

        // Only one default per lease_type
        if ($isDefault) {
            $db->prepare("UPDATE lease_templates SET is_default = 0 WHERE lease_type = ?")
               ->execute([$leaseType]);
        }

        $stmt = $db->prepare(
            "INSERT INTO lease_templates (name, lease_type, body, is_default, created_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            trim($body['name']),
            $leaseType,
            $body['body'],
            $isDefault,
            $user['id'],
        ]);
        ApiResponse::created(['id' => (int)$db->lastInsertId()], 'Template created.');
    });

    // ── View ──────────────────────────────────────────────────
    $router->get('lease-templates/{id}', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:leases');
        $stmt = $db->prepare("SELECT * FROM lease_templates WHERE id = ? AND is_active = 1");
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch();
        $row ? ApiResponse::ok($row) : ApiResponse::notFound('Template not found.');
    });

    // ── Update ────────────────────────────────────────────────
    $router->put('lease-templates/{id}', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $body = Router::body();

        $check = $db->prepare("SELECT id, lease_type FROM lease_templates WHERE id = ? AND is_active = 1");
        $check->execute([(int)$id]);
        $tpl = $check->fetch();
        if (!$tpl) ApiResponse::notFound('Template not found.');

        if (!empty($body['is_default'])) {
            $type = $body['lease_type'] ?? $tpl['lease_type'];
            $db->prepare("UPDATE lease_templates SET is_default = 0 WHERE lease_type = ? AND id != ?")
               ->execute([$type, (int)$id]);
        }

        $allowed = array_intersect_key($body, array_flip(['name', 'lease_type', 'body', 'is_default', 'is_active']));
        if (!$allowed) ApiResponse::badRequest('No valid fields to update.');

        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($allowed)));
        $db->prepare("UPDATE lease_templates SET $set WHERE id = ?")
           ->execute([...array_values($allowed), (int)$id]);
        ApiResponse::ok(null, 'Template updated.');
    });

    // ── Delete (soft) ─────────────────────────────────────────
    $router->delete('lease-templates/{id}', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $db->prepare("UPDATE lease_templates SET is_active = 0, is_default = 0 WHERE id = ?")
           ->execute([(int)$id]);
        ApiResponse::ok(null, 'Template deleted.');
    });
}
