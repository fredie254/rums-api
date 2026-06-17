<?php
/**
 * Auth endpoints — no Bearer token required for login
 *
 * POST   /api/v1/auth/login        exchange credentials for a token
 * POST   /api/v1/auth/logout       revoke current token
 * GET    /api/v1/auth/me           current user profile + token info
 * POST   /api/v1/auth/token        issue a new named token (requires auth)
 * DELETE /api/v1/auth/token/{id}   revoke a token by id (requires auth)
 */
function registerAuthRoutes(Router $router, PDO $db): void
{
    // POST /auth/login ─────────────────────────────────────────
    $router->post('auth/login', function () use ($db) {
        $body     = Router::body();
        $email    = trim($body['email']    ?? '');
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            ApiResponse::badRequest('email and password are required.');
        }

        $stmt = $db->prepare(
            "SELECT id, name, email, role, status, password FROM users WHERE email = ? LIMIT 1"
        );
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if (!$u || !password_verify($password, $u['password'])) {
            ApiResponse::unauthorized('Invalid credentials.');
        }
        if ($u['status'] !== 'active') {
            ApiResponse::forbidden('Account suspended. Contact an administrator.');
        }

        // ── MFA check ─────────────────────────────────────────
        $mfaStmt = $db->prepare(
            "SELECT is_enabled FROM mfa_secrets WHERE user_id = ? AND is_enabled = 1"
        );
        $mfaStmt->execute([$u['id']]);
        if ($mfaStmt->fetch()) {
            // Issue a short-lived pending token (10 minutes) instead of a full token
            $pendingToken = bin2hex(random_bytes(32)); // 64-char hex
            $db->prepare(
                "DELETE FROM mfa_pending WHERE user_id = ? OR expires_at < NOW()"
            )->execute([$u['id']]);
            $db->prepare(
                "INSERT INTO mfa_pending (user_id, pending_token, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
            )->execute([$u['id'], $pendingToken]);

            ApiResponse::ok([
                'mfa_required'  => true,
                'pending_token' => $pendingToken,
                'expires_in'    => 600,
            ], 'MFA required. Submit the TOTP code to /auth/mfa/challenge.');
        }
        // ─────────────────────────────────────────────────────

        // Default scopes per role
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
        ], 'Login successful.');
    });

    // POST /auth/logout ────────────────────────────────────────
    $router->post('auth/logout', function () use ($db) {
        $token = ApiAuth::resolve($db);
        if ($token) {
            $db->prepare("UPDATE api_tokens SET revoked = 1 WHERE id = ?")->execute([$token['id']]);
        }
        ApiResponse::ok(null, 'Logged out.');
    });

    // GET /auth/me ─────────────────────────────────────────────
    $router->get('auth/me', function () use ($db) {
        $token = ApiAuth::require($db);
        $stmt  = $db->prepare(
            "SELECT id, name, email, phone, role, status, last_login, created_at
             FROM users WHERE id = ?"
        );
        $stmt->execute([$token['user_id']]);
        $u = $stmt->fetch();

        ApiResponse::ok([
            'user'  => $u,
            'token' => [
                'id'         => $token['id'],
                'name'       => $token['name'],
                'scopes'     => $token['scopes'],
                'last_used'  => $token['last_used'],
                'expires_at' => $token['expires_at'],
            ],
        ]);
    });

    // PATCH /auth/profile — update own name/phone ─────────────
    $router->patch('auth/profile', function () use ($db) {
        $token = ApiAuth::require($db);
        $body  = Router::body();
        $allowed = array_intersect_key($body, array_flip(['name', 'phone']));
        if (!$allowed) ApiResponse::badRequest('No valid fields to update.');
        $set  = implode(', ', array_map(fn($k) => "$k = ?", array_keys($allowed)));
        $db->prepare("UPDATE users SET $set WHERE id = ?")
           ->execute([...array_values($allowed), $token['user_id']]);
        ApiResponse::ok(null, 'Profile updated.');
    });

    // POST /auth/change-password — self-service password change ─
    $router->post('auth/change-password', function () use ($db) {
        $token   = ApiAuth::require($db);
        $body    = Router::body();
        $current = $body['current_password'] ?? '';
        $new     = $body['new_password']     ?? '';

        if (!$current || !$new) ApiResponse::badRequest('current_password and new_password are required.');
        if (strlen($new) < 8)   ApiResponse::unprocessable('New password must be at least 8 characters.');

        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$token['user_id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password'])) {
            ApiResponse::unauthorized('Current password is incorrect.');
        }

        $db->prepare("UPDATE users SET password = ? WHERE id = ?")
           ->execute([password_hash($new, PASSWORD_BCRYPT, ['cost' => 10]), $token['user_id']]);
        ApiResponse::ok(null, 'Password changed successfully.');
    });

    // POST /auth/token — issue named token ────────────────────
    $router->post('auth/token', function () use ($db) {
        ApiAuth::require($db);
        $body   = Router::body();
        $name   = trim($body['name']   ?? 'API Token');
        $scopes = trim($body['scopes'] ?? '');
        $ttl    = (int)($body['expires_days'] ?? env('API_TOKEN_EXPIRY_DAYS', 365));

        if (!$scopes) ApiResponse::badRequest('scopes is required.');

        $token = ApiAuth::issueToken($db, ApiAuth::userId(), $name, $scopes, $ttl);

        ApiResponse::created([
            'token'      => $token,
            'token_type' => 'Bearer',
            'name'       => $name,
            'scopes'     => $scopes,
            'expires_in' => $ttl > 0 ? $ttl * 86400 : null,
        ], 'Token issued.');
    });

    // DELETE /auth/token/{id} — revoke a token ────────────────
    $router->delete('auth/token/{id}', function (string $id) use ($db) {
        ApiAuth::require($db);
        $tokenId = (int)$id;
        $userId  = ApiAuth::userId();
        $role    = ApiAuth::userRole();

        $t = $db->prepare("SELECT user_id FROM api_tokens WHERE id = ?");
        $t->execute([$tokenId]);
        $row = $t->fetch();

        if (!$row) ApiResponse::notFound('Token not found.');
        if ($row['user_id'] !== $userId && $role !== 'admin') {
            ApiResponse::forbidden('You can only revoke your own tokens.');
        }

        $db->prepare("UPDATE api_tokens SET revoked = 1 WHERE id = ?")->execute([$tokenId]);
        ApiResponse::ok(null, 'Token revoked.');
    });
}
