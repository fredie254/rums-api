<?php
require_once __DIR__ . '/BaseService.php';

class PropertyService extends BaseService
{
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ['p.status != "deleted"'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(p.name LIKE ? OR p.address_line1 LIKE ? OR p.address_line2 LIKE ?)';
            $s = '%' . $filters['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s;
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['property_type'])) {
            $where[] = 'p.property_type = ?';
            $params[] = $filters['property_type'];
        }
        if (!empty($filters['landlord_id'])) {
            $where[] = 'p.landlord_id = ?';
            $params[] = (int)$filters['landlord_id'];
        }

        $w = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
            p.id, p.name, p.property_type, p.address_line1, p.address_line2,
            p.address_city, p.address_county, p.address_country, p.year_built,
            p.landlord_id, p.manager_id, p.description, p.amenities,
            p.status, p.created_at,
            u.name AS landlord_name,
            COUNT(DISTINCT un.id)                                          AS total_units,
            COUNT(DISTINCT CASE WHEN un.status='occupied'  THEN un.id END) AS occupied_units,
            COUNT(DISTINCT CASE WHEN un.status='available' THEN un.id END) AS available_units
            FROM properties p
            LEFT JOIN landlords l ON l.id = p.landlord_id
            LEFT JOIN users u     ON u.id = l.user_id
            LEFT JOIN units un    ON un.property_id = p.id
            $w
            GROUP BY p.id
            ORDER BY p.name";

        $countSql = "SELECT COUNT(DISTINCT p.id) FROM properties p $w";

        return $this->paginatedQuery($sql, $params, $countSql, $params, $page, $perPage);
    }

    public function find(int $id): ?array
    {
        // Single query: property + landlord name/email
        $prop = $this->fetchOne(
            "SELECT p.*, u.name AS landlord_name, u.email AS landlord_email
             FROM properties p
             LEFT JOIN landlords l ON l.id = p.landlord_id
             LEFT JOIN users u ON u.id = l.user_id
             WHERE p.id = ?",
            [$id]
        );
        if (!$prop) return null;

        // One query: units list + aggregate stats side-by-side via a subquery
        // Units list
        $prop['units'] = $this->fetchAll(
            "SELECT * FROM units WHERE property_id = ? ORDER BY unit_number",
            [$id]
        );

        // Stats: combine unit aggregates + year income in a single query
        $prop['stats'] = $this->fetchOne(
            "SELECT
                COUNT(*)                                    AS total_units,
                COALESCE(SUM(status='occupied'),   0)       AS occupied,
                COALESCE(SUM(status='available'),  0)       AS available,
                COALESCE(SUM(status='maintenance'),0)       AS maintenance,
                COALESCE(SUM(rent_amount),         0)       AS potential_monthly_revenue,
                COALESCE((
                    SELECT SUM(pay.amount)
                    FROM payments pay
                    JOIN leases le ON le.id = pay.lease_id
                    WHERE le.unit_id IN (SELECT id FROM units WHERE property_id = ?)
                      AND YEAR(pay.payment_date) = YEAR(NOW())
                ), 0) AS year_income
             FROM units WHERE property_id = ?",
            [$id, $id]
        ) ?? ['total_units' => 0, 'occupied' => 0, 'available' => 0, 'maintenance' => 0, 'potential_monthly_revenue' => 0, 'year_income' => 0];

        return $prop;
    }

    public function stats(int $propertyId): array
    {
        $row = $this->fetchOne(
            "SELECT
                COUNT(*)                                    AS total_units,
                COALESCE(SUM(status='occupied'),   0)       AS occupied,
                COALESCE(SUM(status='available'),  0)       AS available,
                COALESCE(SUM(status='maintenance'),0)       AS maintenance,
                COALESCE(SUM(rent_amount),         0)       AS potential_monthly_revenue,
                COALESCE((
                    SELECT SUM(pay.amount)
                    FROM payments pay
                    JOIN leases le ON le.id = pay.lease_id
                    WHERE le.unit_id IN (SELECT id FROM units WHERE property_id = ?)
                      AND YEAR(pay.payment_date) = YEAR(NOW())
                ), 0) AS year_income
             FROM units WHERE property_id = ?",
            [$propertyId, $propertyId]
        );

        return $row ?? ['total_units' => 0, 'occupied' => 0, 'available' => 0, 'maintenance' => 0, 'potential_monthly_revenue' => 0, 'year_income' => 0];
    }

    public function create(array $data): array
    {
        $missing = $this->requireFields($data, ['name', 'property_type']);
        if ($missing) {
            return ['success' => false, 'errors' => $missing, 'message' => 'Missing required fields.'];
        }

        $allowed = $this->only($data, [
            'name', 'property_type', 'address_line1', 'address_line2', 'address_city',
            'address_county', 'address_country', 'total_units', 'year_built',
            'landlord_id', 'manager_id', 'description', 'amenities', 'status', 'image',
        ]);
        $allowed = array_filter($allowed, fn($v) => $v !== null && $v !== '');
        $allowed['status'] = $allowed['status'] ?? 'active';

        try {
            $cols   = implode(', ', array_keys($allowed));
            $places = implode(', ', array_fill(0, count($allowed), '?'));
            $id     = $this->insert("INSERT INTO properties ($cols) VALUES ($places)", array_values($allowed));
        } catch (Throwable $e) {
            error_log('[PropertyService::create] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to save property: ' . $e->getMessage()];
        }

        return ['success' => true, 'id' => $id, 'message' => 'Property created.'];
    }

    public function update(int $id, array $data): array
    {
        $allowed = $this->only($data, [
            'name', 'property_type', 'address_line1', 'address_line2', 'address_city',
            'address_county', 'address_country', 'total_units', 'year_built',
            'landlord_id', 'manager_id', 'description', 'amenities', 'status', 'image',
        ]);
        if (!$allowed) {
            return ['success' => false, 'message' => 'No valid fields to update.'];
        }

        [$set, $vals] = $this->buildSet($allowed);
        $affected = $this->execute("UPDATE properties SET $set WHERE id = ? AND status != 'deleted'", [...$vals, $id]);
        if ($affected === 0) {
            return ['success' => false, 'message' => 'Property not found.'];
        }
        return ['success' => true, 'message' => 'Property updated.'];
    }

    public function delete(int $id): array
    {
        // Check occupancy without loading the full property object
        $occupied = (int)$this->fetchColumn(
            "SELECT COUNT(*) FROM units WHERE property_id = ? AND status = 'occupied'",
            [$id]
        );
        if ($occupied > 0) {
            return ['success' => false, 'message' => "Cannot delete — $occupied unit(s) still occupied."];
        }

        $affected = $this->execute("UPDATE properties SET status = 'deleted' WHERE id = ? AND status != 'deleted'", [$id]);
        if ($affected === 0) {
            return ['success' => false, 'message' => 'Property not found.'];
        }
        return ['success' => true, 'message' => 'Property deleted.'];
    }
}
