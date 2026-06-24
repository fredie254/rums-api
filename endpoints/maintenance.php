<?php
/**
 * Maintenance endpoints
 *
 * GET    /api/v1/maintenance                    list work orders (filterable, role-scoped)
 * GET    /api/v1/maintenance/summary             stats summary
 * POST   /api/v1/maintenance                    create work order
 * GET    /api/v1/maintenance/{id}               single work order
 * PUT    /api/v1/maintenance/{id}               full update   (admin/manager)
 * PATCH  /api/v1/maintenance/{id}               partial update (role-restricted)
 * DELETE /api/v1/maintenance/{id}               delete         (admin/manager)
 * POST   /api/v1/maintenance/{id}/assign        assign to staff (admin/manager) + email notification
 * POST   /api/v1/maintenance/{id}/start         mark in_progress
 * POST   /api/v1/maintenance/{id}/complete      maintenance staff: mark completed
 * POST   /api/v1/maintenance/{id}/approve       tenant: approve completed → resolved
 * POST   /api/v1/maintenance/{id}/reopen        tenant: reopen completed/resolved → open
 * GET    /api/v1/maintenance/{id}/logs          activity log
 */
function registerMaintenanceRoutes(Router $router, PDO $db): void
{
    $svc = new MaintenanceService($db);

    // ── Helper: build email HTML for task assignment ────────────
    $assignmentEmail = static function (array $wo, string $assigneeName, string $appUrl): string {
        $priority     = strtolower($wo['priority'] ?? 'medium');
        $priorityColors = [
            'urgent' => '#dc2626', 'high' => '#d97706',
            'medium' => '#2563eb', 'low'  => '#16a34a',
        ];
        $badgeColor = $priorityColors[$priority] ?? '#6b7280';
        $viewUrl    = rtrim($appUrl, '/') . '/maintenance/view?id=' . $wo['id'];

        return '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
  body{margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif}
  .wrap{max-width:580px;margin:32px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1)}
  .hdr{background:#1e3a5f;padding:24px 28px}
  .hdr h1{margin:0;color:#fff;font-size:20px}
  .hdr p{margin:4px 0 0;color:#93c5fd;font-size:13px}
  .body{padding:28px}
  .badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;color:#fff;background:' . $badgeColor . '}
  table.details{width:100%;border-collapse:collapse;margin:16px 0}
  table.details td{padding:8px 10px;font-size:13px;border-bottom:1px solid #f0f0f0}
  table.details td:first-child{font-weight:600;color:#374151;width:35%;background:#f9fafb}
  .btn{display:inline-block;padding:12px 28px;background:#1e3a5f;color:#fff;text-decoration:none;border-radius:7px;font-weight:600;font-size:14px;margin-top:20px}
  .footer{padding:16px 28px;background:#f9fafb;font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb}
</style></head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>&#128295; New Maintenance Task Assigned</h1>
    <p>RUMS — Rental Unit Management System</p>
  </div>
  <div class="body">
    <p style="font-size:15px">Hi <strong>' . htmlspecialchars($assigneeName) . '</strong>,</p>
    <p style="color:#4b5563;font-size:14px">A maintenance work order has been assigned to you. Please review the details and begin work promptly.</p>
    <div style="margin:12px 0"><span class="badge">' . strtoupper($priority) . ' PRIORITY</span></div>
    <table class="details">
      <tr><td>Work Order</td><td><strong>' . htmlspecialchars($wo['request_number'] ?? '') . '</strong></td></tr>
      <tr><td>Issue</td><td>' . htmlspecialchars($wo['issue_title'] ?? '') . '</td></tr>
      <tr><td>Property / Unit</td><td>' . htmlspecialchars(($wo['property_name'] ?? '') . ' / ' . ($wo['unit_number'] ?? '')) . '</td></tr>
      <tr><td>Category</td><td>' . ucfirst(str_replace('_', ' ', $wo['category'] ?? '—')) . '</td></tr>
      <tr><td>Reported by</td><td>' . htmlspecialchars($wo['tenant_name'] ?? 'Not assigned') . '</td></tr>
      <tr><td>Date Reported</td><td>' . date('d M Y, H:i', strtotime($wo['created_at'] ?? 'now')) . '</td></tr>
    </table>
    ' . (!empty($wo['description']) ? '<p style="background:#f9fafb;padding:12px;border-left:3px solid #1e3a5f;border-radius:0 6px 6px 0;font-size:13px;color:#374151">' . nl2br(htmlspecialchars(mb_substr($wo['description'], 0, 300))) . '</p>' : '') . '
    <a href="' . htmlspecialchars($viewUrl) . '" class="btn">View Work Order &#8594;</a>
  </div>
  <div class="footer">
    This is an automated notification from RUMS. Do not reply to this email.<br>
    &copy; ' . date('Y') . ' RUMS — Rental Unit Management System
  </div>
</div>
</body></html>';
    };

    // ── Helper: send in-app notification (non-fatal) ────────────
    $inApp = static function (PDO $db, int $userId, string $title, string $message, string $link): void {
        try {
            $db->prepare(
                "INSERT INTO notifications (user_id, title, message, type, link)
                 VALUES (?, ?, ?, 'maintenance', ?)"
            )->execute([$userId, $title, $message, $link]);
        } catch (Throwable) {}
    };

    // ── Static routes first ──────────────────────────────────────
    $router->get('maintenance/summary', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:maintenance');
        $pid = Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->summary($pid));
    });

    $router->get('maintenance', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:maintenance');
        $user    = ApiAuth::user();
        $filters = [
            'status'      => Router::strParam('status'),
            'priority'    => Router::strParam('priority'),
            'property_id' => Router::intParam('property_id'),
            'unit_id'     => Router::intParam('unit_id'),
            'assigned_to' => Router::intParam('assigned_to'),
        ];

        // Maintenance staff: only their assigned tasks
        if ($user['role'] === 'maintenance') {
            $filters['assigned_to'] = $user['id'];
        }
        // Tenants: only their own requests
        if ($user['role'] === 'tenant') {
            $row = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $row->execute([$user['id']]);
            $t = $row->fetch();
            $filters['tenant_id'] = $t ? (int)$t['id'] : 0;
        }

        try {
            $result = $svc->list($filters, Router::page(), Router::perPage());
        } catch (Throwable $e) {
            error_log('[Maintenance] list() failed: ' . $e->getMessage());
            ApiResponse::serverError('Failed to load maintenance requests.');
        }
        ApiResponse::paginated($result);
    });

    $router->post('maintenance', function () use ($svc, $db, $inApp, $assignmentEmail) {
        ApiAuth::requireScope($db, 'write:maintenance');
        $body = Router::body();
        $user = ApiAuth::user();

        // Tenants can only submit for their own active unit
        if ($user['role'] === 'tenant') {
            $row = $db->prepare(
                "SELECT l.unit_id FROM leases l
                 JOIN tenants t ON t.id = l.tenant_id
                 WHERE t.user_id = ? AND l.status = 'active' LIMIT 1"
            );
            $row->execute([$user['id']]);
            $r = $row->fetch();
            if (!$r) ApiResponse::forbidden('No active lease found.');
            $body['unit_id'] = $r['unit_id'];
        }

        $res = $svc->create($body);
        if (!$res['success']) {
            ApiResponse::unprocessable($res['message'], $res['errors'] ?? []);
        }

        // If created with an assignment, notify the assignee
        if (!empty($body['assigned_to'])) {
            $assigneeRow = $db->prepare("SELECT id, name, email FROM users WHERE id = ?");
            $assigneeRow->execute([(int)$body['assigned_to']]);
            $assignee = $assigneeRow->fetch();
            if ($assignee) {
                $wo = $svc->find($res['id']);
                $inApp($db, $assignee['id'],
                    "Task Assigned: {$res['request_number']}",
                    "Work order {$res['request_number']} has been assigned to you.",
                    "/maintenance/view?id={$res['id']}"
                );
                if (!empty($assignee['email'])) {
                    try {
                        $notif = new NotificationService($db);
                        $notif->sendEmail(
                            $assignee['email'],
                            "Maintenance Task Assigned: {$res['request_number']}",
                            $assignmentEmail($wo ?? [], $assignee['name'], env('APP_URL', ''))
                        );
                    } catch (Throwable $e) {
                        error_log('[MaintenanceCreate] Email notification failed: ' . $e->getMessage());
                    }
                }
            }
        }

        ApiResponse::created(
            ['id' => $res['id'], 'request_number' => $res['request_number']],
            $res['message']
        );
    });

    $router->get('maintenance/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:maintenance');
        $user = ApiAuth::user();
        $wo   = $svc->find((int)$id);
        if (!$wo) ApiResponse::notFound('Work order not found.');

        // Maintenance staff: only their own assigned tasks
        if ($user['role'] === 'maintenance' && (int)($wo['assigned_to'] ?? 0) !== $user['id']) {
            ApiResponse::forbidden('You can only view tasks assigned to you.');
        }
        // Tenants: only their own requests
        if ($user['role'] === 'tenant') {
            $row = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $row->execute([$user['id']]);
            $t = $row->fetch();
            if (!$t || (int)$t['id'] !== (int)($wo['tenant_id'] ?? 0)) {
                ApiResponse::forbidden('You can only view your own requests.');
            }
        }

        ApiResponse::ok($wo);
    });

    $router->put('maintenance/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $res = $svc->update((int)$id, Router::body());
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    $router->patch('maintenance/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:maintenance');
        $user = ApiAuth::user();
        $body = Router::body();

        // Tenants cannot PATCH directly — use /approve or /reopen
        if ($user['role'] === 'tenant') {
            ApiResponse::forbidden('Use the approve or reopen endpoints to update your request.');
        }

        // Maintenance staff: restrict to their own tasks and limited fields only
        if ($user['role'] === 'maintenance') {
            $check = $db->prepare("SELECT assigned_to FROM maintenance_requests WHERE id = ?");
            $check->execute([(int)$id]);
            $row = $check->fetch();
            if (!$row || (int)$row['assigned_to'] !== $user['id']) {
                ApiResponse::forbidden('You can only update tasks assigned to you.');
            }
            // Maintenance can only update notes, work_started; status limited to in_progress/completed
            $body = array_intersect_key($body, array_flip(['notes', 'status', 'work_started']));
            if (!empty($body['status']) && !in_array($body['status'], ['in_progress', 'completed'], true)) {
                ApiResponse::unprocessable('Maintenance staff can only set status to in_progress or completed.');
            }
        }

        $res = $svc->update((int)$id, $body);
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    $router->delete('maintenance/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $wo = $svc->find((int)$id);
        if (!$wo) ApiResponse::notFound('Work order not found.');

        if ($wo['status'] === 'in_progress') {
            ApiResponse::unprocessable('Cannot delete a work order that is currently in progress. Change status first.');
        }

        try {
            $db->prepare("DELETE FROM maintenance_requests WHERE id = ?")->execute([(int)$id]);
        } catch (Throwable $e) {
            ApiResponse::serverError('Failed to delete work order.', $e);
        }

        ApiResponse::ok(null, 'Work order deleted.');
    });

    // ── Assign ───────────────────────────────────────────────────
    $router->post('maintenance/{id}/assign', function (string $id) use ($svc, $db, $inApp, $assignmentEmail) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $body       = Router::body();
        $assignedTo = (int)($body['assigned_to'] ?? 0);
        if (!$assignedTo) ApiResponse::badRequest('assigned_to is required.');

        // Capture old assignee for log
        $old = $db->prepare(
            "SELECT au.name AS old_name FROM maintenance_requests mr
             LEFT JOIN users au ON au.id = mr.assigned_to WHERE mr.id = ?"
        );
        $old->execute([(int)$id]);
        $oldName = $old->fetchColumn() ?: 'Unassigned';

        $db->prepare(
            "UPDATE maintenance_requests
             SET assigned_to = ?,
                 status = IF(status = 'open', 'in_progress', status)
             WHERE id = ?"
        )->execute([$assignedTo, (int)$id]);

        // Get new assignee details
        $newRow = $db->prepare("SELECT id, name, email FROM users WHERE id = ?");
        $newRow->execute([$assignedTo]);
        $assignee = $newRow->fetch();
        $assignedName = $assignee['name'] ?? 'staff';

        $svc->logActivity((int)$id, 'assigned', $oldName, $assignedName, "Assigned to {$assignedName}");

        // ── Notifications ────────────────────────────────────────
        $wo = $svc->find((int)$id);
        if ($assignee && $wo) {
            // In-app
            $inApp($db, $assignee['id'],
                "New Task: {$wo['request_number']}",
                "Work order {$wo['request_number']}: {$wo['issue_title']} at {$wo['property_name']} / {$wo['unit_number']} has been assigned to you.",
                "/maintenance/view?id={$wo['id']}"
            );
            // Email
            if (!empty($assignee['email'])) {
                try {
                    $notif = new NotificationService($db);
                    $notif->sendEmail(
                        $assignee['email'],
                        "[RUMS] Maintenance Task Assigned: {$wo['request_number']} — " . ucfirst($wo['priority']) . ' Priority',
                        $assignmentEmail($wo, $assignee['name'], env('APP_URL', ''))
                    );
                } catch (Throwable $e) {
                    error_log('[MaintenanceAssign] Email notification failed: ' . $e->getMessage());
                }
            }
        }

        ApiResponse::ok(null, 'Work order assigned.');
    });

    // ── Start ────────────────────────────────────────────────────
    $router->post('maintenance/{id}/start', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:maintenance');
        $user = ApiAuth::user();
        // Maintenance staff: must be assigned to the task
        if ($user['role'] === 'maintenance') {
            $check = $db->prepare("SELECT assigned_to FROM maintenance_requests WHERE id = ?");
            $check->execute([(int)$id]);
            $row = $check->fetch();
            if (!$row || (int)$row['assigned_to'] !== $user['id']) {
                ApiResponse::forbidden('You can only start tasks assigned to you.');
            }
        }
        $db->prepare(
            "UPDATE maintenance_requests
             SET status = 'in_progress', work_started = COALESCE(work_started, NOW())
             WHERE id = ?"
        )->execute([(int)$id]);
        $svc->logActivity((int)$id, 'status_changed', 'open', 'in_progress', 'Work started.');
        ApiResponse::ok(null, 'Work order started.');
    });

    // ── Complete (maintenance staff marks work done) ─────────────
    $router->post('maintenance/{id}/complete', function (string $id) use ($svc, $db, $inApp) {
        ApiAuth::requireScope($db, 'write:maintenance');
        $user = ApiAuth::user();
        $body = Router::body();

        // Maintenance staff: must be assigned; note is required
        if ($user['role'] === 'maintenance') {
            $check = $db->prepare("SELECT assigned_to, tenant_id, request_number, issue_title FROM maintenance_requests WHERE id = ?");
            $check->execute([(int)$id]);
            $row = $check->fetch();
            if (!$row || (int)$row['assigned_to'] !== $user['id']) {
                ApiResponse::forbidden('You can only complete tasks assigned to you.');
            }
            if (empty(trim($body['completion_notes'] ?? ''))) {
                ApiResponse::unprocessable('A completion note is required when marking work as done.');
            }
        }

        $matCost = (float)($body['materials_cost'] ?? 0);
        $labCost = (float)($body['labour_cost']    ?? 0);

        $db->prepare(
            "UPDATE maintenance_requests SET
                status = 'completed', work_completed = NOW(),
                labour_hours = ?, materials_cost = ?, labour_cost = ?,
                contractor_name = ?,
                notes = CONCAT(COALESCE(notes,''), IF(notes IS NOT NULL AND notes != '','\n',''), COALESCE(?,'')),
                updated_at = NOW()
             WHERE id = ?"
        )->execute([
            (float)($body['labour_hours']   ?? 0),
            $matCost,
            $labCost,
            $body['contractor_name']  ?? null,
            $body['completion_notes'] ?? null,
            (int)$id,
        ]);

        $total = $matCost + $labCost;
        $note  = 'Maintenance completed the work'
            . (!empty($body['completion_notes']) ? ': ' . substr($body['completion_notes'], 0, 200) : '')
            . ($total > 0 ? '. Total cost: ' . number_format($total, 2) : '')
            . (!empty($body['contractor_name']) ? '. Contractor: ' . $body['contractor_name'] : '');

        $svc->logActivity((int)$id, 'completed', 'in_progress', 'completed', $note);

        // Notify tenant that work is done and awaits their approval
        $wo = $svc->find((int)$id);
        if ($wo && !empty($wo['tenant_id'])) {
            $tenantUser = $db->prepare(
                "SELECT u.id, u.name FROM users u
                 JOIN tenants t ON t.user_id = u.id
                 WHERE t.id = ?"
            );
            $tenantUser->execute([(int)$wo['tenant_id']]);
            $tu = $tenantUser->fetch();
            if ($tu) {
                $inApp($db, $tu['id'],
                    "Work Done — Your Approval Needed: {$wo['request_number']}",
                    "Maintenance has completed work on {$wo['issue_title']}. Please review and approve or reopen the request.",
                    "/maintenance/view?id={$wo['id']}"
                );
            }
        }

        ApiResponse::ok(null, 'Work order marked as completed. Awaiting tenant approval.');
    });

    // ── Approve (tenant: completed → resolved) ───────────────────
    $router->post('maintenance/{id}/approve', function (string $id) use ($svc, $db, $inApp) {
        ApiAuth::requireScope($db, 'write:maintenance');
        $user = ApiAuth::user();
        $wo   = $svc->find((int)$id);
        if (!$wo) ApiResponse::notFound('Work order not found.');

        // Tenants can only approve their own requests
        if ($user['role'] === 'tenant') {
            $tenantRow = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $tenantRow->execute([$user['id']]);
            $t = $tenantRow->fetch();
            if (!$t || (int)$t['id'] !== (int)($wo['tenant_id'] ?? 0)) {
                ApiResponse::forbidden('You can only approve your own requests.');
            }
        } elseif (!in_array($user['role'], ['admin', 'manager'], true)) {
            ApiResponse::forbidden('Only tenants, admins and managers can approve requests.');
        }

        $note = trim(Router::body()['approval_note'] ?? '');
        if (!$note) ApiResponse::unprocessable('An approval note is required.');

        $res = $svc->approve((int)$id, $note);
        if (!$res['success']) ApiResponse::unprocessable($res['message']);

        // Notify the assigned maintenance staff
        if (!empty($wo['assigned_to'])) {
            $inApp($db, (int)$wo['assigned_to'],
                "Work Approved: {$wo['request_number']}",
                "The tenant has approved and closed work order {$wo['request_number']}: {$wo['issue_title']}.",
                "/maintenance/view?id={$wo['id']}"
            );
        }

        ApiResponse::ok(null, $res['message']);
    });

    // ── Reopen (tenant: completed/resolved → open) ───────────────
    $router->post('maintenance/{id}/reopen', function (string $id) use ($svc, $db, $inApp) {
        ApiAuth::requireScope($db, 'write:maintenance');
        $user = ApiAuth::user();
        $wo   = $svc->find((int)$id);
        if (!$wo) ApiResponse::notFound('Work order not found.');

        // Tenants can only reopen their own requests
        if ($user['role'] === 'tenant') {
            $tenantRow = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $tenantRow->execute([$user['id']]);
            $t = $tenantRow->fetch();
            if (!$t || (int)$t['id'] !== (int)($wo['tenant_id'] ?? 0)) {
                ApiResponse::forbidden('You can only reopen your own requests.');
            }
        } elseif (!in_array($user['role'], ['admin', 'manager'], true)) {
            ApiResponse::forbidden('Only tenants, admins and managers can reopen requests.');
        }

        $note = trim(Router::body()['reopen_note'] ?? '');
        if (!$note) ApiResponse::unprocessable('A reason for reopening is required.');

        $res = $svc->reopen((int)$id, $note);
        if (!$res['success']) ApiResponse::unprocessable($res['message']);

        // Notify assigned maintenance staff that request was reopened
        if (!empty($wo['assigned_to'])) {
            $inApp($db, (int)$wo['assigned_to'],
                "Request Reopened: {$wo['request_number']}",
                "The tenant has reopened work order {$wo['request_number']}. Reason: " . mb_substr($note, 0, 100),
                "/maintenance/view?id={$wo['id']}"
            );
        }

        ApiResponse::ok(null, $res['message']);
    });

    // ── Logs ─────────────────────────────────────────────────────
    $router->get('maintenance/{id}/logs', function (string $id) use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:maintenance');
        try {
            $logs = $svc->getLogs((int)$id);
        } catch (Throwable) {
            $logs = [];
        }
        ApiResponse::ok($logs);
    });
}
