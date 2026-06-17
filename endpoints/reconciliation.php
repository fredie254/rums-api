<?php
/**
 * Bank Reconciliation endpoints
 *
 * GET    /api/v1/reconciliation                    — summary report for a period
 * GET    /api/v1/reconciliation/entries            — list statement entries (paginated)
 * GET    /api/v1/reconciliation/batches            — list import batches
 * GET    /api/v1/reconciliation/unmatched-rums     — RUMS bank payments with no statement entry
 * POST   /api/v1/reconciliation/import             — import CSV rows (JSON array)
 * POST   /api/v1/reconciliation/auto-match         — run auto-matching for a period
 * PATCH  /api/v1/reconciliation/entries/{id}/match — manually link entry → payment
 * DELETE /api/v1/reconciliation/entries/{id}/match — unlink entry from payment
 */
require_once __DIR__ . '/../services/ReconciliationService.php';

function registerReconciliationRoutes(Router $router, PDO $db): void
{
    $svc = new ReconciliationService($db);

    // ── Summary report ────────────────────────────────────────────
    $router->get('reconciliation', function () use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager', 'accountant');
        $from   = Router::strParam('date_from', date('Y-m-01'));
        $to     = Router::strParam('date_to',   date('Y-m-d'));
        $propId = Router::intParam('property_id') ?: null;
        ApiResponse::ok($svc->report($from, $to, $propId));
    });

    // ── List entries ──────────────────────────────────────────────
    $router->get('reconciliation/entries', function () use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager', 'accountant');
        $from        = Router::strParam('date_from', date('Y-m-01'));
        $to          = Router::strParam('date_to',   date('Y-m-d'));
        $matchStatus = Router::strParam('match_status') ?: null;  // matched|unmatched|null
        $page        = Router::page();
        $perPage     = Router::perPage(50);
        ApiResponse::paginated($svc->getEntries($from, $to, $matchStatus, $page, $perPage));
    });

    // ── List batches ──────────────────────────────────────────────
    $router->get('reconciliation/batches', function () use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager', 'accountant');
        ApiResponse::ok($svc->getBatches());
    });

    // ── Unmatched RUMS payments ───────────────────────────────────
    $router->get('reconciliation/unmatched-rums', function () use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'manager', 'accountant');
        $from = Router::strParam('date_from', date('Y-m-01'));
        $to   = Router::strParam('date_to',   date('Y-m-d'));
        ApiResponse::ok($svc->getUnmatchedRumsPayments($from, $to));
    });

    // ── Import CSV rows ───────────────────────────────────────────
    $router->post('reconciliation/import', function () use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'accountant');
        $body  = Router::body();
        $rows  = $body['rows']  ?? [];
        $batch = trim($body['batch'] ?? '');

        if (empty($rows) || !is_array($rows)) {
            ApiResponse::unprocessable('rows array is required.');
        }
        if (count($rows) > 5000) {
            ApiResponse::unprocessable('Maximum 5000 rows per import.');
        }

        $result = $svc->import($rows, ApiAuth::user()['id'], $batch);
        ApiResponse::ok($result, "{$result['inserted']} row(s) imported in batch {$result['batch']}.");
    });

    // ── Auto-match ────────────────────────────────────────────────
    $router->post('reconciliation/auto-match', function () use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'accountant');
        $body = Router::body();
        $from = trim($body['date_from'] ?? date('Y-m-01'));
        $to   = trim($body['date_to']   ?? date('Y-m-d'));

        $matched = $svc->autoMatch($from, $to);
        ApiResponse::ok(['matched' => $matched], "$matched entry/entries auto-matched.");
    });

    // ── Manual match (PATCH) — static segment "entries" must come before {id} ──
    $router->patch('reconciliation/entries/{id}/match', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'accountant');
        $body      = Router::body();
        $paymentId = (int)($body['payment_id'] ?? 0);
        if (!$paymentId) ApiResponse::unprocessable('payment_id is required.');

        $ok = $svc->matchEntry((int)$id, $paymentId, ApiAuth::user()['id']);
        $ok ? ApiResponse::ok(null, 'Entry matched to payment.') : ApiResponse::notFound('Entry or payment not found.');
    });

    // ── Unmatch (DELETE) ──────────────────────────────────────────
    $router->delete('reconciliation/entries/{id}/match', function (string $id) use ($svc, $db) {
        ApiAuth::requireRole($db, 'admin', 'accountant');
        $ok = $svc->unmatchEntry((int)$id);
        $ok ? ApiResponse::ok(null, 'Entry unmatched.') : ApiResponse::notFound('Entry not found.');
    });
}
