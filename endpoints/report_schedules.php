<?php
/**
 * Report Schedules endpoints (admin/manager only)
 *
 * GET    /api/v1/report-schedules              list
 * POST   /api/v1/report-schedules              create
 * GET    /api/v1/report-schedules/{id}         find
 * PUT    /api/v1/report-schedules/{id}         update
 * DELETE /api/v1/report-schedules/{id}         delete
 * POST   /api/v1/report-schedules/{id}/run     run immediately (sends email)
 */
function registerReportScheduleRoutes(Router $router, PDO $db): void
{
    $requireAdmin = fn() => ApiAuth::requireRole($db, 'admin', 'manager');

    // ── List ─────────────────────────────────────────────────
    $router->get('report-schedules', function () use ($db, $requireAdmin) {
        $requireAdmin();
        $stmt = $db->query(
            "SELECT rs.*, CONCAT(u.first_name,' ',u.last_name) AS created_by_name
             FROM report_schedules rs
             LEFT JOIN users u ON u.id = rs.created_by
             ORDER BY rs.name"
        );
        $rows = $stmt->fetchAll();
        ApiResponse::ok(['data' => $rows, 'total' => count($rows)]);
    });

    // ── Create ───────────────────────────────────────────────
    $router->post('report-schedules', function () use ($db, $requireAdmin) {
        $requireAdmin();
        $body = Router::body();
        $user = ApiAuth::user();

        $required = ['name', 'report_type', 'frequency', 'recipients'];
        foreach ($required as $f) {
            if (empty($body[$f])) { ApiResponse::unprocessable("$f is required."); return; }
        }

        $recipients = is_array($body['recipients']) ? $body['recipients'] : [$body['recipients']];
        $filters    = !empty($body['filters']) && is_array($body['filters']) ? $body['filters'] : null;

        $nextRun = computeNextRun($body['frequency'], (int)($body['run_day'] ?? 1), (int)($body['run_hour'] ?? 7));

        $db->prepare(
            "INSERT INTO report_schedules
                (name, report_type, format, filters, frequency, run_day, run_hour, recipients, is_active, next_run_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $body['name'],
            $body['report_type'],
            $body['format']       ?? 'csv',
            $filters              ? json_encode($filters) : null,
            $body['frequency'],
            (int)($body['run_day']  ?? 1),
            (int)($body['run_hour'] ?? 7),
            json_encode($recipients),
            isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1,
            $nextRun,
            $user['id'],
        ]);

        ApiResponse::created(['id' => (int)$db->lastInsertId()], 'Schedule created.');
    });

    // ── Find ─────────────────────────────────────────────────
    $router->get('report-schedules/{id}', function (string $id) use ($db, $requireAdmin) {
        $requireAdmin();
        $stmt = $db->prepare(
            "SELECT rs.*, CONCAT(u.first_name,' ',u.last_name) AS created_by_name
             FROM report_schedules rs
             LEFT JOIN users u ON u.id = rs.created_by
             WHERE rs.id = ?"
        );
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch();
        $row ? ApiResponse::ok($row) : ApiResponse::notFound('Schedule not found.');
    });

    // ── Update ───────────────────────────────────────────────
    $router->put('report-schedules/{id}', function (string $id) use ($db, $requireAdmin) {
        $requireAdmin();
        $body    = Router::body();
        $allowed = ['name','report_type','format','filters','frequency','run_day','run_hour','recipients','is_active'];

        $fields  = [];
        $values  = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $body)) continue;
            if ($k === 'recipients') {
                $fields[] = "$k = ?";
                $values[] = json_encode(is_array($body[$k]) ? $body[$k] : [$body[$k]]);
            } elseif ($k === 'filters') {
                $fields[] = "$k = ?";
                $values[] = $body[$k] ? json_encode($body[$k]) : null;
            } else {
                $fields[] = "$k = ?";
                $values[] = $body[$k];
            }
        }

        if (empty($fields)) { ApiResponse::badRequest('Nothing to update.'); return; }

        // Recompute next_run if schedule params changed
        if (array_key_exists('frequency', $body) || array_key_exists('run_day', $body) || array_key_exists('run_hour', $body)) {
            $cur = $db->prepare("SELECT frequency, run_day, run_hour FROM report_schedules WHERE id = ?");
            $cur->execute([(int)$id]);
            $cur = $cur->fetch() ?: [];
            $freq    = $body['frequency']  ?? $cur['frequency']  ?? 'monthly';
            $runDay  = (int)($body['run_day']  ?? $cur['run_day']  ?? 1);
            $runHour = (int)($body['run_hour'] ?? $cur['run_hour'] ?? 7);
            $fields[] = 'next_run_at = ?';
            $values[] = computeNextRun($freq, $runDay, $runHour);
        }

        $values[] = (int)$id;
        $db->prepare("UPDATE report_schedules SET " . implode(', ', $fields) . " WHERE id = ?")->execute($values);
        ApiResponse::ok(null, 'Schedule updated.');
    });

    // ── Delete ───────────────────────────────────────────────
    $router->delete('report-schedules/{id}', function (string $id) use ($db, $requireAdmin) {
        $requireAdmin();
        $db->prepare("DELETE FROM report_schedules WHERE id = ?")->execute([(int)$id]);
        ApiResponse::ok(null, 'Schedule deleted.');
    });

    // ── Run now ───────────────────────────────────────────────
    $router->post('report-schedules/{id}/run', function (string $id) use ($db, $requireAdmin) {
        $requireAdmin();

        $stmt = $db->prepare("SELECT * FROM report_schedules WHERE id = ?");
        $stmt->execute([(int)$id]);
        $sched = $stmt->fetch();
        if (!$sched) { ApiResponse::notFound('Schedule not found.'); return; }

        $svc        = new ReportService($db);
        $filters    = $sched['filters'] ? (json_decode($sched['filters'], true) ?? []) : [];
        $exportData = $svc->exportCsv($sched['report_type'], $filters);

        if (empty($exportData['headers'])) {
            ApiResponse::serverError('No data available for export.');
            return;
        }

        // Build CSV string
        ob_start();
        $tmp = fopen('php://output', 'w');
        fputs($tmp, "\xEF\xBB\xBF");
        fputcsv($tmp, $exportData['headers']);
        foreach ($exportData['rows'] as $row) {
            fputcsv($tmp, is_array($row) ? array_values($row) : [$row]);
        }
        fclose($tmp);
        $csvContent = ob_get_clean();

        // Send via email with CSV as inline attachment link (base64)
        $recipients  = json_decode($sched['recipients'], true) ?? [];
        $mailSvc     = new MailService();
        $reportLabel = ucwords(str_replace('_', ' ', $sched['report_type']));
        $subject     = "$reportLabel Report — " . date('d M Y');
        $htmlBody    = "<p>Please find the <strong>$reportLabel</strong> report attached (CSV data inline below).</p>"
                     . "<p>Generated: " . date('d M Y H:i') . "</p>"
                     . "<p><em>This is a scheduled report from RUMS.</em></p>"
                     . "<pre style='font-size:12px;max-height:500px;overflow:auto'>"
                     . htmlspecialchars(substr($csvContent, 0, 10000)) . (strlen($csvContent) > 10000 ? "\n...(truncated)" : '')
                     . "</pre>";

        $sent   = 0;
        $errors = [];
        foreach ($recipients as $email) {
            $res = $mailSvc->send($email, $subject, $htmlBody);
            if ($res['success']) $sent++;
            else $errors[] = "$email: " . ($res['error'] ?? 'failed');
        }

        // Update last/next run timestamps
        $nextRun = computeNextRun($sched['frequency'], (int)$sched['run_day'], (int)$sched['run_hour']);
        $db->prepare("UPDATE report_schedules SET last_run_at = NOW(), next_run_at = ? WHERE id = ?")
           ->execute([$nextRun, (int)$id]);

        ApiResponse::ok([
            'sent'   => $sent,
            'total'  => count($recipients),
            'errors' => $errors,
        ], "Report sent to $sent of " . count($recipients) . " recipients.");
    });
}

