<?php
/**
 * Expenses endpoints
 *
 * GET    /api/v1/expenses              list (filterable by status, category, property, date range)
 * GET    /api/v1/expenses/summary      totals by status for a period
 * POST   /api/v1/expenses              create expense
 * PATCH  /api/v1/expenses/{id}/approve approve expense (admin only)
 * PATCH  /api/v1/expenses/{id}/reject  reject expense (admin only)
 * PATCH  /api/v1/expenses/{id}/mark-paid mark approved expense as paid
 */
function registerExpenseRoutes(Router $router, PDO $db): void
{
    // ── Summary (static before parameterised) ────────────────────
    $router->get('expenses/summary', function () use ($db) {
        ApiAuth::requireScope($db, 'read:reports');
        $from = Router::strParam('date_from', date('Y-m-01'));
        $to   = Router::strParam('date_to',   date('Y-m-t'));

        $row = $db->prepare(
            "SELECT
                COALESCE(SUM(amount),0)                                     AS total,
                COALESCE(SUM(CASE WHEN status='pending'  THEN amount END),0) AS pending,
                COALESCE(SUM(CASE WHEN status='approved' THEN amount END),0) AS approved,
                COALESCE(SUM(CASE WHEN status='paid'     THEN amount END),0) AS paid,
                COALESCE(SUM(CASE WHEN status='rejected' THEN amount END),0) AS rejected,
                COUNT(*) AS count
             FROM expenses
             WHERE expense_date BETWEEN ? AND ?"
        );
        $row->execute([$from, $to]);
        ApiResponse::ok($row->fetch(PDO::FETCH_ASSOC));
    });

    // ── List ─────────────────────────────────────────────────────
    $router->get('expenses', function () use ($db) {
        ApiAuth::requireScope($db, 'read:reports');
        $page    = Router::page();
        $perPage = Router::perPage();

        $from       = Router::strParam('date_from', date('Y-m-01'));
        $to         = Router::strParam('date_to',   date('Y-m-t'));
        $status     = Router::strParam('status');
        $category   = Router::strParam('category');
        $propId     = Router::intParam('property_id');
        $landlordId = Router::intParam('landlord_id');

        $where  = ['e.expense_date BETWEEN ? AND ?'];
        $params = [$from, $to];

        if ($status)     { $where[] = 'e.status = ?';       $params[] = $status; }
        if ($category)   { $where[] = 'e.category = ?';     $params[] = $category; }
        if ($propId)     { $where[] = 'e.property_id = ?';  $params[] = $propId; }
        if ($landlordId) { $where[] = 'pr.landlord_id = ?'; $params[] = $landlordId; }

        $w = 'WHERE ' . implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM expenses e LEFT JOIN properties pr ON pr.id = e.property_id $w";
        $cStmt    = $db->prepare($countSql);
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT e.*,
                    pr.name AS property_name,
                    u.unit_number,
                    pu.name AS paid_by_name
                FROM expenses e
                LEFT JOIN properties pr ON pr.id = e.property_id
                LEFT JOIN units u       ON u.id  = e.unit_id
                LEFT JOIN users pu      ON pu.id = e.paid_by
                $w
                ORDER BY e.expense_date DESC, e.id DESC
                LIMIT ? OFFSET ?";

        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ApiResponse::paginated([
            'data' => $rows,
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'total_pages'  => (int)ceil($total / $perPage),
            ],
        ]);
    });

    // ── Create ───────────────────────────────────────────────────
    $router->post('expenses', function () use ($db) {
        ApiAuth::requireScope($db, 'write:payments');
        $user = ApiAuth::user();
        $body = Router::body();

        $required = ['category', 'description', 'amount', 'expense_date'];
        $missing  = [];
        foreach ($required as $f) {
            if (empty($body[$f])) $missing[] = $f;
        }
        if ($missing) {
            ApiResponse::unprocessable('Missing required fields.', $missing);
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO expenses
                (property_id, unit_id, category, description, amount, expense_date,
                 vendor, receipt_ref, paid_by, status, notes)
             VALUES (?,?,?,?,?,?,?,?,?,'pending',?)"
        );
        $stmt->execute([
            $body['property_id'] ? (int)$body['property_id'] : null,
            $body['unit_id']     ? (int)$body['unit_id']     : null,
            $body['category'],
            $body['description'],
            (float)$body['amount'],
            $body['expense_date'],
            $body['vendor']      ?? null,
            $body['receipt_ref'] ?? null,
            $user['id'],
            $body['notes']       ?? null,
        ]);
        $id = (int)$db->lastInsertId();
        ApiResponse::created(['id' => $id], 'Expense recorded.');
    });

    // ── Approve ──────────────────────────────────────────────────
    $router->patch('expenses/{id}/approve', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $user = ApiAuth::user();
        $stmt = $db->prepare(
            "UPDATE expenses SET status='approved', approved_by=? WHERE id=? AND status='pending'"
        );
        $stmt->execute([$user['id'], (int)$id]);
        $stmt->rowCount()
            ? ApiResponse::ok([], 'Expense approved.')
            : ApiResponse::unprocessable('Expense not found or not pending.');
    });

    // ── Reject ───────────────────────────────────────────────────
    $router->patch('expenses/{id}/reject', function (string $id) use ($db) {
        ApiAuth::requireRole($db, 'admin', 'manager');
        $stmt = $db->prepare(
            "UPDATE expenses SET status='rejected' WHERE id=? AND status='pending'"
        );
        $stmt->execute([(int)$id]);
        $stmt->rowCount()
            ? ApiResponse::ok([], 'Expense rejected.')
            : ApiResponse::unprocessable('Expense not found or not pending.');
    });

    // ── Mark Paid ────────────────────────────────────────────────
    $router->patch('expenses/{id}/mark-paid', function (string $id) use ($db) {
        ApiAuth::requireScope($db, 'write:payments');
        $stmt = $db->prepare(
            "UPDATE expenses SET status='paid' WHERE id=? AND status='approved'"
        );
        $stmt->execute([(int)$id]);
        $stmt->rowCount()
            ? ApiResponse::ok([], 'Expense marked as paid.')
            : ApiResponse::unprocessable('Expense not found or not approved.');
    });
}
