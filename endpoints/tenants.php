<?php
/**
 * Tenants endpoints
 *
 * GET    /api/v1/tenants                     list (paginated, filterable)
 * POST   /api/v1/tenants                     create + optional unit assignment
 * GET    /api/v1/tenants/{id}                single + lease + payment summary
 * PUT    /api/v1/tenants/{id}                full update
 * PATCH  /api/v1/tenants/{id}                partial update
 * GET    /api/v1/tenants/{id}/statement      payment statement (date range)
 * GET    /api/v1/tenants/{id}/invoices       invoices for tenant
 * GET    /api/v1/tenants/{id}/payments       payments for tenant
 * GET    /api/v1/tenants/{id}/maintenance    maintenance requests for tenant
 */

// Shared welcome email builder — also defined in users.php (loaded later); guard prevents redeclaration.
if (!function_exists('buildWelcomeEmail')):
function buildWelcomeEmail(string $name, string $role, string $email, string $setupLink, string $tempPassword, string $loginUrl): string
{
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Welcome to RUMS</title>
</head>
<body style="margin:0;padding:0;background:#f2f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Helvetica Neue',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;padding:48px 16px;">
<tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" style="max-width:520px;width:100%;background:#ffffff;border-radius:8px;border:1px solid #e2e2e2;">

  <tr><td style="padding:40px 48px 0;text-align:center;">
    <span style="font-size:20px;font-weight:800;color:#1a56db;letter-spacing:-0.5px;">RUMS</span>
  </td></tr>

  <tr><td style="padding:28px 48px 0;text-align:center;">
    <p style="margin:0 0 8px;font-size:20px;font-weight:700;color:#111111;">Welcome, {$name}</p>
    <p style="margin:0;font-size:14px;line-height:1.7;color:#666666;">
      Your account is ready. Use the credentials below to log in,<br>
      then set a permanent password when prompted.
    </p>
  </td></tr>

  <!-- Credentials box -->
  <tr><td style="padding:24px 48px 0;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f7f8fa;border-radius:6px;border:1px solid #e8e8e8;">
      <tr>
        <td style="padding:14px 20px;border-bottom:1px solid #e8e8e8;">
          <span style="font-size:11px;color:#999999;text-transform:uppercase;letter-spacing:0.06em;">Email</span><br>
          <span style="font-size:14px;color:#111111;font-weight:600;">{$email}</span>
        </td>
      </tr>
      <tr>
        <td style="padding:14px 20px;border-bottom:1px solid #e8e8e8;">
          <span style="font-size:11px;color:#999999;text-transform:uppercase;letter-spacing:0.06em;">Temporary Password</span><br>
          <span style="font-size:16px;color:#1a56db;font-weight:700;font-family:'Courier New',monospace;letter-spacing:1px;">{$tempPassword}</span>
        </td>
      </tr>
      <tr>
        <td style="padding:14px 20px;">
          <span style="font-size:11px;color:#999999;text-transform:uppercase;letter-spacing:0.06em;">Role</span><br>
          <span style="font-size:14px;color:#111111;font-weight:600;text-transform:capitalize;">{$role}</span>
        </td>
      </tr>
    </table>
  </td></tr>

  <!-- Buttons -->
  <tr><td style="padding:28px 48px 0;text-align:center;">
    <table cellpadding="0" cellspacing="0" style="margin:0 auto 12px;">
      <tr>
        <td style="background:#1a56db;border-radius:6px;">
          <a href="{$loginUrl}" style="display:inline-block;padding:13px 32px;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">Log In to RUMS</a>
        </td>
      </tr>
    </table>
    <table cellpadding="0" cellspacing="0" style="margin:0 auto;">
      <tr>
        <td style="border:1px solid #d0d5dd;border-radius:6px;">
          <a href="{$setupLink}" style="display:inline-block;padding:12px 32px;color:#374151;font-size:14px;font-weight:600;text-decoration:none;">Set Up Password</a>
        </td>
      </tr>
    </table>
    <p style="margin:12px 0 0;font-size:12px;color:#aaaaaa;">Setup link expires in 72 hours.</p>
  </td></tr>

  <!-- Footer -->
  <tr><td style="padding:32px 48px;">
    <table width="100%" cellpadding="0" cellspacing="0"><tr><td style="border-top:1px solid #eeeeee;padding-top:20px;font-size:12px;color:#bbbbbb;text-align:center;line-height:1.6;">
      If you were not expecting this email, you can ignore it.
      &nbsp;&middot;&nbsp; &copy; {$year} RUMS
    </td></tr></table>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}
endif;

