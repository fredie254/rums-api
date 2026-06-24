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
        $missing = array_filter(['name','email','role','password'], fn($f) => empty($body[$f]));
        if ($missing) ApiResponse::unprocessable('Missing: ' . implode(', ', $missing));

        $exists = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $exists->execute([$body['email']]);
        if ($exists->fetchColumn() > 0) ApiResponse::conflict('Email already registered.');

        $validRoles = ['admin','manager','landlord','tenant','accountant','maintenance','auditor','security'];
        if (!in_array($body['role'], $validRoles, true)) {
            ApiResponse::badRequest('Invalid role. Must be one of: ' . implode(', ', $validRoles));
        }

        $hash = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 10]);

        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO users (name, email, phone, role, password, status) VALUES (?,?,?,?,?,'active')"
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

        ApiResponse::created($responseData, 'User created.');
    });

    $router->get('users/{id}', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $stmt = $db->prepare(
            "SELECT id, name, email, phone, role, status, last_login, created_at FROM users WHERE id = ?"
        );
        $stmt->execute([(int)$id]);
        $u = $stmt->fetch();
        $u ? ApiResponse::ok($u) : ApiResponse::notFound('User not found.');
    });

    $router->patch('users/{id}', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin');
        $body    = Router::body();
        $allowed = array_intersect_key($body, array_flip(['name','phone','role']));

        if (!empty($body['password'])) {
            $allowed['password'] = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 10]);
        }

        if (!$allowed) ApiResponse::badRequest('No valid fields to update.');
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

        // Role or name change — purge cached tokens so permissions update immediately
        ApiAuth::invalidateUserTokens($db, (int)$id);

        ApiResponse::ok(null, 'User updated.');
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
