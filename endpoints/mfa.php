<?php
/**
 * MFA endpoints — Multi-Factor Authentication (TOTP)
 *
 * GET    /api/v1/auth/mfa/status             current MFA status for authenticated user
 * POST   /api/v1/auth/mfa/setup              generate secret + QR URI + backup codes
 * POST   /api/v1/auth/mfa/confirm            verify first code and enable MFA
 * POST   /api/v1/auth/mfa/challenge          exchange pending_token + TOTP code for full token
 * POST   /api/v1/auth/mfa/disable            disable MFA (requires current password)
 * GET    /api/v1/auth/mfa/backup-codes       list remaining (unused) backup code count
 * POST   /api/v1/auth/mfa/backup-codes/regenerate  regenerate all backup codes (requires TOTP or password)
 *
 * Admin:
 * GET    /api/v1/mfa/users                   list users with MFA status (admin only)
 * DELETE /api/v1/mfa/users/{id}/reset        reset MFA for a user (admin only)
 */
function registerMfaRoutes(Router $router, PDO $db): void
{
    $appName = defined('APP_NAME') ? APP_NAME : 'RUMS';

    // ── Status ────────────────────────────────────────────────
    $router->get('auth/mfa/status', function () use ($db) {
        ApiAuth::require($db);
        $userId = ApiAuth::userId();

        $row = $db->prepare("SELECT is_enabled, enabled_at FROM mfa_secrets WHERE user_id = ?");
        $row->execute([$userId]);
        $mfa = $row->fetch();

        $remaining = 0;
        if ($mfa && $mfa['is_enabled']) {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM mfa_backup_codes WHERE user_id = ? AND used_at IS NULL"
            );
            $stmt->execute([$userId]);
            $remaining = (int)$stmt->fetchColumn();
        }

        ApiResponse::ok([
            'enabled'          => (bool)($mfa['is_enabled'] ?? false),
            'enabled_at'       => $mfa['enabled_at'] ?? null,
            'backup_codes_left'=> $remaining,
        ]);
    });

    // ── Setup — generate secret + QR URI (does NOT enable MFA yet) ──
    $router->post('auth/mfa/setup', function () use ($db, $appName) {
        ApiAuth::require($db);
        $userId = ApiAuth::userId();
        $email  = ApiAuth::user()['email'];

        // If MFA already enabled, require disable first
        $stmt = $db->prepare("SELECT is_enabled FROM mfa_secrets WHERE user_id = ?");
        $stmt->execute([$userId]);
        $existing = $stmt->fetch();
        if ($existing && $existing['is_enabled']) {
            ApiResponse::conflict('MFA is already enabled. Disable it before re-setup.');
        }

        $secret  = TOTP::generateSecret();
        $qrUri   = TOTP::getUri($secret, $email, $appName);

        // Generate but do not yet enable
        $backup  = TOTP::generateBackupCodes(8);

        // Store encrypted secret and backup code hashes (pending — not yet enabled)
        $encryptedSecret = Encryptor::encrypt($secret);

        if ($existing) {
            $db->prepare(
                "UPDATE mfa_secrets
                 SET secret = ?, is_enabled = 0, enabled_at = NULL
                 WHERE user_id = ?"
            )->execute([$encryptedSecret, $userId]);
        } else {
            $db->prepare(
                "INSERT INTO mfa_secrets (user_id, secret, is_enabled)
                 VALUES (?, ?, 0)"
            )->execute([$userId, $encryptedSecret]);
        }

        // Store pending backup codes (replace any previous)
        $db->prepare("DELETE FROM mfa_backup_codes WHERE user_id = ?")->execute([$userId]);
        $ins = $db->prepare(
            "INSERT INTO mfa_backup_codes (user_id, code_hash) VALUES (?, ?)"
        );
        foreach ($backup['hashes'] as $hash) {
            $ins->execute([$userId, $hash]);
        }

        ApiResponse::ok([
            'secret'       => $secret,             // show once to user for manual entry
            'qr_uri'       => $qrUri,              // encode as QR on frontend
            'backup_codes' => $backup['plain'],    // show once — user must save these
        ], 'Scan the QR code in your authenticator app, then confirm with a code.');
    });

    // ── Confirm — verify first code and enable MFA ────────────
    $router->post('auth/mfa/confirm', function () use ($db) {
        ApiAuth::require($db);
        $userId = ApiAuth::userId();
        $body   = Router::body();
        $code   = trim($body['code'] ?? '');

        if (!$code) ApiResponse::badRequest('code is required.');

        $stmt = $db->prepare(
            "SELECT secret FROM mfa_secrets WHERE user_id = ? AND is_enabled = 0"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) ApiResponse::badRequest('No pending MFA setup found. Call /auth/mfa/setup first.');

        $secret = Encryptor::decrypt($row['secret']);
        if (!$secret || !TOTP::verify($secret, $code)) {
            ApiResponse::unprocessable('Invalid code. Please try again.');
        }

        $db->prepare(
            "UPDATE mfa_secrets SET is_enabled = 1, enabled_at = NOW() WHERE user_id = ?"
        )->execute([$userId]);

        ApiResponse::ok(null, 'MFA enabled successfully. Keep your backup codes safe.');
    });

    // ── Challenge — exchange pending_token + TOTP code for full token ──
    $router->post('auth/mfa/challenge', function () use ($db) {
        $body          = Router::body();
        $pendingToken  = trim($body['pending_token'] ?? '');
        $code          = trim($body['code']          ?? '');

        if (!$pendingToken || !$code) {
            ApiResponse::badRequest('pending_token and code are required.');
        }

        // Resolve pending token
        $stmt = $db->prepare(
            "SELECT * FROM mfa_pending
             WHERE pending_token = ?
               AND expires_at > NOW()
               AND used = 0"
        );
        $stmt->execute([$pendingToken]);
        $pending = $stmt->fetch();

        if (!$pending) {
            ApiResponse::unauthorized('MFA session expired or invalid. Please log in again.');
        }

        $userId = (int)$pending['user_id'];

        // Fetch MFA secret
        $mStmt = $db->prepare(
            "SELECT secret FROM mfa_secrets WHERE user_id = ? AND is_enabled = 1"
        );
        $mStmt->execute([$userId]);
        $mfa = $mStmt->fetch();

        if (!$mfa) {
            ApiResponse::serverError('MFA configuration error.');
        }

        $secret  = Encryptor::decrypt($mfa['secret']);
        $verified = $secret && TOTP::verify($secret, $code);

        // If TOTP fails, try backup codes
        if (!$verified) {
            $bStmt = $db->prepare(
                "SELECT id, code_hash FROM mfa_backup_codes
                 WHERE user_id = ? AND used_at IS NULL"
            );
            $bStmt->execute([$userId]);
            $backupRows = $bStmt->fetchAll();

            $hashes = array_column($backupRows, 'code_hash');
            $idx    = TOTP::verifyBackupCode($code, $hashes);

            if ($idx >= 0) {
                // Mark backup code as used
                $db->prepare(
                    "UPDATE mfa_backup_codes SET used_at = NOW() WHERE id = ?"
                )->execute([$backupRows[$idx]['id']]);
                $verified = true;
            }
        }

        if (!$verified) {
            ApiResponse::unauthorized('Invalid authenticator code.');
        }

        // Mark pending token as used
        $db->prepare("UPDATE mfa_pending SET used = 1 WHERE id = ?")->execute([$pending['id']]);

        // Fetch user and issue full token (same as normal login)
        $uStmt = $db->prepare("SELECT id, name, email, role, status FROM users WHERE id = ?");
        $uStmt->execute([$userId]);
        $u = $uStmt->fetch();

        $scopeMap = [
            'admin'       => 'admin',
            'manager'     => 'read:properties,write:properties,read:units,write:units,read:tenants,write:tenants,read:leases,write:leases,read:payments,write:payments,read:invoices,write:invoices,read:maintenance,write:maintenance,read:reports',
            'accountant'  => 'read:properties,read:units,read:tenants,read:leases,read:payments,write:payments,read:invoices,write:invoices,read:reports',
            'landlord'    => 'read:properties,read:units,read:leases,read:payments,read:invoices,read:reports',
            'maintenance' => 'read:units,read:maintenance,write:maintenance',
            'auditor'     => 'read:properties,read:units,read:tenants,read:leases,read:payments,read:invoices,read:reports',
            'security'    => 'read:properties,read:units',
            'tenant'      => 'read:leases,read:payments,read:invoices,write:maintenance',
        ];
        $scopes = $scopeMap[$u['role']] ?? 'read:properties';
        $ttl    = (int)env('API_TOKEN_EXPIRY_DAYS', 365);
        $token  = ApiAuth::issueToken($db, $u['id'], 'Login Token', $scopes, $ttl);

        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$u['id']]);

        ApiResponse::ok([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttl > 0 ? $ttl * 86400 : null,
            'user' => [
                'id'     => $u['id'],
                'name'   => $u['name'],
                'email'  => $u['email'],
                'role'   => $u['role'],
                'status' => $u['status'],
            ],
        ], 'MFA verified. Login successful.');
    });

    // ── Disable MFA ───────────────────────────────────────────
    $router->post('auth/mfa/disable', function () use ($db) {
        ApiAuth::require($db);
        $userId = ApiAuth::userId();
        $body   = Router::body();
        $pw     = $body['password'] ?? '';

        if (!$pw) ApiResponse::badRequest('password is required to disable MFA.');

        // Verify password
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($pw, $row['password'])) {
            ApiResponse::unauthorized('Incorrect password.');
        }

        $db->prepare(
            "UPDATE mfa_secrets SET is_enabled = 0, enabled_at = NULL WHERE user_id = ?"
        )->execute([$userId]);

        $db->prepare("DELETE FROM mfa_backup_codes WHERE user_id = ?")->execute([$userId]);

        ApiResponse::ok(null, 'MFA has been disabled.');
    });

    // ── Backup code count ─────────────────────────────────────
    $router->get('auth/mfa/backup-codes', function () use ($db) {
        ApiAuth::require($db);
        $userId = ApiAuth::userId();

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM mfa_backup_codes WHERE user_id = ? AND used_at IS NULL"
        );
        $stmt->execute([$userId]);
        ApiResponse::ok(['remaining' => (int)$stmt->fetchColumn()]);
    });

    // ── Regenerate backup codes ───────────────────────────────
    $router->post('auth/mfa/backup-codes/regenerate', function () use ($db) {
        ApiAuth::require($db);
        $userId = ApiAuth::userId();
        $body   = Router::body();
        $code   = trim($body['code'] ?? '');

        if (!$code) ApiResponse::badRequest('Provide current TOTP code or password to regenerate codes.');

        // Try TOTP first
        $mStmt = $db->prepare(
            "SELECT secret FROM mfa_secrets WHERE user_id = ? AND is_enabled = 1"
        );
        $mStmt->execute([$userId]);
        $mfa = $mStmt->fetch();

        $verified = false;
        if ($mfa) {
            $secret   = Encryptor::decrypt($mfa['secret']);
            $verified = $secret && TOTP::verify($secret, $code);
        }

        // Fall back to password check
        if (!$verified) {
            $pStmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $pStmt->execute([$userId]);
            $row      = $pStmt->fetch();
            $verified = $row && password_verify($code, $row['password']);
        }

        if (!$verified) ApiResponse::unauthorized('Verification failed.');

        $backup = TOTP::generateBackupCodes(8);
        $db->prepare("DELETE FROM mfa_backup_codes WHERE user_id = ?")->execute([$userId]);
        $ins = $db->prepare(
            "INSERT INTO mfa_backup_codes (user_id, code_hash) VALUES (?, ?)"
        );
        foreach ($backup['hashes'] as $hash) {
            $ins->execute([$userId, $hash]);
        }

        ApiResponse::ok([
            'backup_codes' => $backup['plain'],
        ], 'Backup codes regenerated. Save these securely — they cannot be shown again.');
    });

    // ── Admin: list users with MFA status ─────────────────────
    $router->get('mfa/users', function () use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $stmt = $db->prepare(
            "SELECT u.id, u.name, u.email, u.role,
                    COALESCE(m.is_enabled, 0) AS mfa_enabled,
                    m.enabled_at
             FROM users u
             LEFT JOIN mfa_secrets m ON m.user_id = u.id
             WHERE u.status = 'active'
             ORDER BY u.role, u.name"
        );
        $stmt->execute();
        ApiResponse::ok(['data' => $stmt->fetchAll()]);
    });

    // ── Admin: reset a user's MFA ─────────────────────────────
    $router->delete('mfa/users/{id}/reset', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $userId = (int)$id;

        $db->prepare(
            "UPDATE mfa_secrets SET is_enabled = 0, enabled_at = NULL WHERE user_id = ?"
        )->execute([$userId]);
        $db->prepare("DELETE FROM mfa_backup_codes WHERE user_id = ?")->execute([$userId]);

        ApiResponse::ok(null, 'MFA reset for user #' . $userId . '.');
    });
}
