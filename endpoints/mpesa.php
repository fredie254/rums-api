<?php
/**
 * M-Pesa endpoints
 *
 * POST /api/v1/mpesa/stk-push  (protected) — initiate STK push & store pending payment
 * POST /api/v1/mpesa/callback  (PUBLIC)    — Safaricom webhook, updates DB records
 */

require_once __DIR__ . '/../services/MpesaService.php';

/**
 * Read M-Pesa settings from the settings table.
 * Returns a config array for MpesaService constructor.
 */
function mpesaSettings(PDO $db): array
{
    $rows = $db->query(
        "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'mpesa_%'"
    )->fetchAll();
    $cfg = array_column($rows, 'setting_value', 'setting_key');

    return [
        'consumer_key'    => $cfg['mpesa_consumer_key']    ?? '',
        'consumer_secret' => $cfg['mpesa_consumer_secret'] ?? '',
        'shortcode'       => $cfg['mpesa_shortcode']       ?? '',
        'passkey'         => $cfg['mpesa_passkey']         ?? '',
        'env'             => $cfg['mpesa_env']             ?? 'sandbox',
        'callback_url'    => $cfg['mpesa_callback_url']    ?? '',
    ];
}

// ────────────────────────────────────────────────────────────────────────────
// PUBLIC — registered BEFORE auth guards; Safaricom calls this directly.
// ────────────────────────────────────────────────────────────────────────────
function registerMpesaPublicRoutes(Router $router, PDO $db): void
{
    $router->post('mpesa/callback', function () use ($db) {
        $raw = file_get_contents('php://input');
        error_log('[M-Pesa Callback] ' . date('Y-m-d H:i:s') . ' | ' . $raw);

        if (empty($raw)) {
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Empty body']);
            exit;
        }

        $data = json_decode($raw, true);
        if (!$data) {
            echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON']);
            exit;
        }

        try {
            $body     = $data['Body']['stkCallback'] ?? [];
            $checkout = $body['CheckoutRequestID'] ?? '';
            $result   = (int)($body['ResultCode'] ?? 1);
            $desc     = $body['ResultDesc'] ?? '';

            $stmt = $db->prepare(
                "SELECT * FROM mpesa_transactions WHERE checkout_request_id = ?"
            );
            $stmt->execute([$checkout]);
            $transaction = $stmt->fetch();

            if ($transaction) {
                if ($result === 0) {
                    // Successful payment
                    $items   = $body['CallbackMetadata']['Item'] ?? [];
                    $meta    = array_column($items, 'Value', 'Name');
                    $receipt = $meta['MpesaReceiptNumber'] ?? '';
                    $amount  = (float)($meta['Amount'] ?? $transaction['amount']);

                    $db->prepare(
                        "UPDATE mpesa_transactions
                         SET status='completed', mpesa_receipt=?, result_code=0, result_desc=?, raw_response=?
                         WHERE checkout_request_id=?"
                    )->execute([$receipt, $desc, $raw, $checkout]);

                    if ($transaction['payment_id']) {
                        $db->prepare(
                            "UPDATE payments SET status='completed', mpesa_receipt=?, amount=? WHERE id=?"
                        )->execute([$receipt, $amount, $transaction['payment_id']]);

                        $pStmt = $db->prepare("SELECT invoice_id FROM payments WHERE id=?");
                        $pStmt->execute([$transaction['payment_id']]);
                        $pay = $pStmt->fetch();
                        if ($pay && $pay['invoice_id']) {
                            $db->prepare(
                                "UPDATE invoices
                                 SET amount_paid = amount_paid + ?,
                                     status = CASE WHEN amount_paid + ? >= total_amount THEN 'paid' ELSE 'partial' END
                                 WHERE id=?"
                            )->execute([$amount, $amount, $pay['invoice_id']]);
                        }
                    }
                } else {
                    // Failed payment
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
            }
        } catch (Throwable $e) {
            error_log('[M-Pesa Callback Error] ' . $e->getMessage());
        }

        // Always respond with success to Safaricom
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        exit;
    });
}

// ────────────────────────────────────────────────────────────────────────────
// PROTECTED — registered AFTER auth guards
// ────────────────────────────────────────────────────────────────────────────
function registerMpesaProtectedRoutes(Router $router, PDO $db): void
{
    $router->post('mpesa/stk-push', function () use ($db) {
        ApiAuth::requireScope($db, 'write:payments');

        $body      = Router::body();
        $phone     = trim($body['phone']      ?? '');
        $amount    = (float)($body['amount']   ?? 0);
        $leaseId   = (int)($body['lease_id']   ?? 0);
        $invoiceId = (int)($body['invoice_id'] ?? 0) ?: null;
        $account   = trim($body['account']     ?? 'RENT');

        if (!$phone || $amount <= 0 || !$leaseId) {
            ApiResponse::unprocessable('phone, amount, and lease_id are required.');
        }

        $ls = $db->prepare(
            "SELECT l.*, u.unit_number FROM leases l
             JOIN units u ON l.unit_id = u.id
             WHERE l.id = ? AND l.status = 'active'"
        );
        $ls->execute([$leaseId]);
        $lease = $ls->fetch();
        if (!$lease) {
            ApiResponse::unprocessable('Lease not found or not active.');
        }

        try {
            $mpesa  = new MpesaService(mpesaSettings($db));
            $phoneF = MpesaService::formatPhone($phone);
            $result = $mpesa->stkPush($phoneF, $amount, $account, 'Rent - ' . $lease['unit_number']);

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
                    (payment_id, checkout_request_id, merchant_request_id, phone, amount, account_reference, status)
                 VALUES (?,?,?,?,?,?,'pending')"
            )->execute([
                $payId,
                $result['CheckoutRequestID'],
                $result['MerchantRequestID'] ?? '',
                $phoneF,
                $amount,
                $account,
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

    // ── STK Query ─────────────────────────────────────────────────
    $router->post('mpesa/stk-query', function () use ($db) {
        ApiAuth::requireScope($db, 'write:payments');

        $body       = Router::body();
        $checkoutId = trim($body['checkout_request_id'] ?? '');
        if (!$checkoutId) ApiResponse::unprocessable('checkout_request_id is required.');

        try {
            $mpesa  = new MpesaService(mpesaSettings($db));
            $result = $mpesa->stkQuery($checkoutId);
            $result['queried_at'] = date('c');

            $resultCode = isset($result['ResultCode']) ? (int)$result['ResultCode'] : null;

            // Update mpesa_transactions if result is conclusive
            if ($resultCode !== null) {
                $txStmt = $db->prepare(
                    "SELECT * FROM mpesa_transactions WHERE checkout_request_id = ?"
                );
                $txStmt->execute([$checkoutId]);
                $tx = $txStmt->fetch();

                if ($tx && $tx['status'] === 'pending') {
                    if ($resultCode === 0) {
                        $db->prepare(
                            "UPDATE mpesa_transactions SET status='completed', result_code=0, result_desc=? WHERE checkout_request_id=?"
                        )->execute([$result['ResultDesc'] ?? 'Success', $checkoutId]);

                        if ($tx['payment_id']) {
                            $db->prepare("UPDATE payments SET status='completed' WHERE id=?")
                               ->execute([$tx['payment_id']]);
                        }
                    } elseif ($resultCode !== 1032) {
                        // 1032 = user cancelled — keep pending; all other failures = failed
                        $db->prepare(
                            "UPDATE mpesa_transactions SET status='failed', result_code=?, result_desc=? WHERE checkout_request_id=?"
                        )->execute([$resultCode, $result['ResultDesc'] ?? 'Failed', $checkoutId]);

                        if ($tx['payment_id']) {
                            $db->prepare("UPDATE payments SET status='failed' WHERE id=?")
                               ->execute([$tx['payment_id']]);
                        }
                    }
                }
            }

            ApiResponse::ok($result);
        } catch (Throwable $e) {
            error_log('[M-Pesa STK Query Error] ' . $e->getMessage());
            ApiResponse::serverError('M-Pesa query error. Please try again.');
        }
    });
}
