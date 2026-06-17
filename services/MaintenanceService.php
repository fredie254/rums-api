<?php
require_once __DIR__ . '/BaseService.php';

class MaintenanceService extends BaseService
{
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $where[] = "mr.status NOT IN ('completed','resolved','cancelled')";
            } else {
                $where[] = 'mr.status = ?';
                $params[] = $filters['status'];
            }
        }
        if (!empty($filters['priority']))    { $where[] = 'mr.priority = ?';     $params[] = $filters['priority']; }
        if (!empty($filters['property_id'])) { $where[] = 'u.property_id = ?';  $params[] = (int)$filters['property_id']; }
        if (!empty($filters['unit_id']))     { $where[] = 'mr.unit_id = ?';      $params[] = (int)$filters['unit_id']; }
        if (!empty($filters['assigned_to'])) { $where[] = 'mr.assigned_to = ?'; $params[] = (int)$filters['assigned_to']; }
        if (!empty($filters['tenant_id']))   { $where[] = 'mr.tenant_id = ?';   $params[] = (int)$filters['tenant_id']; }

        $w = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT mr.*,
            u.unit_number, pr.name AS property_name, pr.id AS property_id,
            CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
            au.name AS assigned_to_name,
            DATEDIFF(NOW(), mr.created_at) AS age_days,
            (COALESCE(mr.materials_cost, 0) + COALESCE(mr.labour_cost, 0)) AS total_cost
            FROM maintenance_requests mr
            LEFT JOIN units u        ON u.id  = mr.unit_id
            LEFT JOIN properties pr  ON pr.id = u.property_id
            LEFT JOIN tenants t      ON t.id  = mr.tenant_id
            LEFT JOIN users au       ON au.id = mr.assigned_to
            $w
            ORDER BY FIELD(mr.priority,'urgent','high','medium','low'), mr.created_at DESC";

        $countSql = "SELECT COUNT(*) FROM maintenance_requests mr
            LEFT JOIN units u ON u.id = mr.unit_id $w";

        return $this->paginatedQuery($sql, $params, $countSql, $params, $page, $perPage);
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT mr.*,
                u.unit_number, pr.name AS property_name,
                CONCAT(t.first_name,' ',t.last_name) AS tenant_name, t.phone AS tenant_phone,
                au.name AS assigned_to_name,
                (COALESCE(mr.materials_cost, 0) + COALESCE(mr.labour_cost, 0)) AS total_cost
             FROM maintenance_requests mr
             LEFT JOIN units u        ON u.id  = mr.unit_id
             LEFT JOIN properties pr  ON pr.id = u.property_id
             LEFT JOIN tenants t      ON t.id  = mr.tenant_id
             LEFT JOIN users au       ON au.id = mr.assigned_to
             WHERE mr.id = ?",
            [$id]
        );
    }

    public function create(array $data): array
    {
        $missing = $this->requireFields($data, ['unit_id', 'issue_title', 'priority']);
        if ($missing) return ['success' => false, 'errors' => $missing, 'message' => 'Missing required fields.'];

        $unit = $this->fetchOne("SELECT id, property_id FROM units WHERE id = ?", [(int)$data['unit_id']]);
        if (!$unit) return ['success' => false, 'message' => 'Unit not found.'];

        $request_number = 'MNT-' . date('Y') . '-' . str_pad(
            (int)$this->fetchColumn("SELECT COALESCE(MAX(id), 0) + 1 FROM maintenance_requests"),
            5, '0', STR_PAD_LEFT
        );

        $lease = $this->fetchOne(
            "SELECT tenant_id FROM leases WHERE unit_id = ? AND status = 'active' LIMIT 1",
            [(int)$data['unit_id']]
        );

        $allowed = $this->only($data, [
            'unit_id', 'issue_title', 'description', 'priority',
            'category', 'assigned_to', 'notes',
        ]);
        $allowed['request_number'] = $request_number;
        $allowed['status']         = 'open';
        $allowed['tenant_id']      = $data['tenant_id'] ?? ($lease['tenant_id'] ?? null);

        $cols   = implode(', ', array_keys($allowed));
        $places = implode(', ', array_fill(0, count($allowed), '?'));
        $id     = $this->insert("INSERT INTO maintenance_requests ($cols) VALUES ($places)", array_values($allowed));

        return ['success' => true, 'id' => $id, 'request_number' => $request_number, 'message' => 'Work order created.'];
    }

    public function update(int $id, array $data): array
    {
        if (!$this->find($id)) return ['success' => false, 'message' => 'Work order not found.'];

        $allowed = $this->only($data, [
            'status', 'priority', 'assigned_to', 'notes',
            'work_started', 'work_completed', 'labour_hours',
            'materials_cost', 'labour_cost', 'contractor_name', 'contractor_phone',
            'is_recurring', 'next_due_date',
        ]);

        if (($allowed['status'] ?? '') === 'in_progress' && empty($allowed['work_started'])) {
            $allowed['work_started'] = date('Y-m-d H:i:s');
        }
        if (in_array($allowed['status'] ?? '', ['completed','resolved']) && empty($allowed['work_completed'])) {
            $allowed['work_completed'] = date('Y-m-d H:i:s');
        }

        if (!$allowed) return ['success' => false, 'message' => 'No valid fields to update.'];

        [$set, $vals] = $this->buildSet($allowed);
        $this->execute("UPDATE maintenance_requests SET $set WHERE id = ?", [...$vals, $id]);
        return ['success' => true, 'message' => 'Work order updated.'];
    }

    public function summary(?int $propertyId = null): array
    {
        $where  = '1=1';
        $params = [];
        if ($propertyId) {
            $where    = 'u.property_id = ?';
            $params[] = $propertyId;
        }

        return $this->fetchOne(
            "SELECT
                COUNT(*) AS total,
                SUM(mr.status = 'open')                          AS open,
                SUM(mr.status = 'in_progress')                   AS in_progress,
                SUM(mr.status IN ('completed','resolved'))        AS completed,
                SUM(mr.priority = 'urgent')                      AS urgent,
                SUM(mr.priority = 'high')                        AS high,
                COALESCE(SUM(mr.materials_cost + mr.labour_cost), 0) AS total_cost,
                AVG(CASE WHEN mr.status IN ('completed','resolved')
                    THEN DATEDIFF(mr.work_completed, mr.created_at) END) AS avg_days_to_resolve
             FROM maintenance_requests mr
             LEFT JOIN units u ON u.id = mr.unit_id
             WHERE $where",
            $params
        ) ?: [];
    }
}