function registerTenantRoutes(Router $router, PDO $db): void
{
    $svc = new TenantService($db);

    // Asserts the tenant has at least one lease under the landlord's properties.
    $ownTenant = static function (int $tenantId, int $lid) use ($db): void {
        $ok = $db->prepare(
            "SELECT 1 FROM leases l
             JOIN units u ON u.id = l.unit_id
             JOIN properties pr ON pr.id = u.property_id
             WHERE l.tenant_id = ? AND pr.landlord_id = ? LIMIT 1"
        );
        $ok->execute([$tenantId, $lid]);
        if (!$ok->fetchColumn()) ApiResponse::notFound('Tenant not found.');
    };

    $router->get('tenants', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'read:tenants');
        $landlordId = ApiAuth::landlordId($db);
        ApiResponse::paginated($svc->list(
            filters: [
                'search'       => Router::strParam('search'),
                'status'       => Router::strParam('status'),
                'property_id'  => Router::intParam('property_id'),
                'landlord_id'  => $landlordId,
            ],
            page: Router::page(), perPage: Router::perPage()
        ));
    });

    $router->post('tenants', function () use ($svc, $db) {
        ApiAuth::requireScope($db, 'write:tenants');
        $body = Router::body();

        // ── Create tenant + user account ──────────────────────
        $res = $svc->create($body);
        if (!$res['success']) {
            ApiResponse::unprocessable($res['message'], $res['errors'] ?? []);
        }

        $tenantId     = (int)$res['id'];
        $userId       = (int)$res['user_id'];
        $responseData = ['id' => $tenantId, 'user_id' => $userId];
        $emailSent    = false;
        $emailError   = null;

        // ── Generate setup token ──────────────────────────────
        $setupToken = null;
        try {
            $setupToken  = bin2hex(random_bytes(32));
            $tokenExpiry = date('Y-m-d H:i:s', strtotime('+72 hours'));
            $db->prepare(
                "UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?"
            )->execute([$setupToken, $tokenExpiry, $userId]);
        } catch (Throwable $e) {
            error_log('[RUMS] Tenant setup token failed for user ' . $userId . ': ' . $e->getMessage());
            $setupToken = null;
        }

        // ── Send welcome email ────────────────────────────────
        if ($setupToken && !empty($body['email'])) {
            try {
                $display = $db->prepare(
                    "SELECT setting_key, setting_value FROM settings
                     WHERE setting_key IN ('company_name','mail_from_name','mail_from_email')"
                );
                $display->execute();
                $cfg = array_column($display->fetchAll(), 'setting_value', 'setting_key');

                $mailer = new MailService([
                    'smtp_host'       => env('MAIL_HOST',       ''),
                    'smtp_port'       => (int)env('MAIL_PORT',  465),
                    'smtp_user'       => env('MAIL_USER',       ''),
                    'smtp_pass'       => env('MAIL_PASS',       ''),
                    'smtp_encryption' => env('MAIL_ENCRYPTION', 'ssl'),
                    'from_name'       => env('MAIL_FROM_NAME',  $cfg['mail_from_name']  ?? ($cfg['company_name'] ?? 'RUMS')),
                    'from_email'      => env('MAIL_FROM_EMAIL', $cfg['mail_from_email'] ?? env('MAIL_USER', '')),
                ]);

                $frontendUrl = rtrim(env('FRONTEND_URL', 'https://properties.vertexiot.co.ke'), '/');
                $setupLink   = $frontendUrl . '/setup-password?token=' . $setupToken;
                $loginUrl    = $frontendUrl . '/login';
                $tenantName  = trim(($body['first_name'] ?? '') . ' ' . ($body['last_name'] ?? ''));

                $result = $mailer->send(
                    $body['email'],
                    'Welcome to RUMS — Your Tenant Account',
                    buildWelcomeEmail($tenantName, 'tenant', $body['email'], $setupLink, 'Tenant@1234', $loginUrl)
                );

                $emailSent  = $result['success'];
                $emailError = $result['success'] ? null : ($result['error'] ?? 'Unknown mail error');
                if (!$emailSent) {
                    error_log('[RUMS] Tenant welcome email failed for user ' . $userId . ': ' . $emailError);
                }
            } catch (Throwable $e) {
                $emailError = $e->getMessage();
                error_log('[RUMS] Tenant welcome email exception for user ' . $userId . ': ' . $emailError);
            }
        }

        // ── Create lease if a unit was assigned ───────────────
        if (!empty($body['unit_id'])) {
            try {
                $leaseSvc  = new LeaseService($db);

                // Fetch unit's rent_amount as fallback when monthly_rent not provided
                if (empty($body['monthly_rent'])) {
                    $unitRow = $db->prepare("SELECT rent_amount FROM units WHERE id = ?");
                    $unitRow->execute([(int)$body['unit_id']]);
                    $unitData = $unitRow->fetch();
                    $body['monthly_rent'] = $unitData ? (float)$unitData['rent_amount'] : 0;
                }

                $startDate = $body['start_date'] ?? date('Y-m-d');
                $endDate   = $body['end_date']   ?? date('Y-m-d', strtotime($startDate . ' +1 year'));

                $leaseRes = $leaseSvc->create([
                    'unit_id'         => (int)$body['unit_id'],
                    'tenant_id'       => $tenantId,
                    'start_date'      => $startDate,
                    'end_date'        => $endDate,
                    'monthly_rent'    => (float)$body['monthly_rent'],
                    'initial_reading' => (float)($body['initial_reading'] ?? 0),
                ]);

                if ($leaseRes['success']) {
                    $responseData['lease_id']     = $leaseRes['id'];
                    $responseData['lease_number'] = $leaseRes['lease_number'];
                } else {
                    error_log('[RUMS] Auto-lease failed for tenant ' . $tenantId . ': ' . $leaseRes['message']);
                    $responseData['lease_error'] = $leaseRes['message'];
                }
            } catch (Throwable $e) {
                error_log('[RUMS] Auto-lease exception for tenant ' . $tenantId . ': ' . $e->getMessage());
                $responseData['lease_error'] = $e->getMessage();
            }
        }

        $responseData['email_sent'] = $emailSent;
        $msg = $emailSent ? 'Tenant created and welcome email sent.' : 'Tenant created.';
        if (!empty($responseData['lease_id']))  $msg .= ' Unit assigned.';
        if (!empty($responseData['lease_error'])) $msg .= ' Unit assignment failed: ' . $responseData['lease_error'];
        ApiResponse::created($responseData, $msg);
    });

    $router->get('tenants/{id}', function (string $id) use ($svc, $db, $ownTenant) {
        ApiAuth::requireScope($db, 'read:tenants');

        $user = ApiAuth::user();
        if ($user['role'] === 'tenant') {
            $own = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
            $own->execute([$user['id']]);
            $row = $own->fetch();
            if (!$row || (int)$row['id'] !== (int)$id) ApiResponse::forbidden('Access denied.');
        }
        $lid = ApiAuth::landlordId($db);
        if ($lid) $ownTenant((int)$id, $lid);

        $t = $svc->find((int)$id);
        $t ? ApiResponse::ok($t) : ApiResponse::notFound('Tenant not found.');
    });

    $router->put('tenants/{id}', function (string $id) use ($svc, $db, $ownTenant) {
        ApiAuth::requireScope($db, 'write:tenants');
        $lid = ApiAuth::landlordId($db);
        if ($lid) $ownTenant((int)$id, $lid);
        $res = $svc->update((int)$id, Router::body());
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    $router->patch('tenants/{id}', function (string $id) use ($svc, $db, $ownTenant) {
        ApiAuth::requireScope($db, 'write:tenants');
        $lid = ApiAuth::landlordId($db);
        if ($lid) $ownTenant((int)$id, $lid);
        $res = $svc->update((int)$id, Router::body());
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    $router->delete('tenants/{id}', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin');
        $res = $svc->delete((int)$id);
        $res['success']
            ? ApiResponse::ok(null, $res['message'])
            : ApiResponse::unprocessable($res['message']);
    });

    $router->get('tenants/{id}/statement', function (string $id) use ($svc, $db, $ownTenant) {
        ApiAuth::requireScope($db, 'read:tenants');
        $lid = ApiAuth::landlordId($db);
        if ($lid) $ownTenant((int)$id, $lid);
        $from = Router::strParam('date_from', date('Y-m-01'));
        $to   = Router::strParam('date_to',   date('Y-m-d'));
        ApiResponse::ok($svc->getStatement((int)$id, $from, $to));
    });

    $router->get('tenants/{id}/invoices', function (string $id) use ($db, $ownTenant) {
        ApiAuth::requireScope($db, 'read:invoices');
        $lid = ApiAuth::landlordId($db);
        if ($lid) $ownTenant((int)$id, $lid);
        $status = Router::strParam('status');
        $where  = $status ? "AND i.status = " . $db->quote($status) : '';
        $stmt   = $db->prepare(
            "SELECT i.*, u.unit_number, pr.name AS property_name
             FROM invoices i
             JOIN leases l      ON l.id  = i.lease_id
             JOIN units u       ON u.id  = l.unit_id
             JOIN properties pr ON pr.id = u.property_id
             WHERE i.tenant_id = ? $where ORDER BY i.invoice_date DESC LIMIT 50"
        );
        $stmt->execute([(int)$id]);
        ApiResponse::ok($stmt->fetchAll());
    });

    $router->get('tenants/{id}/payments', function (string $id) use ($db, $ownTenant) {
        ApiAuth::requireScope($db, 'read:payments');
        $lid = ApiAuth::landlordId($db);
        if ($lid) $ownTenant((int)$id, $lid);
        $stmt = $db->prepare(
            "SELECT p.*, i.invoice_number, u.unit_number
             FROM payments p
             LEFT JOIN invoices i ON i.id = p.invoice_id
             LEFT JOIN leases l   ON l.id = p.lease_id
             LEFT JOIN units u    ON u.id = l.unit_id
             WHERE p.tenant_id = ? ORDER BY p.payment_date DESC LIMIT 50"
        );
        $stmt->execute([(int)$id]);
        ApiResponse::ok($stmt->fetchAll());
    });

    // GET  /tenants/{id}/kyc-documents ─────────────────────────
    $router->get('tenants/{id}/kyc-documents', function (string $id) use ($db, $ownTenant) {
        ApiAuth::requireScope($db, 'read:tenants');
        $lid = ApiAuth::landlordId($db);
        if ($lid) $ownTenant((int)$id, $lid);
        $stmt = $db->prepare(
            "SELECT k.*, u.name AS uploaded_by_name
             FROM kyc_documents k
             LEFT JOIN users u ON u.id = k.uploaded_by
             WHERE k.tenant_id = ? ORDER BY k.created_at DESC"
        );
        $stmt->execute([(int)$id]);
        ApiResponse::ok($stmt->fetchAll());
    });

    // POST /tenants/{id}/kyc-documents ─────────────────────────
    $router->post('tenants/{id}/kyc-documents', function (string $id) use ($db, $ownTenant) {
        ApiAuth::requireScope($db, 'write:tenants');
        $lid = ApiAuth::landlordId($db);
        if ($lid) $ownTenant((int)$id, $lid);
        $body = Router::body();

        $missing = array_filter(['document_type','original_name','file_path'], fn($f) => empty($body[$f]));
        if ($missing) ApiResponse::unprocessable('Missing: ' . implode(', ', $missing));

        $validTypes = ['national_id_front','national_id_back','passport','alien_id',
                       'driving_license','payslip','bank_statement','lease_agreement','other'];
        if (!in_array($body['document_type'], $validTypes, true)) {
            ApiResponse::badRequest('Invalid document_type.');
        }

        // Verify tenant exists
        $check = $db->prepare("SELECT id FROM tenants WHERE id = ?");
        $check->execute([(int)$id]);
        if (!$check->fetch()) ApiResponse::notFound('Tenant not found.');

        $db->prepare(
            "INSERT INTO kyc_documents
                (tenant_id, document_type, original_name, file_path, file_size, mime_type, notes, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            (int)$id,
            $body['document_type'],
            $body['original_name'],
            $body['file_path'],
            isset($body['file_size']) ? (int)$body['file_size'] : null,
            $body['mime_type'] ?? null,
            $body['notes'] ?? null,
            ApiAuth::userId(),
        ]);

        ApiResponse::created(['id' => (int)$db->lastInsertId()], 'Document record saved.');
    });

    // DELETE /tenants/{id}/kyc-documents/{doc_id} ──────────────
    $router->delete('tenants/{id}/kyc-documents/{doc_id}', function (string $id, string $doc_id) use ($db) {
        ApiAuth::requireScope($db, 'write:tenants');

        $stmt = $db->prepare("SELECT id, file_path FROM kyc_documents WHERE id = ? AND tenant_id = ?");
        $stmt->execute([(int)$doc_id, (int)$id]);
        $doc = $stmt->fetch();
        if (!$doc) ApiResponse::notFound('Document not found.');

        $db->prepare("DELETE FROM kyc_documents WHERE id = ?")->execute([(int)$doc_id]);
        ApiResponse::ok(['file_path' => $doc['file_path']], 'Document deleted.');
    });

    $router->get('tenants/{id}/maintenance', function (string $id) use ($db, $ownTenant) {
        ApiAuth::requireScope($db, 'read:maintenance');
        $lid = ApiAuth::landlordId($db);
        if ($lid) $ownTenant((int)$id, $lid);
        $stmt = $db->prepare(
            "SELECT mr.*, u.unit_number, pr.name AS property_name
             FROM maintenance_requests mr
             LEFT JOIN units u      ON u.id  = mr.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             WHERE mr.tenant_id = ? ORDER BY mr.created_at DESC LIMIT 30"
        );
        $stmt->execute([(int)$id]);
        ApiResponse::ok($stmt->fetchAll());
    });
}
