<?php
/**
 * Landlords endpoints
 *
 * GET    /api/v1/landlords              list (paginated, filterable)
 * POST   /api/v1/landlords              create (creates user + landlord in transaction)
 * GET    /api/v1/landlords/{id}         single + properties
 * PUT    /api/v1/landlords/{id}         update
 *
 * Encrypted fields: id_number, kra_pin, bank_account, mpesa_number
 */

/** Fields stored encrypted at rest in the landlords table */
const LANDLORD_ENCRYPTED = ['id_number', 'kra_pin', 'bank_account', 'mpesa_number'];

function landlordDecrypt(array $row): array
{
    return Encryptor::decryptFields($row, LANDLORD_ENCRYPTED);
}

function registerLandlordRoutes(Router $router, PDO $db): void
{
    // ── List ─────────────────────────────────────────────────
    $router->get('landlords', function () use ($db) {
        ApiAuth::requireScope($db, 'read:properties');

        $search  = Router::strParam('search');
        $userId  = Router::intParam('user_id') ?: 0;
        $page    = Router::page();
        $perPage = Router::perPage();

        $where  = ['1=1'];
        $params = [];
        if ($search) {
            // id_number is now encrypted — only search unencrypted user fields
            $where[]  = '(u.name LIKE ? OR u.email LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($userId) {
            $where[]  = 'l.user_id = ?';
            $params[] = $userId;
        }
        $w = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM landlords l JOIN users u ON l.user_id = u.id $w");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        $offset     = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT l.*, u.name, u.email, u.phone, u.status AS user_status,
                   (SELECT COUNT(*) FROM properties WHERE landlord_id = l.id) AS property_count,
                   (SELECT COUNT(*) FROM units un
                    JOIN properties pp ON pp.id = un.property_id
                    WHERE pp.landlord_id = l.id AND un.status = 'occupied') AS occupied_units
            FROM landlords l
            JOIN users u ON l.user_id = u.id
            $w ORDER BY u.name
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([...$params, $perPage, $offset]);
        $rows = array_map('landlordDecrypt', $stmt->fetchAll());

        ApiResponse::paginated([
            'data' => $rows,
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'total_pages'  => $totalPages,
                'from'         => $offset + 1,
                'to'           => min($offset + $perPage, $total),
            ],
        ]);
    });

    // ── Create ────────────────────────────────────────────────
    $router->post('landlords', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');

        $body = Router::body();
        foreach (['name', 'email', 'phone', 'id_number'] as $field) {
            if (empty($body[$field])) ApiResponse::unprocessable("Field '$field' is required.");
        }
        if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            ApiResponse::unprocessable('Invalid email address.');
        }

        $email     = strtolower(trim($body['email']));
        $idNumHash = Encryptor::hash($body['id_number']);

        // Duplicate checks
        $chkEmail = $db->prepare("SELECT id FROM users WHERE email = ?");
        $chkEmail->execute([$email]);
        if ($chkEmail->fetch()) ApiResponse::conflict('Email is already registered.');

        $chkHash = $db->prepare("SELECT id FROM landlords WHERE id_number_hash = ?");
        $chkHash->execute([$idNumHash]);
        if ($chkHash->fetch()) ApiResponse::conflict('ID number is already registered.');

        // Compute default password before encrypting id_number
        $defaultPass = password_hash(
            'Landlord@' . substr(preg_replace('/\D/', '', $body['id_number']), -4),
            PASSWORD_BCRYPT, ['cost' => 10]
        );
        $defaultSuffix = substr($body['id_number'], -4);

        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO users (name, email, phone, password, role) VALUES (?,?,?,?,'landlord')")
               ->execute([trim($body['name']), $email, $body['phone'], $defaultPass]);
            $userId = (int)$db->lastInsertId();

            $db->prepare(
                "INSERT INTO landlords
                    (user_id, id_number, id_number_hash, kra_pin, bank_name, bank_account,
                     bank_branch, mpesa_number, commission_rate, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $userId,
                Encryptor::encrypt($body['id_number']),
                $idNumHash,
                Encryptor::encrypt($body['kra_pin']      ?? null),
                $body['bank_name']    ?? null,
                Encryptor::encrypt($body['bank_account'] ?? null),
                $body['bank_branch']  ?? null,
                Encryptor::encrypt($body['mpesa_number'] ?? null),
                (float)($body['commission_rate'] ?? 0),
                $body['notes'] ?? null,
            ]);
            $landlordId = (int)$db->lastInsertId();
            $db->commit();

            ApiResponse::created(
                ['id' => $landlordId, 'user_id' => $userId],
                'Landlord created. Default password: Landlord@' . $defaultSuffix
            );
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[landlords] create failed: ' . $e->getMessage());
            ApiResponse::serverError('Failed to create landlord.');
        }
    });

    // ── View single ───────────────────────────────────────────
    $router->get('landlords/{id}', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:properties');

        $stmt = $db->prepare("
            SELECT l.*, u.name, u.email, u.phone, u.status AS user_status
            FROM landlords l JOIN users u ON l.user_id = u.id
            WHERE l.id = ?
        ");
        $stmt->execute([(int)$id]);
        $landlord = $stmt->fetch();
        if (!$landlord) ApiResponse::notFound('Landlord not found.');

        $landlord = landlordDecrypt($landlord);

        $propStmt = $db->prepare("
            SELECT p.*,
                   (SELECT COUNT(*) FROM units WHERE property_id = p.id) AS total_units,
                   (SELECT COUNT(*) FROM units WHERE property_id = p.id AND status = 'occupied') AS occupied_units
            FROM properties p
            WHERE p.landlord_id = ?
            ORDER BY p.name
        ");
        $propStmt->execute([(int)$id]);
        $landlord['properties'] = $propStmt->fetchAll();

        ApiResponse::ok($landlord);
    });

    // ── Update ────────────────────────────────────────────────
    $router->put('landlords/{id}', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');

        $stmt = $db->prepare("SELECT l.*, u.id AS u_id FROM landlords l JOIN users u ON l.user_id = u.id WHERE l.id = ?");
        $stmt->execute([(int)$id]);
        $landlord = $stmt->fetch();
        if (!$landlord) ApiResponse::notFound('Landlord not found.');

        $landlord = landlordDecrypt($landlord); // decrypt current values for fallback
        $body     = Router::body();

        // If id_number is changing, validate uniqueness and compute new hash
        $idNumHash = null;
        if (!empty($body['id_number'])) {
            $idNumHash = Encryptor::hash($body['id_number']);
            $conflict  = $db->prepare("SELECT id FROM landlords WHERE id_number_hash = ? AND id != ?");
            $conflict->execute([$idNumHash, (int)$id]);
            if ($conflict->fetch()) ApiResponse::conflict('ID number already registered to another landlord.');
        }

        $db->beginTransaction();
        try {
            // Update users row (plaintext fields)
            if (!empty($body['name']) || !empty($body['phone']) || !empty($body['status'])) {
                $db->prepare("UPDATE users SET name=?, phone=?, status=? WHERE id=?")
                   ->execute([
                       $body['name']   ?? $landlord['name'],
                       $body['phone']  ?? $landlord['phone'],
                       $body['status'] ?? $landlord['user_status'],
                       $landlord['u_id'],
                   ]);
            }

            // Encrypt sensitive fields; fall back to existing encrypted value if not changing
            $newIdNumber   = !empty($body['id_number'])   ? Encryptor::encrypt($body['id_number'])   : Encryptor::encrypt($landlord['id_number']);
            $newKraPin     = array_key_exists('kra_pin', $body)      ? Encryptor::encrypt($body['kra_pin'])      : Encryptor::encrypt($landlord['kra_pin']);
            $newBankAcct   = array_key_exists('bank_account', $body)  ? Encryptor::encrypt($body['bank_account'])  : Encryptor::encrypt($landlord['bank_account']);
            $newMpesa      = array_key_exists('mpesa_number', $body)   ? Encryptor::encrypt($body['mpesa_number'])   : Encryptor::encrypt($landlord['mpesa_number']);

            $db->prepare(
                "UPDATE landlords SET
                    id_number=?, id_number_hash=?, kra_pin=?, bank_name=?, bank_account=?,
                    bank_branch=?, mpesa_number=?, commission_rate=?, notes=?
                 WHERE id=?"
            )->execute([
                $newIdNumber,
                $idNumHash ?? Encryptor::hash($landlord['id_number']),
                $newKraPin,
                $body['bank_name']       ?? $landlord['bank_name'],
                $newBankAcct,
                $body['bank_branch']     ?? $landlord['bank_branch'],
                $newMpesa,
                $body['commission_rate'] ?? $landlord['commission_rate'],
                $body['notes']           ?? $landlord['notes'],
                (int)$id,
            ]);

            $db->commit();
            ApiResponse::ok(null, 'Landlord updated.');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[landlords] update failed: ' . $e->getMessage());
            ApiResponse::serverError('Failed to update landlord.');
        }
    });
}
