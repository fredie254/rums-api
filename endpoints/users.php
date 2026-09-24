<?php
/**
 * Users & API tokens endpoints  (admin only, except self-service token ops)
 *
 * GET    /api/v1/users                  list users
 * POST   /api/v1/users                  create user
 * GET    /api/v1/users/{id}             single user
 * PATCH  /api/v1/users/{id}             update user (name, phone, role, password)
 * PATCH  /api/v1/users/{id}/status      activate / suspend / deactivate
 * GET    /api/v1/users/{id}/tokens      list tokens for a user
 *
 * GET    /api/v1/tokens                 list all tokens (admin)
 * DELETE /api/v1/tokens/{id}            revoke any token (admin)
 */

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
        <td style="padding:14px 20px;">
          <span style="font-size:11px;color:#999999;text-transform:uppercase;letter-spacing:0.06em;">Temporary Password</span><br>
          <span style="font-size:16px;color:#1a56db;font-weight:700;font-family:'Courier New',monospace;letter-spacing:1px;">{$tempPassword}</span>
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

function buildPasswordResetEmail(string $name, string $resetLink): string
{
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Password Reset — RUMS</title>
</head>
<body style="margin:0;padding:0;background:#f2f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Helvetica Neue',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;padding:48px 16px;">
<tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" style="max-width:520px;width:100%;background:#ffffff;border-radius:8px;border:1px solid #e2e2e2;">

  <tr><td style="padding:40px 48px 0;text-align:center;">
    <span style="font-size:20px;font-weight:800;color:#1a56db;letter-spacing:-0.5px;">RUMS</span>
  </td></tr>

  <tr><td style="padding:28px 48px 0;text-align:center;">
    <p style="margin:0 0 8px;font-size:20px;font-weight:700;color:#111111;">Reset your password</p>
    <p style="margin:0;font-size:14px;line-height:1.7;color:#666666;">
      Hi {$name}, click the button below to choose a new password.<br>
      This link expires in <strong>1 hour</strong>.
    </p>
  </td></tr>

  <tr><td style="padding:28px 48px 0;text-align:center;">
    <table cellpadding="0" cellspacing="0" style="margin:0 auto;">
      <tr>
        <td style="background:#1a56db;border-radius:6px;">
          <a href="{$resetLink}" style="display:inline-block;padding:13px 36px;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">Reset My Password</a>
        </td>
      </tr>
    </table>
  </td></tr>

  <tr><td style="padding:32px 48px;">
    <table width="100%" cellpadding="0" cellspacing="0"><tr><td style="border-top:1px solid #eeeeee;padding-top:20px;font-size:12px;color:#bbbbbb;text-align:center;line-height:1.6;">
      If you didn't request this, your account is safe — ignore this email.
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
function registerUserRoutes(Router $router, PDO $db): void
{
    // ── Users ─────────────────────────────────────────────────

    $router->get('users', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');

        $search = Router::strParam('search');
        $role   = Router::strParam('role');
        $status = Router::strParam('status', 'active');
        $pg     = Router::page();
        $pp     = Router::perPage();
        $off    = ($pg - 1) * $pp;

        $where  = ['1=1'];
        $params = [];

        if ($search) {
            $where[] = '(name LIKE ? OR email LIKE ?)';
            $s = "%$search%"; $params[] = $s; $params[] = $s;
        }
        if ($role)             { $where[] = 'role = ?';   $params[] = $role; }
        if ($status !== 'all') { $where[] = 'status = ?'; $params[] = $status; }

        $w       = 'WHERE ' . implode(' AND ', $where);
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM users $w");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT id, name, email, phone, role, status, last_login, created_at
             FROM users $w ORDER BY name LIMIT ? OFFSET ?"
        );
        foreach ($params as $k => $v) $stmt->bindValue($k + 1, $v);
        $stmt->bindValue(count($params) + 1, $pp,  PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $off, PDO::PARAM_INT);
        $stmt->execute();

        ApiResponse::ok($stmt->fetchAll(), '', [
            'total'        => $total,
            'per_page'     => $pp,
            'current_page' => $pg,
            'total_pages'  => max(1, (int)ceil($total / $pp)),
        ]);
    });

    $router->post('users', function () use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $body    = Router::body();
        $missing = array_filter(['name','email','role'], fn($f) => empty($body[$f]));
        if ($missing) ApiResponse::unprocessable('Missing: ' . implode(', ', $missing));

        $exists = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $exists->execute([$body['email']]);
        if ($exists->fetchColumn() > 0) ApiResponse::conflict('Email already registered.');

        $validRoles = ['admin','manager','landlord','tenant','accountant','maintenance','auditor','security'];
        if (!in_array($body['role'], $validRoles, true)) {
            ApiResponse::badRequest('Invalid role. Must be one of: ' . implode(', ', $validRoles));
        }

        $plain = 'Rums@1234';
        $hash  = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 10]);

        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO users (name, email, phone, role, password, status, must_change_password) VALUES (?,?,?,?,?,'active',1)"
            )->execute([$body['name'], $body['email'], $body['phone'] ?? null, $body['role'], $hash]);
            $userId = (int)$db->lastInsertId();

            $responseData = ['id' => $userId];

            // When creating a landlord user, also create their landlords record
            if ($body['role'] === 'landlord') {
                $db->prepare(
                    "INSERT INTO landlords (user_id, notes) VALUES (?, ?)"
                )->execute([$userId, $body['notes'] ?? null]);
                $responseData['landlord_id'] = (int)$db->lastInsertId();
            }

            // When creating a tenant user, also create their tenant profile
            if ($body['role'] === 'tenant') {
                if (empty($body['id_number'])) {
                    $db->rollBack();
                    ApiResponse::unprocessable('id_number is required when creating a tenant user.');
                }
                $nameParts = explode(' ', trim($body['name']), 2);
                $db->prepare(
                    "INSERT INTO tenants
                        (user_id, first_name, last_name, email, phone, id_number, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'active')"
                )->execute([
                    $userId,
                    $nameParts[0],
                    $nameParts[1] ?? '',
                    $body['email'],
                    $body['phone'] ?? null,
                    $body['id_number'],
                ]);
                $responseData['tenant_id'] = (int)$db->lastInsertId();
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            ApiResponse::serverError('Failed to create user.', $e);
        }

        // ── Generate setup token ──────────────────────────────────
        $emailSent  = false;
        $emailError = null;

        try {
            $setupToken  = bin2hex(random_bytes(32));
            $tokenExpiry = date('Y-m-d H:i:s', strtotime('+72 hours'));
            $db->prepare(
                "UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?"
            )->execute([$setupToken, $tokenExpiry, $userId]);
        } catch (Throwable $e) {
            // Column may not exist yet — log but continue
            error_log('[RUMS] Failed to save setup token for user ' . $userId . ': ' . $e->getMessage());
            $setupToken = null;
        }

        // ── Send welcome email ────────────────────────────────────
        if ($setupToken) {
            try {
                require_once __DIR__ . '/../services/MailService.php';

                // SMTP credentials from .env — display info from settings table
                $display = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name','mail_from_name','mail_from_email')");
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

                $frontendUrl = rtrim(env('FRONTEND_URL', env('APP_URL', '')), '/');
                $setupLink   = $frontendUrl . '/setup-password?token=' . $setupToken;
                $loginUrl    = $frontendUrl . '/login';

                $result = $mailer->send(
                    $body['email'],
                    'Welcome to RUMS — Your Account Details',
                    buildWelcomeEmail($body['name'], $body['role'], $body['email'], $setupLink, $plain, $loginUrl)
                );

                $emailSent  = $result['success'];
                $emailError = $result['success'] ? null : ($result['error'] ?? 'Unknown mail error');

                if (!$emailSent) {
                    error_log('[RUMS] Welcome email failed for user ' . $userId . ': ' . $emailError);
                }
            } catch (Throwable $e) {
                $emailError = $e->getMessage();
                error_log('[RUMS] Welcome email exception for user ' . $userId . ': ' . $emailError);
            }
        }

        $responseData['email_sent']  = $emailSent;
        $responseData['email_error'] = $emailError;

        ApiResponse::created($responseData, $emailSent
            ? 'User created. Welcome email sent.'
            : 'User created. Welcome email could not be sent' . ($emailError ? ': ' . $emailError : '.'));
    });

    // POST /users/{id}/resend-welcome ─────────────────────────────
    $router->post('users/{id}/resend-welcome', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $targetId = (int)$id;

        $stmt = $db->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) ApiResponse::notFound('User not found.');

        // Refresh setup token (72 hrs)
        $setupToken  = bin2hex(random_bytes(32));
        $tokenExpiry = date('Y-m-d H:i:s', strtotime('+72 hours'));
        $db->prepare(
            "UPDATE users SET password_reset_token = ?, password_reset_expires = ?, must_change_password = 1 WHERE id = ?"
        )->execute([$setupToken, $tokenExpiry, $targetId]);

        try {
            require_once __DIR__ . '/../services/MailService.php';

            $display = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name','mail_from_name','mail_from_email')");
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

            $frontendUrl = rtrim(env('FRONTEND_URL', env('APP_URL', '')), '/');
            $setupLink   = $frontendUrl . '/setup-password?token=' . $setupToken;
            $loginUrl    = $frontendUrl . '/login';

            $result = $mailer->send(
                $target['email'],
                'Welcome to RUMS — Your Account Details',
                buildWelcomeEmail($target['name'], $target['role'], $target['email'], $setupLink, 'Rums@1234', $loginUrl)
            );

            if (!$result['success']) {
                error_log('[RUMS] Resend welcome failed for user ' . $targetId . ': ' . ($result['error'] ?? ''));
                ApiResponse::serverError('Email could not be sent: ' . ($result['error'] ?? 'Unknown error'));
            }
        } catch (Throwable $e) {
            error_log('[RUMS] Resend welcome exception for user ' . $targetId . ': ' . $e->getMessage());
            ApiResponse::serverError('Email could not be sent: ' . $e->getMessage());
        }

        ApiResponse::ok(null, 'Welcome email resent to ' . $target['email'] . '.');
    });

    $router->get('users/{id}', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $stmt = $db->prepare(
            "SELECT id, name, email, phone, role, status, must_change_password, last_login, created_at FROM users WHERE id = ?"
        );
        $stmt->execute([(int)$id]);
        $u = $stmt->fetch();
        if (!$u) ApiResponse::notFound('User not found.');
        $u['must_change_password'] = (bool)$u['must_change_password'];
        ApiResponse::ok($u);
    });

    $router->patch('users/{id}', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin');
        $body    = Router::body();
        $allowed = array_intersect_key($body, array_flip(['name', 'email', 'phone', 'role', 'status']));

        if (!empty($body['password'])) {
            $allowed['password'] = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 8]);
        }

        if (!$allowed) ApiResponse::badRequest('No valid fields to update.');

        try {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($allowed)));
            $db->prepare("UPDATE users SET $set WHERE id = ?")
               ->execute([...array_values($allowed), (int)$id]);

            // Auto-create landlord/tenant profile if role just changed
            if (!empty($body['role'])) {
                if ($body['role'] === 'landlord') {
                    $chk = $db->prepare("SELECT id FROM landlords WHERE user_id = ?");
                    $chk->execute([(int)$id]);
                    if (!$chk->fetch()) {
                        $db->prepare("INSERT INTO landlords (user_id) VALUES (?)")->execute([(int)$id]);
                    }
                } elseif ($body['role'] === 'tenant') {
                    $chk = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
                    $chk->execute([(int)$id]);
                    if (!$chk->fetch()) {
                        $u = $db->prepare("SELECT name, email, phone FROM users WHERE id = ?");
                        $u->execute([(int)$id]);
                        $uRow = $u->fetch();
                        if ($uRow) {
                            $parts = explode(' ', trim($uRow['name']), 2);
                            $db->prepare(
                                "INSERT INTO tenants (user_id, first_name, last_name, email, phone, status)
                                 VALUES (?,?,?,?,?,'active')"
                            )->execute([(int)$id, $parts[0], $parts[1] ?? '', $uRow['email'], $uRow['phone']]);
                        }
                    }
                }
            }

            // Purge cached tokens so role/status changes take effect immediately
            if (!empty($body['role']) || !empty($body['status']) || !empty($body['password'])) {
                ApiAuth::invalidateUserTokens($db, (int)$id);
            }
        } catch (\Throwable $e) {
            ApiResponse::serverError('Failed to update user: ' . $e->getMessage());
        }

        ApiResponse::ok(null, 'User updated.');
    });

    $router->delete('users/{id}', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin');
        $targetId = (int)$id;

        if ($targetId === ApiAuth::userId()) {
            ApiResponse::forbidden('You cannot delete your own account.');
        }

        $stmt = $db->prepare("SELECT id, name, role FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) ApiResponse::notFound('User not found.');

        // Revoke tokens from cache before the transaction so they stop working immediately
        ApiAuth::invalidateUserTokens($db, $targetId);

        $db->beginTransaction();
        try {
            // Nullify RESTRICT FK references that would block the DELETE
            $db->prepare("UPDATE bank_statement_entries SET imported_by  = NULL WHERE imported_by  = ?")->execute([$targetId]);
            $db->prepare("UPDATE message_templates       SET created_by  = NULL WHERE created_by   = ?")->execute([$targetId]);
            $db->prepare("UPDATE broadcast_messages      SET created_by  = NULL WHERE created_by   = ?")->execute([$targetId]);
            $db->prepare("UPDATE documents               SET uploaded_by = NULL WHERE uploaded_by  = ?")->execute([$targetId]);

            // Hard-delete tokens (CASCADE would handle this but being explicit)
            $db->prepare("DELETE FROM api_tokens WHERE user_id = ?")->execute([$targetId]);

            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            ApiResponse::serverError('Could not delete user: ' . $e->getMessage());
        }

        ApiResponse::ok(null, "User \"{$target['name']}\" has been deleted.");
    });

    $router->patch('users/{id}/status', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $status = Router::body()['status'] ?? '';
        if (!in_array($status, ['active','inactive','suspended'], true)) {
            ApiResponse::badRequest('status must be active, inactive, or suspended.');
        }
        if ((int)$id === ApiAuth::userId()) {
            ApiResponse::forbidden('Cannot modify your own status.');
        }
        $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$status, (int)$id]);

        // Status change must take effect immediately — purge cached tokens
        ApiAuth::invalidateUserTokens($db, (int)$id);

        ApiResponse::ok(['status' => $status], 'User status updated.');
    });

    $router->get('users/{id}/tokens', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $stmt = $db->prepare(
            "SELECT id, name, scopes, last_used, expires_at, revoked, created_at
             FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC"
        );
        $stmt->execute([(int)$id]);
        ApiResponse::ok($stmt->fetchAll());
    });

    // ── Tokens (admin) ────────────────────────────────────────

    $router->get('tokens', function () use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $pg  = Router::page();
        $pp  = Router::perPage(50);
        $off = ($pg - 1) * $pp;

        $stmt = $db->prepare(
            "SELECT t.id, t.name, t.scopes, t.last_used, t.expires_at, t.revoked, t.created_at,
                u.name AS user_name, u.email, u.role AS user_role
             FROM api_tokens t JOIN users u ON u.id = t.user_id
             ORDER BY t.created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $pp,  PDO::PARAM_INT);
        $stmt->bindValue(2, $off, PDO::PARAM_INT);
        $stmt->execute();
        ApiResponse::ok($stmt->fetchAll());
    });

    $router->delete('tokens/{id}', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin');

        // Fetch token value BEFORE revoking so we can evict it from APCu
        $row = $db->prepare("SELECT token FROM api_tokens WHERE id = ? AND revoked = 0");
        $row->execute([(int)$id]);
        $tokenValue = $row->fetchColumn();

        $db->prepare("UPDATE api_tokens SET revoked = 1 WHERE id = ?")->execute([(int)$id]);

        if ($tokenValue) ApiAuth::invalidateCache($tokenValue);

        ApiResponse::ok(null, 'Token revoked.');
    });
}