// ── Helper: compute next run datetime ─────────────────────────
function computeNextRun(string $frequency, int $runDay, int $runHour): string
{
    $now  = new DateTime();
    $time = sprintf('%02d:00:00', $runHour);

    switch ($frequency) {
        case 'daily':
            $next = new DateTime('today ' . $time);
            if ($next <= $now) $next->modify('+1 day');
            break;

        case 'weekly':
            // runDay 0=Sun…6=Sat
            $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            $next = new DateTime('this ' . ($days[$runDay] ?? 'Monday') . ' ' . $time);
            if ($next <= $now) $next->modify('+1 week');
            break;

        case 'monthly':
        default:
            $year  = (int)date('Y');
            $month = (int)date('n');
            $day   = min($runDay, (int)date('t', mktime(0, 0, 0, $month, 1, $year)));
            $next  = new DateTime(sprintf('%04d-%02d-%02d %s', $year, $month, $day, $time));
            if ($next <= $now) {
                $month++;
                if ($month > 12) { $month = 1; $year++; }
                $day  = min($runDay, (int)date('t', mktime(0, 0, 0, $month, 1, $year)));
                $next = new DateTime(sprintf('%04d-%02d-%02d %s', $year, $month, $day, $time));
            }
            break;
    }

    return $next->format('Y-m-d H:i:s');
}
