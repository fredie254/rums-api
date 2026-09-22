<?php
/**
 * M-Pesa endpoints — multi-landlord architecture
 *
 * Every landlord has their own shortcode (paybill/till).
 * A single shared callback/validate/confirm URL receives all Safaricom webhooks.
 * The BusinessShortCode field in each payload is used to identify which landlord's
 * config to use and which tenant's payment to record.
 *
 * PUBLIC (no JWT required — Safaricom calls these):
 *   POST /api/v1/mpesa/callback   STK push result webhook
 *   POST /api/v1/mpesa/validate   C2B pre-validation (unit_number lookup)
 *   POST /api/v1/mpesa/confirm    C2B payment confirmation (record payment)
 *
 * PROTECTED (JWT required):
 *   POST /api/v1/mpesa/stk-push   Initiate STK push for a lease
 *   POST /api/v1/mpesa/stk-query  Query STK push status
 *
 * SUPER ADMIN (JWT + admin role):
 *   GET    /api/v1/mpesa/configs          List all landlord M-Pesa configs
 *   POST   /api/v1/mpesa/configs          Create config
 *   PUT    /api/v1/mpesa/configs/{id}     Update config
 *   DELETE /api/v1/mpesa/configs/{id}     Delete config
 *   POST   /api/v1/mpesa/configs/{id}/register-urls
 */

require_once __DIR__ . '/../services/MpesaService.php';

// ────────────────────────────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────────────────────────────

/**
 * Read the legacy global M-Pesa settings (fallback when no per-landlord config).
 */
function mpesaSettings(PDO $db): array
{
    $cacheKey = 'rums_mpesa_settings';
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cacheKey, $hit);
        if ($hit) return $cached;
    }

    $rows = $db->query(
        "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'mpesa_%'"
    )->fetchAll();
    $cfg = array_column($rows, 'setting_value', 'setting_key');

    $settings = [
        'consumer_key'    => $cfg['mpesa_consumer_key']    ?? '',
        'consumer_secret' => $cfg['mpesa_consumer_secret'] ?? '',
        'shortcode'       => $cfg['mpesa_shortcode']       ?? '',
        'passkey'         => $cfg['mpesa_passkey']         ?? '',
        'env'             => $cfg['mpesa_env']             ?? 'sandbox',
        'callback_url'    => $cfg['mpesa_callback_url']    ?? '',
    ];

    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, $settings, 300);
    }

    return $settings;
}

/**
 * Fetch per-landlord M-Pesa config by Safaricom shortcode.
 * Returns null if this shortcode is not registered in mpesa_configs.
 */
