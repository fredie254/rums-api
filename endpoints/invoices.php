<?php
/**
 * Invoices endpoints
 *
 * POST   /api/v1/invoices/bulk              bulk-generate for active leases
 * POST   /api/v1/invoices/mark-overdue      mark past-grace invoices as overdue
 * GET    /api/v1/invoices                   list (filter status, lease, tenant, period)
 * POST   /api/v1/invoices                   create single invoice
 * GET    /api/v1/invoices/{id}              single invoice + payments
 * PATCH  /api/v1/invoices/{id}              partial update (amounts, due_date, notes)
 * POST   /api/v1/invoices/{id}/void         void an unpaid/overdue invoice
 * POST   /api/v1/invoices/{id}/apply-penalty calculate & apply penalty from lease rules
 */
function registerInvoiceRoutes(Router $router, PDO $db): void
{
    // ── Bulk generate ─────────────────────────────────────────
    $router->post('invoices/bulk', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin', 'manager', 'property_manager', 'landlord', 'owner', 'accountant');
        $body   = Router::body();
        $year   = (int)($body['year']        ?? date('Y'));
        $month  = (int)($body['month']       ?? date('n'));
        $propId = (int)($body['property_id'] ?? 0);

        // Count total active leases for the response summary (2 queries total
        // instead of the previous 1 + N duplicate-check queries).
        $countParams = $propId ? [$propId] : [];
        $countFilter = $propId ? 'AND u.property_id = ?' : '';
        $totalStmt   = $db->prepare(
            "SELECT COUNT(*) FROM leases l JOIN units u ON u.id = l.unit_id
             WHERE l.status = 'active' $countFilter"
        );
        $totalStmt->execute($countParams);
        $totalLeases = (int)$totalStmt->fetchColumn();

        // Fetch only leases that do NOT yet have an invoice for this period.
        // A single NOT EXISTS subquery replaces the previous N per-lease COUNT queries.
        $leasesParams = [$year, $month];
        if ($propId) $leasesParams[] = $propId;
        $leasesStmt = $db->prepare(
            "SELECT l.id, l.tenant_id, l.monthly_rent, l.payment_day, l.grace_period_days,
                    COALESCE(u.utility_charge, 0) AS utility_charge
             FROM leases l JOIN units u ON u.id = l.unit_id
             WHERE l.status = 'active'
               AND NOT EXISTS (
                   SELECT 1 FROM invoices i2
                   WHERE i2.lease_id   = l.id
                     AND i2.period_year  = ?
                     AND i2.period_month = ?
               ) $countFilter"
        );
        $leasesStmt->execute($leasesParams);
        $leases = $leasesStmt->fetchAll();

        $skipped     = $totalLeases - count($leases);
        $created     = 0;
        $invDateStr  = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = (int)date('t', strtotime($invDateStr));

        foreach ($leases as $lease) {
            // due_date = payment_day of the invoice month (clamped to last day)
            $payDay  = min((int)$lease['payment_day'], $daysInMonth);
            $dueDate = sprintf('%04d-%02d-%02d', $year, $month, $payDay);
            $rent    = (float)$lease['monthly_rent'];
            $utility = (float)$lease['utility_charge'];
            $total   = round($rent + $utility, 2);

            // Insert with a random placeholder so the NOT NULL + UNIQUE constraint
            // is satisfied. The real formatted number is written immediately after
            // using the guaranteed-unique auto-increment id — no SELECT MAX()+1 race.
            $placeholder = 'PENDING-' . bin2hex(random_bytes(8));
            $db->prepare(
                "INSERT INTO invoices
                    (lease_id, tenant_id, invoice_number, invoice_date, due_date,
                     rent_amount, utility_amount, total_amount, amount_paid,
                     period_month, period_year, status)
                 VALUES (?,?,?,?,?,?,?,?,0,?,?,'unpaid')"
            )->execute([
                $lease['id'], $lease['tenant_id'], $placeholder,
                $invDateStr, $dueDate, $rent, $utility, $total, $month, $year,
            ]);
            $newId  = (int)$db->lastInsertId();
            $invNum = sprintf('INV-%04d-%06d', $year, $newId);
            $db->prepare("UPDATE invoices SET invoice_number = ? WHERE id = ?")
               ->execute([$invNum, $newId]);
            $created++;
        }

        ApiResponse::ok([
            'created'      => $created,
            'skipped'      => $skipped,
            'total_leases' => $totalLeases,
        ], "Bulk generation: $created created, $skipped skipped.");
    });

    // ── Mark overdue ──────────────────────────────────────────
    // Sets status='overdue' for all unpaid/partial invoices past (due_date + grace_period_days).
    $router->post('invoices/mark-overdue', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin', 'manager', 'property_manager', 'landlord', 'owner', 'accountant');

        $stmt = $db->prepare(
            "UPDATE invoices i
             JOIN leases l ON l.id = i.lease_id
             SET i.status = 'overdue'
             WHERE i.status IN ('unpaid', 'partial')
               AND DATE_ADD(i.due_date, INTERVAL l.grace_period_days DAY) < CURDATE()"
        );
        $stmt->execute();
        $count = $stmt->rowCount();

        ApiResponse::ok(['updated' => $count], "$count invoice(s) marked as overdue.");
    });

    // ── List ──────────────────────────────────────────────────
    $router->get('invoices', function () use ($db) {
        ApiAuth::requireScope($db, 'read:invoices');

        $where    = ['1=1'];
        $params   = [];
        $status   = Router::strParam('status');
        $tenantId = Router::intParam('tenant_id');
        $leaseId  = Router::intParam('lease_id');
        $propId   = Router::intParam('property_id');
        $from     = Router::strParam('date_from');
        $to       = Router::strParam('date_to');
        $periodY  = Router::intParam('period_year');
        $periodM  = Router::intParam('period_month');

        $tid = ApiAuth::tenantId($db);
        if ($tid !== null) $tenantId = $tid;

        // Landlords see only invoices for their properties
        $landlordId = ApiAuth::landlordId($db);

        if ($status === 'outstanding') {
            $where[] = "i.status IN ('unpaid','partial','overdue')";
        } elseif ($status) {
            $where[] = 'i.status = ?'; $params[] = $status;
        }
        if ($tenantId)   { $where[] = 'i.tenant_id = ?';      $params[] = $tenantId; }
        if ($leaseId)    { $where[] = 'i.lease_id = ?';       $params[] = $leaseId; }
        if ($propId)     { $where[] = 'u.property_id = ?';    $params[] = $propId; }
        if ($from)       { $where[] = 'i.invoice_date >= ?';  $params[] = $from; }
        if ($to)         { $where[] = 'i.invoice_date <= ?';  $params[] = $to; }
        if ($periodY)    { $where[] = 'i.period_year = ?';    $params[] = $periodY; }
        if ($periodM)    { $where[] = 'i.period_month = ?';   $params[] = $periodM; }
        if ($landlordId) { $where[] = 'pr.landlord_id = ?';   $params[] = $landlordId; }

        $w   = 'WHERE ' . implode(' AND ', $where);
        $pg  = Router::page();
        $pp  = Router::perPage();
        $off = ($pg - 1) * $pp;

        $cntStmt = $db->prepare(
            "SELECT COUNT(*) FROM invoices i
             LEFT JOIN leases l      ON l.id  = i.lease_id
             LEFT JOIN units u       ON u.id  = l.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id $w"
        );
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT i.*,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                u.unit_number, pr.name AS property_name,
                (i.total_amount - i.amount_paid) AS balance
             FROM invoices i
             LEFT JOIN leases l      ON l.id  = i.lease_id
             LEFT JOIN units u       ON u.id  = l.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             LEFT JOIN tenants t     ON t.id  = i.tenant_id
             $w ORDER BY i.due_date DESC, i.id DESC
             LIMIT ? OFFSET ?"
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

    // ── Create single ─────────────────────────────────────────
    $router->post('invoices', function () use ($db) {
        ApiAuth::requireScope($db, 'write:invoices');
        $body = Router::body();

        // Normalise month/year aliases sent by the utility billing frontend
        if (!isset($body['period_month']) && isset($body['month'])) $body['period_month'] = $body['month'];
        if (!isset($body['period_year'])  && isset($body['year']))  $body['period_year']  = $body['year'];

        // lease_id is required for all invoices
        if (empty($body['lease_id'])) ApiResponse::unprocessable('Missing: lease_id');

        $lid = ApiAuth::landlordId($db);
        if ($lid) {
            $ok = $db->prepare(
                "SELECT 1 FROM leases l JOIN units u ON u.id = l.unit_id
                 JOIN properties pr ON pr.id = u.property_id
                 WHERE l.id = ? AND pr.landlord_id = ? LIMIT 1"
            );
            $ok->execute([(int)$body['lease_id'], $lid]);
            if (!$ok->fetchColumn()) ApiResponse::forbidden('Lease does not belong to your portfolio.');
        }

        $l = $db->prepare("SELECT tenant_id FROM leases WHERE id = ?");
        $l->execute([(int)$body['lease_id']]);
        $lease = $l->fetch();
        if (!$lease) ApiResponse::badRequest('Lease not found.');

        // Compute total_amount from items array if not explicitly provided
        $items       = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
        $itemsTotal  = 0.0;
        foreach ($items as $item) {
            $itemsTotal += (float)($item['unit_price'] ?? 0) * (float)($item['quantity'] ?? 1);
        }

        $totalAmount = isset($body['total_amount']) && $body['total_amount'] !== ''
            ? (float)$body['total_amount']
            : $itemsTotal;

        if ($totalAmount <= 0 && empty($items)) {
            ApiResponse::unprocessable('Provide total_amount or at least one item.');
        }

        // Auto-generate dates if absent
        $invoiceDate = $body['invoice_date'] ?? date('Y-m-d');
        $dueDate     = $body['due_date']     ?? date('Y-m-d', strtotime('+30 days'));

        $allowed = [
            'lease_id'       => (int)$body['lease_id'],
            'invoice_type'   => in_array($body['invoice_type'] ?? '', ['rent','utility','mixed'])
                                    ? $body['invoice_type'] : 'rent',
            'unit_id'        => isset($body['unit_id']) ? (int)$body['unit_id'] : null,
            'invoice_date'   => $invoiceDate,
            'due_date'       => $dueDate,
            'total_amount'   => $totalAmount,
            'rent_amount'    => isset($body['rent_amount'])    ? (float)$body['rent_amount']    : 0,
            'utility_amount' => isset($body['utility_amount']) ? (float)$body['utility_amount'] : ($body['invoice_type'] === 'utility' ? $totalAmount : 0),
            'penalty_amount' => isset($body['penalty_amount']) ? (float)$body['penalty_amount'] : 0,
            'discount_amount'=> isset($body['discount_amount'])? (float)$body['discount_amount']: 0,
            'period_month'   => isset($body['period_month'])   ? (int)$body['period_month']     : null,
            'period_year'    => isset($body['period_year'])    ? (int)$body['period_year']      : null,
            'notes'          => $body['notes'] ?? null,
            'meter_reading_prev' => isset($body['meter_reading_prev']) ? (float)$body['meter_reading_prev'] : null,
            'meter_reading_curr' => isset($body['meter_reading_curr']) ? (float)$body['meter_reading_curr'] : null,
        ];
        // Remove nulls for cleaner INSERT
        $allowed = array_filter($allowed, fn($v) => $v !== null);

        // Placeholder satisfies NOT NULL + UNIQUE; real number written after insert.
        $allowed['invoice_number'] = 'PENDING-' . bin2hex(random_bytes(8));
        $allowed['tenant_id']      = $lease['tenant_id'];
        $allowed['status']         = 'unpaid';
        $allowed['amount_paid']    = 0;

        $cols   = implode(', ', array_keys($allowed));
        $places = implode(', ', array_fill(0, count($allowed), '?'));
        $db->prepare("INSERT INTO invoices ($cols) VALUES ($places)")->execute(array_values($allowed));
        $newId  = (int)$db->lastInsertId();
        $invNum = sprintf('INV-%04d-%06d', (int)date('Y'), $newId);
        $db->prepare("UPDATE invoices SET invoice_number = ? WHERE id = ?")->execute([$invNum, $newId]);

        // Insert line items if provided
        if (!empty($items)) {
            $itemStmt = $db->prepare(
                "INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, item_type)
                 VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($items as $item) {
                $itemStmt->execute([
                    $newId,
                    $item['description'] ?? '',
                    (float)($item['quantity']   ?? 1),
                    (float)($item['unit_price'] ?? 0),
                    $item['item_type'] ?? null,
                ]);
            }
        }

        ApiResponse::created(['id' => $newId, 'invoice_number' => $invNum], 'Invoice created.');
    });

    // ── View single ───────────────────────────────────────────
    $router->get('invoices/{id}', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'read:invoices');
        $lid = ApiAuth::landlordId($db);
        if ($lid) {
            $ok = $db->prepare(
                "SELECT 1 FROM invoices i
                 JOIN leases l ON l.id = i.lease_id
                 JOIN units u ON u.id = l.unit_id
                 JOIN properties pr ON pr.id = u.property_id
                 WHERE i.id = ? AND pr.landlord_id = ? LIMIT 1"
            );
            $ok->execute([(int)$id, $lid]);
            if (!$ok->fetchColumn()) ApiResponse::notFound('Invoice not found.');
        }

        $stmt = $db->prepare(
            "SELECT i.*,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                t.email AS tenant_email, t.phone AS tenant_phone,
                u.unit_number, pr.name AS property_name,
                l.grace_period_days, l.penalty_rate,
                DATEDIFF(CURDATE(), DATE_ADD(i.due_date, INTERVAL l.grace_period_days DAY)) AS days_overdue_net
             FROM invoices i
             LEFT JOIN leases l      ON l.id  = i.lease_id
             LEFT JOIN units u       ON u.id  = l.unit_id
             LEFT JOIN properties pr ON pr.id = u.property_id
             LEFT JOIN tenants t     ON t.id  = i.tenant_id
             WHERE i.id = ?"
        );
        $stmt->execute([(int)$id]);
        $inv = $stmt->fetch();
        if (!$inv) ApiResponse::notFound('Invoice not found.');

        // Decrypt tenant phone
        if (!empty($inv['tenant_phone'])) {
            $inv['tenant_phone'] = Encryptor::decrypt($inv['tenant_phone']);
        }

        $ps = $db->prepare(
            "SELECT id, payment_ref, amount, payment_date, payment_method, payment_type, status
             FROM payments WHERE invoice_id = ? ORDER BY payment_date"
        );
        $ps->execute([(int)$id]);
        $inv['payments'] = $ps->fetchAll();

        ApiResponse::ok($inv);
    });

    // ── Partial update ────────────────────────────────────────
    $router->patch('invoices/{id}', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'write:invoices');
        $lid = ApiAuth::landlordId($db);
        if ($lid) {
            $ok = $db->prepare(
                "SELECT 1 FROM invoices i
                 JOIN leases l ON l.id = i.lease_id
                 JOIN units u ON u.id = l.unit_id
                 JOIN properties pr ON pr.id = u.property_id
                 WHERE i.id = ? AND pr.landlord_id = ? LIMIT 1"
            );
            $ok->execute([(int)$id, $lid]);
            if (!$ok->fetchColumn()) ApiResponse::notFound('Invoice not found.');
        }
        $body    = Router::body();
        $allowed = array_intersect_key($body, array_flip([
            'due_date', 'total_amount', 'rent_amount', 'utility_amount',
            'penalty_amount', 'discount_amount', 'notes', 'status',
        ]));
        if (!$allowed) ApiResponse::badRequest('No valid fields to update.');

        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($allowed)));
        $db->prepare("UPDATE invoices SET $set WHERE id = ?")
           ->execute([...array_values($allowed), (int)$id]);
        ApiResponse::ok(null, 'Invoice updated.');
    });

    // ── Void ─────────────────────────────────────────────────
    $router->post('invoices/{id}/void', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'accountant');
        $db->prepare(
            "UPDATE invoices SET status = 'cancelled'
             WHERE id = ? AND status IN ('unpaid', 'overdue')"
        )->execute([(int)$id]);
        ApiResponse::ok(null, 'Invoice voided.');
    });

    // ── Apply penalty ─────────────────────────────────────────
    // Calculates penalty from lease.penalty_rate applied to rent_amount
    // and applies it if invoice is past (due_date + grace_period_days).
    $router->post('invoices/{id}/apply-penalty', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin', 'manager', 'property_manager', 'landlord', 'owner', 'accountant');

        $stmt = $db->prepare(
            "SELECT i.*, l.penalty_rate, l.grace_period_days
             FROM invoices i
             JOIN leases l ON l.id = i.lease_id
             WHERE i.id = ?"
        );
        $stmt->execute([(int)$id]);
        $inv = $stmt->fetch();

        if (!$inv) ApiResponse::notFound('Invoice not found.');
        if (in_array($inv['status'], ['paid', 'cancelled'])) {
            ApiResponse::unprocessable('Cannot apply penalty to a paid or cancelled invoice.');
        }

        $graceDays   = (int)($inv['grace_period_days'] ?? 0);
        $penaltyRate = (float)($inv['penalty_rate'] ?? 0);
        $dueWithGrace = date('Y-m-d', strtotime($inv['due_date'] . " +{$graceDays} days"));

        if ($dueWithGrace >= date('Y-m-d')) {
            ApiResponse::unprocessable(
                "Invoice is within grace period. Penalty can be applied after $dueWithGrace."
            );
        }
        if ($penaltyRate <= 0) {
            ApiResponse::unprocessable('This lease has no penalty rate configured.');
        }

        $rentAmount    = (float)$inv['rent_amount'];
        $oldPenalty    = (float)($inv['penalty_amount'] ?? 0);
        $newPenalty    = round($rentAmount * $penaltyRate / 100, 2);
        $newTotal      = round(
            (float)$inv['total_amount'] - $oldPenalty + $newPenalty,
            2
        );

        $db->prepare(
            "UPDATE invoices
             SET penalty_amount = ?, total_amount = ?, status = 'overdue'
             WHERE id = ?"
        )->execute([$newPenalty, $newTotal, (int)$id]);

        ApiResponse::ok([
            'old_penalty' => $oldPenalty,
            'new_penalty' => $newPenalty,
            'new_total'   => $newTotal,
        ], "Penalty of {$newPenalty} applied.");
    });
}
