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

        $countParams = $propId ? [$propId] : [];
        $countFilter = $propId ? 'AND u.property_id = ?' : '';
        $totalStmt   = $db->prepare(
            "SELECT COUNT(*) FROM leases l JOIN units u ON u.id = l.unit_id
             WHERE l.status = 'active' $countFilter"
        );
        $totalStmt->execute($countParams);
        $totalLeases = (int)$totalStmt->fetchColumn();

        $leasesParams = [$year, $month];
        if ($propId) $leasesParams[] = $propId;
        $leasesStmt = $db->prepare(
            "SELECT l.id AS lease_id, l.tenant_id, l.monthly_rent, l.payment_day,
                    u.id AS unit_id,
                    COALESCE(u.water_rate, 0)   AS water_rate,
                    COALESCE(u.garbage_fee, 0)  AS garbage_fee,
                    COALESCE(u.service_fee, 0)  AS service_fee,
                    COALESCE(u.utility_charge, 0) AS other_utility
             FROM leases l
             JOIN units u ON u.id = l.unit_id
             WHERE l.status = 'active'
               AND NOT EXISTS (
                   SELECT 1 FROM invoices i2
                   WHERE i2.lease_id    = l.id
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
        $periodEnd   = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
        $monthName   = date('F', mktime(0, 0, 0, $month, 1, $year));

        // Prepared statements reused per lease
        $waterStmt = $db->prepare(
            "SELECT wr.reading_value AS curr_val,
                    (SELECT prev.reading_value FROM water_readings prev
                     WHERE prev.unit_id = wr.unit_id
                       AND (prev.reading_date < wr.reading_date
                            OR (prev.reading_date = wr.reading_date AND prev.id < wr.id))
                     ORDER BY prev.reading_date DESC, prev.id DESC LIMIT 1) AS prev_val
             FROM water_readings wr
             WHERE wr.unit_id = ?
               AND wr.reading_date <= ?
               AND wr.is_initial = 0
             ORDER BY wr.reading_date DESC, wr.id DESC
             LIMIT 1"
        );
        $itemStmt = $db->prepare(
            "INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, item_type)
             VALUES (?, ?, ?, ?, ?)"
        );

        foreach ($leases as $lease) {
            $payDay  = min((int)$lease['payment_day'], $daysInMonth);
            $dueDate = sprintf('%04d-%02d-%02d', $year, $month, $payDay);
            $rent       = (float)$lease['monthly_rent'];
            $waterRate  = (float)$lease['water_rate'];
            $garbageFee = (float)$lease['garbage_fee'];
            $serviceFee = (float)$lease['service_fee'];
            $otherUtil  = (float)$lease['other_utility'];

            // Water: look for the most recent uninvoiced reading in/before this period
            $waterAmount = 0.0;
            $prevReading = null;
            $currReading = null;
            if ($waterRate > 0) {
                $waterStmt->execute([$lease['unit_id'], $periodEnd]);
                $wr = $waterStmt->fetch();
                if ($wr && $wr['prev_val'] !== null) {
                    $currReading = (float)$wr['curr_val'];
                    $prevReading = (float)$wr['prev_val'];
                    $consumption = max(0, $currReading - $prevReading);
                    $waterAmount = round($consumption * $waterRate, 2);
                }
            }

            $utilityTotal = round($waterAmount + $garbageFee + $serviceFee + $otherUtil, 2);
            $total        = round($rent + $utilityTotal, 2);
            $invoiceType  = $utilityTotal > 0 ? 'mixed' : 'rent';

            $placeholder = 'PENDING-' . bin2hex(random_bytes(8));
            $db->prepare(
                "INSERT INTO invoices
                    (lease_id, invoice_type, unit_id, tenant_id, invoice_number,
                     invoice_date, due_date, rent_amount, utility_amount, total_amount,
                     amount_paid, period_month, period_year,
                     meter_reading_prev, meter_reading_curr, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,'unpaid')"
            )->execute([
                $lease['lease_id'], $invoiceType, $lease['unit_id'], $lease['tenant_id'],
                $placeholder, $invDateStr, $dueDate,
                $rent, $utilityTotal, $total, $month, $year,
                $prevReading, $currReading,
            ]);
            $newId  = (int)$db->lastInsertId();
            $invNum = sprintf('INV-%04d-%06d', $year, $newId);
            $db->prepare("UPDATE invoices SET invoice_number = ? WHERE id = ?")
               ->execute([$invNum, $newId]);

            // Line items
            $itemStmt->execute([$newId, "Monthly Rent — $monthName $year", 1, $rent, 'rent']);
            if ($waterAmount > 0) {
                $cons = round($currReading - $prevReading, 4);
                $itemStmt->execute([$newId, "Water — {$cons} m³ @ KES {$waterRate}/m³", $cons, $waterRate, 'water']);
            }
            if ($garbageFee > 0) {
                $itemStmt->execute([$newId, 'Garbage Collection Fee', 1, $garbageFee, 'garbage']);
            }
            if ($serviceFee > 0) {
                $itemStmt->execute([$newId, 'Service / Maintenance Fee', 1, $serviceFee, 'service']);
            }
            if ($otherUtil > 0) {
                $itemStmt->execute([$newId, 'Utilities', 1, $otherUtil, 'utility']);
            }
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

        // ── Apply any pre-payments (orphan M-Pesa payments with no invoice) ──
        $orphanRow = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0) AS total_paid
             FROM payments
             WHERE lease_id = ? AND invoice_id IS NULL AND status = 'completed'"
        );
        $orphanRow->execute([(int)$body['lease_id']]);
        $totalPrePaid = (float)$orphanRow->fetchColumn();

        if ($totalPrePaid > 0) {
            // Link all orphan payments to this new invoice
            $db->prepare(
                "UPDATE payments SET invoice_id = ?
                 WHERE lease_id = ? AND invoice_id IS NULL AND status = 'completed'"
            )->execute([$newId, (int)$body['lease_id']]);

            // Recalculate status
            $invStatus = match (true) {
                $totalPrePaid >= $totalAmount => 'paid',
                default                       => 'partial',
            };
            $db->prepare("UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?")
               ->execute([$totalPrePaid, $invStatus, $newId]);

            // Fetch tenant details for notification
            $tenantRow = $db->prepare(
                "SELECT t.user_id, t.first_name, t.last_name, u.unit_number
                 FROM leases l
                 JOIN tenants t ON t.id = l.tenant_id
                 JOIN units u ON u.id = l.unit_id
                 WHERE l.id = ? LIMIT 1"
            );
            $tenantRow->execute([(int)$body['lease_id']]);
            $tenant = $tenantRow->fetch();

            if ($tenant && $tenant['user_id']) {
                $name    = trim($tenant['first_name'] . ' ' . $tenant['last_name']);
                $balance = round($totalAmount - $totalPrePaid, 2);
                $excess  = round($totalPrePaid - $totalAmount, 2);

                if ($invStatus === 'paid') {
                    $notifMsg = sprintf(
                        "Dear %s, your new invoice (%s) for Unit %s of KES %s has been automatically settled by your prior payment. %sThank you!",
                        $name, $invNum, $tenant['unit_number'],
                        number_format($totalAmount, 2),
                        $excess > 0
                            ? sprintf("Excess credit of KES %s will be applied to your next bill. ", number_format($excess, 2))
                            : ''
                    );
                } else {
                    $notifMsg = sprintf(
                        "Dear %s, a new invoice (%s) of KES %s has been raised for Unit %s. Your prior payment of KES %s has been applied. Balance due: KES %s.",
                        $name, $invNum,
                        number_format($totalAmount, 2),
                        $tenant['unit_number'],
                        number_format($totalPrePaid, 2),
                        number_format($balance, 2)
                    );
                }

                try {
                    $db->prepare(
                        "INSERT INTO notifications (user_id, title, message, type, created_at)
                         VALUES (?, ?, ?, 'payment', NOW())"
                    )->execute([$tenant['user_id'], 'Invoice Auto-Settled', $notifMsg]);
                } catch (Throwable $e) {
                    error_log('[Invoice Notification Error] ' . $e->getMessage());
                }
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

        $is = $db->prepare(
            "SELECT id, description, quantity, unit_price, subtotal, item_type
             FROM invoice_items WHERE invoice_id = ? ORDER BY id"
        );
        $is->execute([(int)$id]);
        $items = $is->fetchAll();

        // Cast stored numeric columns so JS receives numbers, not strings
        foreach ($items as &$it) {
            $it['quantity']   = $it['quantity']   !== null ? (float)$it['quantity']   : 1;
            $it['unit_price'] = $it['unit_price'] !== null ? (float)$it['unit_price'] : null;
            $it['subtotal']   = $it['subtotal']   !== null ? (float)$it['subtotal']   : null;
        }
        unset($it);

        // For old invoices that predate invoice_items, synthesize line items
        // from the rent_amount / utility_amount stored on the invoice itself.
        if (empty($items)) {
            $rentAmt    = (float)($inv['rent_amount']    ?? 0);
            $utilityAmt = (float)($inv['utility_amount'] ?? 0);
            $unitLabel  = !empty($inv['unit_number']) ? ' — Unit ' . $inv['unit_number'] : '';
            if ($rentAmt > 0) {
                $items[] = [
                    'id'          => 0,
                    'description' => 'Monthly Rent' . $unitLabel,
                    'quantity'    => 1,
                    'unit_price'  => $rentAmt,
                    'subtotal'    => $rentAmt,
                    'item_type'   => 'rent',
                ];
            }
            if ($utilityAmt > 0) {
                $items[] = [
                    'id'          => 0,
                    'description' => 'Utilities & Fees',
                    'quantity'    => 1,
                    'unit_price'  => $utilityAmt,
                    'subtotal'    => $utilityAmt,
                    'item_type'   => 'utility',
                ];
            }
            if (empty($items)) {
                // absolute fallback — invoice has no stored breakdown at all
                $items[] = [
                    'id'          => 0,
                    'description' => 'Monthly Rent' . $unitLabel,
                    'quantity'    => 1,
                    'unit_price'  => (float)($inv['total_amount'] ?? 0),
                    'subtotal'    => (float)($inv['total_amount'] ?? 0),
                    'item_type'   => 'rent',
                ];
            }
        }

        $inv['items']    = $items;
        // Compute subtotal as sum of line items (pre-tax, pre-discount)
        $inv['subtotal'] = (float)array_sum(array_column($items, 'subtotal'));

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

    // ── Delete ───────────────────────────────────────────────
    $router->delete('invoices/{id}', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin', 'manager', 'property_manager');
        $stmt = $db->prepare("SELECT id FROM invoices WHERE id = ?");
        $stmt->execute([(int)$id]);
        if (!$stmt->fetch()) ApiResponse::notFound('Invoice not found.');
        $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([(int)$id]);
        $db->prepare("DELETE FROM invoices WHERE id = ?")->execute([(int)$id]);
        ApiResponse::ok(null, 'Invoice deleted.');
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