function mpesaConfigByShortcode(PDO $db, string $shortcode): ?array
{
    $stmt = $db->prepare(
        "SELECT mc.*, l.id AS landlord_id
         FROM mpesa_configs mc
         JOIN landlords l ON l.id = mc.landlord_id
         WHERE mc.shortcode = ? AND mc.is_active = 1
         LIMIT 1"
    );
    $stmt->execute([$shortcode]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Fetch per-landlord M-Pesa config by landlord_id.
 */
function mpesaConfigByLandlord(PDO $db, int $landlordId): ?array
{
    $stmt = $db->prepare(
        "SELECT * FROM mpesa_configs WHERE landlord_id = ? AND is_active = 1 LIMIT 1"
    );
    $stmt->execute([$landlordId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Build a MpesaService from a mpesa_configs row.
 * The callback URL is always the central endpoint; Safaricom uses BusinessShortCode
 * in the payload to let us route it back to the right landlord.
 */
function mpesaServiceFromConfig(array $cfg): MpesaService
{
    $baseUrl = rtrim(env('APP_URL', ''), '/');
    return new MpesaService([
        'consumer_key'    => $cfg['consumer_key'],
        'consumer_secret' => $cfg['consumer_secret'],
        'shortcode'       => $cfg['shortcode'],
        'passkey'         => $cfg['passkey'],
        'env'             => $cfg['environment'] === 'production' ? 'live' : 'sandbox',
        'callback_url'    => $baseUrl . '/mpesa/callback',
    ]);
}

/**
 * True if the request originates from Safaricom's documented IP ranges.
 * Skipped in non-production environments to allow local testing.
 */
function isSafaricomIp(): bool
{
    if (env('APP_ENV', 'production') !== 'production') return true;

    $safaricomRanges = ['196.201.214.0/24', '196.201.213.0/24'];
    $remoteIp        = $_SERVER['REMOTE_ADDR'] ?? '';
    foreach ($safaricomRanges as $cidr) {
        [$net, $prefix] = explode('/', $cidr);
        $mask = -1 << (32 - (int)$prefix);
        if ((ip2long($remoteIp) & $mask) === (ip2long($net) & $mask)) return true;
    }
    return false;
}

/**
 * Send an in-app notification to a user and (if configured) an SMS.
 * Silently swallows errors so a notification failure never breaks a payment.
 */
function sendPaymentNotification(PDO $db, int $userId, string $title, string $message): void
{
    try {
        $db->prepare(
            "INSERT INTO notifications (user_id, title, message, type, created_at)
             VALUES (?, ?, ?, 'payment', NOW())"
        )->execute([$userId, $title, $message]);
    } catch (Throwable $e) {
        error_log('[M-Pesa Notification Error] ' . $e->getMessage());
    }
}

/**
 * Format a C2B payment confirmation message.
 * If amount paid > invoice total → shows excess (credit).
 * If amount paid < invoice total → shows balance remaining.
 */
function buildPaymentMessage(string $name, float $paid, float $invoiceTotal, string $unitNumber, string $receipt): string
{
    $excess  = round($paid - $invoiceTotal, 2);
    $balance = round($invoiceTotal - $paid, 2);

    $base = sprintf(
        "Dear %s, payment of KES %s received for unit %s. Mpesa Ref: %s.",
        $name,
        number_format($paid, 2),
        $unitNumber,
        $receipt
    );

    if ($invoiceTotal <= 0) {
        return $base . " No outstanding invoice matched.";
    }

    if ($excess > 0) {
        return $base . sprintf(" Your bill of KES %s is fully settled. Excess credit of KES %s will be applied to your next invoice.",
            number_format($invoiceTotal, 2),
            number_format($excess, 2)
        );
    }

    if ($balance > 0) {
        return $base . sprintf(" KES %s remains outstanding on this invoice.",
            number_format($balance, 2)
        );
    }

    return $base . " Your invoice is fully paid. Thank you!";
}


// ────────────────────────────────────────────────────────────────────────────
// PUBLIC routes — registered BEFORE auth middleware
// ────────────────────────────────────────────────────────────────────────────
function registerMpesaPublicRoutes(Router $router, PDO $db): void
{
    // ── STK Push callback ────────────────────────────────────────────────────
    $router->post('mpesa/callback', function () use ($db) {
        if (!isSafaricomIp()) {
            error_log('[M-Pesa Callback] Rejected — unauthorized source IP: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
            http_response_code(403);
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Unauthorized source']);
            exit;
        }

        $raw = file_get_contents('php://input');
        error_log('[M-Pesa STK Callback] ' . date('Y-m-d H:i:s') . ' | ' . $raw);

        if (empty($raw)) { echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Empty body']); exit; }
        $data = json_decode($raw, true);
        if (!$data)       { echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON']); exit; }

        try {
            $body     = $data['Body']['stkCallback'] ?? [];
            $checkout = $body['CheckoutRequestID'] ?? '';
            $result   = (int)($body['ResultCode'] ?? 1);
            $desc     = $body['ResultDesc'] ?? '';

            $txStmt = $db->prepare("SELECT * FROM mpesa_transactions WHERE checkout_request_id = ?");
            $txStmt->execute([$checkout]);
            $transaction = $txStmt->fetch();

            if ($transaction) {
                $db->beginTransaction();
                try {
                    if ($result === 0) {
                        $items   = $body['CallbackMetadata']['Item'] ?? [];
                        $meta    = array_column($items, 'Value', 'Name');
                        $receipt = $meta['MpesaReceiptNumber'] ?? '';
                        $amount  = (float)($meta['Amount'] ?? $transaction['amount']);

                        $db->prepare(
                            "UPDATE mpesa_transactions
                             SET status='completed', mpesa_receipt=?, result_code=0, result_desc=?, raw_response=?
                             WHERE checkout_request_id=?"
                        )->execute([$receipt, $desc, $raw, $checkout]);

                        $invoiceTotal = 0.0;
                        $invoiceId    = null;

                        if ($transaction['payment_id']) {
                            $db->prepare(
                                "UPDATE payments SET status='completed', mpesa_receipt=?, amount=? WHERE id=?"
                            )->execute([$receipt, $amount, $transaction['payment_id']]);

                            $pStmt = $db->prepare("SELECT invoice_id FROM payments WHERE id=?");
                            $pStmt->execute([$transaction['payment_id']]);
                            $pay = $pStmt->fetch();

                            if ($pay && $pay['invoice_id']) {
                                $invoiceId = $pay['invoice_id'];
                                $invRow = $db->prepare("SELECT total_amount FROM invoices WHERE id=?");
                                $invRow->execute([$invoiceId]);
                                $invoiceTotal = (float)($invRow->fetchColumn() ?: 0);

                                $sumRow = $db->prepare(
                                    "SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND status='completed'"
                                );
                                $sumRow->execute([$invoiceId]);
                                $totalPaid = (float)$sumRow->fetchColumn();

                                $invStatus = match (true) {
                                    $totalPaid <= 0         => 'unpaid',
                                    $totalPaid >= $invoiceTotal => 'paid',
                                    default                 => 'partial',
                                };
                                $db->prepare("UPDATE invoices SET amount_paid=?, status=? WHERE id=?")
                                   ->execute([$totalPaid, $invStatus, $invoiceId]);
                            }
                        }

                        // Notify the tenant
                        $tRow = $db->prepare(
                            "SELECT t.first_name, t.last_name, t.user_id, u.unit_number
                             FROM leases l
                             JOIN tenants t ON t.id = l.tenant_id
                             JOIN units u ON u.id = l.unit_id
                             WHERE l.id = ? LIMIT 1"
                        );
                        $tRow->execute([$transaction['lease_id'] ?? 0]);
                        $tenant = $tRow->fetch();

                        if ($tenant && $tenant['user_id']) {
                            $name = trim($tenant['first_name'] . ' ' . $tenant['last_name']);
                            $msg  = buildPaymentMessage($name, $amount, $invoiceTotal, $tenant['unit_number'], $receipt);
                            sendPaymentNotification($db, (int)$tenant['user_id'], 'Payment Received', $msg);
                        }
                    } else {
                        $db->prepare(
                            "UPDATE mpesa_transactions
                             SET status='failed', result_code=?, result_desc=?, raw_response=?
                             WHERE checkout_request_id=?"
                        )->execute([$result, $desc, $raw, $checkout]);

                        if ($transaction['payment_id']) {
                            $db->prepare("UPDATE payments SET status='failed' WHERE id=?")
                               ->execute([$transaction['payment_id']]);
                        }
                    }
                    $db->commit();
                } catch (Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }
            }
        } catch (Throwable $e) {
            error_log('[M-Pesa Callback Error] ' . $e->getMessage());
        }

        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        exit;
    });

    // ── C2B Validation ───────────────────────────────────────────────────────
    // Safaricom calls this before confirming a paybill payment.
    // We verify the account number (unit_number) belongs to an active lease
    // under the landlord identified by BusinessShortCode.
    $router->post('mpesa/validate', function () use ($db) {
        if (!isSafaricomIp()) {
            http_response_code(403);
            echo json_encode(['ResultCode' => 'C2B00016', 'ResultDesc' => 'Unauthorized source']);
            exit;
        }

        $raw = file_get_contents('php://input');
        error_log('[M-Pesa C2B Validate] ' . date('Y-m-d H:i:s') . ' | ' . $raw);

        $data      = json_decode($raw, true) ?? [];
        $shortcode = trim($data['BusinessShortCode'] ?? '');
        $unitNo    = strtoupper(trim($data['BillRefNumber'] ?? ''));

        if (!$shortcode || !$unitNo) {
            echo json_encode(['ResultCode' => 'C2B00011', 'ResultDesc' => 'Missing shortcode or account reference']);
            exit;
        }

        $cfg = mpesaConfigByShortcode($db, $shortcode);
        if (!$cfg) {
            error_log("[M-Pesa Validate] Unknown shortcode: $shortcode");
            echo json_encode(['ResultCode' => 'C2B00011', 'ResultDesc' => 'Unrecognised shortcode']);
            exit;
        }

        // Find unit with this number belonging to this landlord, with an active lease
        $stmt = $db->prepare(
            "SELECT u.id FROM units u
             JOIN properties pr ON pr.id = u.property_id
             JOIN leases l ON l.unit_id = u.id AND l.status = 'active'
             WHERE UPPER(u.unit_number) = ?
               AND pr.landlord_id = ?
             LIMIT 1"
        );
        $stmt->execute([$unitNo, $cfg['landlord_id']]);
        $unit = $stmt->fetchColumn();

        if (!$unit) {
            error_log("[M-Pesa Validate] No active lease for unit '$unitNo' under shortcode $shortcode");
            echo json_encode(['ResultCode' => 'C2B00011', 'ResultDesc' => 'Unit not found or no active tenancy']);
            exit;
        }

        echo json_encode(['ResultCode' => '0', 'ResultDesc' => 'Accepted']);
        exit;
    });

    // ── C2B Confirmation ─────────────────────────────────────────────────────
    // Safaricom calls this after a payment clears (regardless of validation result
    // when ResponseType = Completed). We record the payment idempotently.
    $router->post('mpesa/confirm', function () use ($db) {
        if (!isSafaricomIp()) {
            http_response_code(403);
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Unauthorized source']);
            exit;
        }

        $raw = file_get_contents('php://input');
        error_log('[M-Pesa C2B Confirm] ' . date('Y-m-d H:i:s') . ' | ' . $raw);

        $data      = json_decode($raw, true) ?? [];
        $shortcode = trim($data['BusinessShortCode'] ?? '');
        $unitNo    = strtoupper(trim($data['BillRefNumber'] ?? ''));
        $receipt   = trim($data['TransID'] ?? '');
        $amount    = (float)($data['TransAmount'] ?? 0);
        $phone     = trim($data['MSISDN'] ?? '');
        $firstName = trim($data['FirstName'] ?? '');
        $lastName  = trim($data['LastName'] ?? '');

        try {
            // Idempotency: if we already recorded this receipt, just ack.
            $dup = $db->prepare("SELECT id FROM payments WHERE mpesa_receipt = ? LIMIT 1");
            $dup->execute([$receipt]);
            if ($dup->fetchColumn()) {
                echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Already recorded']);
                exit;
            }

            $cfg = mpesaConfigByShortcode($db, $shortcode);
            if (!$cfg) {
                error_log("[M-Pesa Confirm] Unknown shortcode: $shortcode");
                // Always ack — we cannot reject a confirmed payment
                echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
                exit;
            }

            // Find unit → lease → tenant
            $stmt = $db->prepare(
                "SELECT u.id AS unit_id, u.unit_number,
                        l.id AS lease_id, l.tenant_id,
                        t.first_name, t.last_name, t.user_id
                 FROM units u
                 JOIN properties pr ON pr.id = u.property_id
                 JOIN leases l ON l.unit_id = u.id AND l.status = 'active'
                 JOIN tenants t ON t.id = l.tenant_id
                 WHERE UPPER(u.unit_number) = ?
                   AND pr.landlord_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$unitNo, $cfg['landlord_id']]);
            $lease = $stmt->fetch();

            if (!$lease) {
                error_log("[M-Pesa Confirm] No active lease for unit '$unitNo' shortcode $shortcode — recording orphan payment");
                // Still ack but don't record; Safaricom will not resend
                echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
                exit;
            }

            $db->beginTransaction();
            try {
                // Find oldest unpaid/partial invoice for this lease to apply against
                $invStmt = $db->prepare(
                    "SELECT id, total_amount, amount_paid FROM invoices
                     WHERE lease_id = ? AND status IN ('unpaid','partial','overdue')
                     ORDER BY due_date ASC LIMIT 1"
                );
                $invStmt->execute([$lease['lease_id']]);
                $invoice      = $invStmt->fetch();
                $invoiceId    = $invoice ? (int)$invoice['id'] : null;
                $invoiceTotal = $invoice ? (float)$invoice['total_amount'] : 0.0;

                $ref = 'C2B-' . strtoupper(bin2hex(random_bytes(4)));
                $db->prepare(
                    "INSERT INTO payments
                        (payment_ref, invoice_id, lease_id, tenant_id, unit_id,
                         amount, payment_date, payment_type, payment_method,
                         period_month, period_year, status, mpesa_receipt, notes)
                     VALUES (?,?,?,?,?, ?,CURDATE(),'rent','mpesa',
                             MONTH(CURDATE()),YEAR(CURDATE()),'completed',?,?)"
                )->execute([
                    $ref, $invoiceId, $lease['lease_id'], $lease['tenant_id'], $lease['unit_id'],
                    $amount, $receipt,
                    "C2B paybill payment from $phone (" . trim("$firstName $lastName") . ")",
                ]);
                $payId = (int)$db->lastInsertId();

                // Update invoice amount_paid and status
                if ($invoiceId) {
                    $sumRow = $db->prepare(
                        "SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND status='completed'"
                    );
                    $sumRow->execute([$invoiceId]);
                    $totalPaid = (float)$sumRow->fetchColumn();

                    $invStatus = match (true) {
                        $totalPaid <= 0             => 'unpaid',
                        $totalPaid >= $invoiceTotal => 'paid',
                        default                     => 'partial',
                    };
                    $db->prepare("UPDATE invoices SET amount_paid=?, status=? WHERE id=?")
                       ->execute([$totalPaid, $invStatus, $invoiceId]);
                }

                $db->commit();

                // Notify tenant
                if ($lease['user_id']) {
                    $name = trim($lease['first_name'] . ' ' . $lease['last_name']);
                    $msg  = buildPaymentMessage($name, $amount, $invoiceTotal, $lease['unit_number'], $receipt);
                    sendPaymentNotification($db, (int)$lease['user_id'], 'Payment Received', $msg);
                }
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            error_log('[M-Pesa Confirm Error] ' . $e->getMessage());
        }

        // Always ack — Safaricom retries on non-200 responses.
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);
        exit;
    });
}


// ────────────────────────────────────────────────────────────────────────────
// PROTECTED routes — registered AFTER auth middleware
// ────────────────────────────────────────────────────────────────────────────
function registerMpesaProtectedRoutes(Router $router, PDO $db): void
{
    // ── STK Push ─────────────────────────────────────────────────────────────
    $router->post('mpesa/stk-push', function () use ($db) {
        ApiAuth::requireScope($db, 'write:payments');

        $body      = Router::body();
        $phone     = trim($body['phone']      ?? '');
        $amount    = (float)($body['amount']   ?? 0);
        $leaseId   = (int)($body['lease_id']   ?? 0);
        $invoiceId = (int)($body['invoice_id'] ?? 0) ?: null;

        if (!$phone || $amount <= 0 || !$leaseId) {
            ApiResponse::unprocessable('phone, amount, and lease_id are required.');
        }

        $lsStmt = $db->prepare(
            "SELECT l.*, u.unit_number, u.property_id
             FROM leases l
             JOIN units u ON l.unit_id = u.id
             WHERE l.id = ? AND l.status = 'active'"
        );
        $lsStmt->execute([$leaseId]);
        $lease = $lsStmt->fetch();
        if (!$lease) ApiResponse::unprocessable('Lease not found or not active.');

        // Resolve per-landlord M-Pesa config via unit → property → landlord
        $cfgStmt = $db->prepare(
            "SELECT mc.* FROM mpesa_configs mc
             JOIN properties pr ON pr.landlord_id = mc.landlord_id
             WHERE pr.id = ? AND mc.is_active = 1
             LIMIT 1"
        );
        $cfgStmt->execute([$lease['property_id']]);
        $cfgRow = $cfgStmt->fetch();

        try {
            $mpesa  = $cfgRow ? mpesaServiceFromConfig($cfgRow) : new MpesaService(mpesaSettings($db));
            $phoneF = MpesaService::formatPhone($phone);
            $result = $mpesa->stkPush($phoneF, $amount, $lease['unit_number'], 'Rent - ' . $lease['unit_number']);

            if (empty($result['CheckoutRequestID'])) {
                $msg = $result['errorMessage'] ?? ($result['ResponseDescription'] ?? 'STK Push failed.');
                ApiResponse::unprocessable($msg);
            }

            $ref = 'PAY-' . strtoupper(bin2hex(random_bytes(4)));
            $db->prepare(
                "INSERT INTO payments
                    (payment_ref, invoice_id, lease_id, tenant_id, unit_id, amount,
                     payment_date, payment_type, payment_method, period_month, period_year, status, notes)
                 VALUES (?,?,?,?,?,?,CURDATE(),'rent','mpesa',MONTH(CURDATE()),YEAR(CURDATE()),'pending',?)"
            )->execute([$ref, $invoiceId, $leaseId, $lease['tenant_id'], $lease['unit_id'], $amount, 'STK Push initiated']);
            $payId = (int)$db->lastInsertId();

            $db->prepare(
                "INSERT INTO mpesa_transactions
                    (payment_id, lease_id, checkout_request_id, merchant_request_id, phone, amount, account_reference, status)
                 VALUES (?,?,?,?,?,?,?,'pending')"
            )->execute([
                $payId,
                $leaseId,
                $result['CheckoutRequestID'],
                $result['MerchantRequestID'] ?? '',
                $phoneF,
                $amount,
                $lease['unit_number'],
            ]);

            ApiResponse::ok([
                'checkout_request_id' => $result['CheckoutRequestID'],
                'payment_id'          => $payId,
                'payment_ref'         => $ref,
            ], 'STK Push sent. Enter your M-Pesa PIN.');

        } catch (Throwable $e) {
            error_log('[M-Pesa STK Error] ' . $e->getMessage());
            ApiResponse::serverError('M-Pesa service error. Please try again.');
        }
    });

    // ── STK Query ─────────────────────────────────────────────────────────────
    $router->post('mpesa/stk-query', function () use ($db) {
        ApiAuth::requireScope($db, 'write:payments');

        $body       = Router::body();
        $checkoutId = trim($body['checkout_request_id'] ?? '');
        if (!$checkoutId) ApiResponse::unprocessable('checkout_request_id is required.');

        try {
            // Resolve which shortcode was used for this checkout
            $txStmt = $db->prepare(
                "SELECT mt.*, l.unit_id
                 FROM mpesa_transactions mt
                 LEFT JOIN leases l ON l.id = mt.lease_id
                 WHERE mt.checkout_request_id = ? LIMIT 1"
            );
            $txStmt->execute([$checkoutId]);
            $tx = $txStmt->fetch();

            $mpesa = null;
            if ($tx && $tx['unit_id']) {
                $cfgStmt = $db->prepare(
                    "SELECT mc.* FROM mpesa_configs mc
                     JOIN properties pr ON pr.landlord_id = mc.landlord_id
                     JOIN units u ON u.property_id = pr.id
                     WHERE u.id = ? AND mc.is_active = 1 LIMIT 1"
                );
                $cfgStmt->execute([$tx['unit_id']]);
                $cfgRow = $cfgStmt->fetch();
                if ($cfgRow) $mpesa = mpesaServiceFromConfig($cfgRow);
            }
            if (!$mpesa) $mpesa = new MpesaService(mpesaSettings($db));

            $result     = $mpesa->stkQuery($checkoutId);
            $result['queried_at'] = date('c');
            $resultCode = isset($result['ResultCode']) ? (int)$result['ResultCode'] : null;

            if ($resultCode !== null && $tx && $tx['status'] === 'pending') {
                if ($resultCode === 0) {
                    $db->prepare(
                        "UPDATE mpesa_transactions SET status='completed', result_code=0, result_desc=? WHERE checkout_request_id=?"
                    )->execute([$result['ResultDesc'] ?? 'Success', $checkoutId]);
                    if ($tx['payment_id']) {
                        $db->prepare("UPDATE payments SET status='completed' WHERE id=?")
                           ->execute([$tx['payment_id']]);
                    }
                } elseif ($resultCode !== 1032) {
                    $db->prepare(
                        "UPDATE mpesa_transactions SET status='failed', result_code=?, result_desc=? WHERE checkout_request_id=?"
                    )->execute([$resultCode, $result['ResultDesc'] ?? 'Failed', $checkoutId]);
                    if ($tx['payment_id']) {
                        $db->prepare("UPDATE payments SET status='failed' WHERE id=?")
                           ->execute([$tx['payment_id']]);
                    }
                }
            }

            ApiResponse::ok($result);
        } catch (Throwable $e) {
            error_log('[M-Pesa STK Query Error] ' . $e->getMessage());
            ApiResponse::serverError('M-Pesa query error. Please try again.');
        }
    });

    // ── M-Pesa Config CRUD (super admin / admin only) ─────────────────────────

    // List all configs with landlord details
    $router->get('mpesa/configs', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin');

        $rows = $db->query(
            "SELECT mc.*,
                    u.name AS landlord_name,
                    u.email AS landlord_email
             FROM mpesa_configs mc
             JOIN landlords l ON l.id = mc.landlord_id
             JOIN users u ON u.id = l.user_id
             ORDER BY u.name"
        )->fetchAll();

        // Mask credentials before returning — never expose keys in list view
        foreach ($rows as &$row) {
            $row['consumer_key']    = str_repeat('*', max(0, strlen($row['consumer_key']) - 4))    . substr($row['consumer_key'],    -4);
            $row['consumer_secret'] = str_repeat('*', max(0, strlen($row['consumer_secret']) - 4)) . substr($row['consumer_secret'], -4);
            $row['passkey']         = str_repeat('*', 32);
        }
        unset($row);

        ApiResponse::ok($rows);
    });

    // List landlords without a config (must be before /{id} so the literal path wins)
    $router->get('mpesa/configs/unregistered-landlords', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin');
        $rows = $db->query(
            "SELECT l.id, u.name, u.email
             FROM landlords l
             JOIN users u ON u.id = l.user_id
             WHERE l.id NOT IN (SELECT landlord_id FROM mpesa_configs)
             ORDER BY u.name"
        )->fetchAll();
        ApiResponse::ok($rows);
    });

    // Get single config (credentials unmasked for editing)
    $router->get('mpesa/configs/{id}', function (int $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin');

        $stmt = $db->prepare(
            "SELECT mc.*,
                    u.name AS landlord_name,
                    u.email AS landlord_email
             FROM mpesa_configs mc
             JOIN landlords l ON l.id = mc.landlord_id
             JOIN users u ON u.id = l.user_id
             WHERE mc.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) ApiResponse::notFound('M-Pesa config not found.');
        ApiResponse::ok($row);
    });

    // Create config for a landlord
    $router->post('mpesa/configs', function () use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin');

        $b = Router::body();
        $required = ['landlord_id', 'shortcode', 'consumer_key', 'consumer_secret'];
        foreach ($required as $f) {
            if (empty($b[$f])) ApiResponse::unprocessable("$f is required.");
        }

        $landlordId    = (int)$b['landlord_id'];
        $shortcode     = trim($b['shortcode']);
        $shortcodeType = in_array($b['shortcode_type'] ?? '', ['paybill', 'till']) ? $b['shortcode_type'] : 'paybill';
        $environment   = ($b['environment'] ?? 'production') === 'sandbox' ? 'sandbox' : 'production';

        // Verify landlord exists
        $lStmt = $db->prepare("SELECT id FROM landlords WHERE id = ?");
        $lStmt->execute([$landlordId]);
        if (!$lStmt->fetchColumn()) ApiResponse::notFound('Landlord not found.');

        try {
            $db->prepare(
                "INSERT INTO mpesa_configs
                    (landlord_id, shortcode, shortcode_type, consumer_key, consumer_secret, environment, is_active, created_by)
                 VALUES (?,?,?,?,?,?,1,?)"
            )->execute([
                $landlordId, $shortcode, $shortcodeType,
                trim($b['consumer_key']), trim($b['consumer_secret']),
                $environment, ApiAuth::userId(),
            ]);
            $newId = (int)$db->lastInsertId();
            ApiResponse::created(['id' => $newId], 'M-Pesa config created. Use Register URLs to activate C2B.');
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                ApiResponse::unprocessable('A config already exists for this landlord or shortcode.');
            }
            throw $e;
        }
    });

    // Update config
    $router->put('mpesa/configs/{id}', function (int $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin');

        $b = Router::body();
        $stmt = $db->prepare("SELECT id FROM mpesa_configs WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) ApiResponse::notFound('M-Pesa config not found.');

        $fields = [];
        $params = [];
        $allowed = ['shortcode', 'shortcode_type', 'consumer_key', 'consumer_secret', 'environment', 'is_active', 'notes'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $b)) {
                $fields[] = "$f = ?";
                $params[] = $f === 'is_active' ? (int)$b[$f] : trim((string)$b[$f]);
            }
        }
        if (empty($fields)) ApiResponse::unprocessable('No updatable fields provided.');

        // URL registration needs to be redone if shortcode/environment changes
        if (array_key_exists('shortcode', $b) || array_key_exists('environment', $b)) {
            $fields[] = 'urls_registered = 0';
            $fields[] = 'urls_registered_at = NULL';
        }

        $params[] = $id;
        $db->prepare("UPDATE mpesa_configs SET " . implode(', ', $fields) . " WHERE id = ?")
           ->execute($params);

        ApiResponse::ok(['id' => $id], 'M-Pesa config updated.');
    });

    // Delete config
    $router->delete('mpesa/configs/{id}', function (int $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin');
        $db->prepare("DELETE FROM mpesa_configs WHERE id = ?")->execute([$id]);
        ApiResponse::ok([], 'M-Pesa config deleted.');
    });

    // Register C2B URLs with Safaricom
    $router->post('mpesa/configs/{id}/register-urls', function (int $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'super_admin');

        $stmt = $db->prepare("SELECT * FROM mpesa_configs WHERE id = ?");
        $stmt->execute([$id]);
        $cfg = $stmt->fetch();
        if (!$cfg) ApiResponse::notFound('M-Pesa config not found.');

        $baseUrl     = rtrim(env('APP_URL', ''), '/');
        $validateUrl = $baseUrl . '/mpesa/validate';
        $confirmUrl  = $baseUrl . '/mpesa/confirm';

        try {
            $mpesa  = mpesaServiceFromConfig($cfg);
            $result = $mpesa->registerUrls($validateUrl, $confirmUrl);

            $responseCode = $result['ResponseCode'] ?? null;
            if ($responseCode !== '0' && $responseCode !== 0) {
                $desc = $result['ResponseDescription'] ?? ($result['errorMessage'] ?? 'Register URLs failed.');
                ApiResponse::unprocessable($desc);
            }

            $db->prepare(
                "UPDATE mpesa_configs SET urls_registered=1, urls_registered_at=NOW() WHERE id=?"
            )->execute([$id]);

            ApiResponse::ok([
                'validate_url' => $validateUrl,
                'confirm_url'  => $confirmUrl,
                'safaricom'    => $result,
            ], 'C2B URLs registered successfully with Safaricom.');
        } catch (Throwable $e) {
            error_log('[M-Pesa Register URLs Error] ' . $e->getMessage());
            ApiResponse::serverError('Failed to register URLs: ' . $e->getMessage());
        }
    });

}
