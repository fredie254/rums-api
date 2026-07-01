<?php
require_once __DIR__ . '/BaseService.php';

class PropertyService extends BaseService
{
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ['p.status != "deleted"'];
        $params = [];

        if (!empty($filters['search'])) {
            // p.address does not exist — search against address_line1 and address_line2
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

        // Explicit column list avoids duplicate `total_units` alias collision with p.*
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
        $prop = $this->fetchOne(
            "SELECT p.*, u.name AS landlord_name, u.email AS landlord_email
             FROM properties p
             LEFT JOIN landlords l ON l.id = p.landlord_id
             LEFT JOIN users u ON u.id = l.user_id
             WHERE p.id = ?",
            [$id]
        );
        if (!$prop) return null;

        $prop['units'] = $this->fetchAll(
            "SELECT * FROM units WHERE property_id = ? ORDER BY unit_number",
            [$id]
        );
        $prop['stats'] = $this->stats($id);
        return $prop;
    }

    public function stats(int $propertyId): array
    {
        $row = $this->fetchOne(
            "SELECT
                COUNT(*)                            AS total_units,
                SUM(status='occupied')              AS occupied,
                SUM(status='available')             AS available,
                SUM(status='maintenance')           AS maintenance,
                COALESCE(SUM(rent_amount), 0)       AS potential_monthly_revenue
             FROM units WHERE property_id = ?",
            [$propertyId]
        );

        $income = $this->fetchColumn(
            "SELECT COALESCE(SUM(p.amount), 0)
             FROM payments p
             JOIN leases l ON l.id = p.lease_id
             JOIN units u  ON u.id = l.unit_id
             WHERE u.property_id = ? AND YEAR(p.payment_date) = YEAR(NOW())",
            [$propertyId]
        );

        return array_merge($row ?? [], ['year_income' => (float)$income]);
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
        // Strip null values so they are omitted from INSERT (rely on DB defaults)
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
        if (!$this->find($id)) {
            return ['success' => false, 'message' => 'Property not found.'];
        }

        $allowed = $this->only($data, [
            'name', 'property_type', 'address_line1', 'address_line2', 'address_city',
            'address_county', 'address_country', 'total_units', 'year_built',
            'landlord_id', 'manager_id', 'description', 'amenities', 'status', 'image',
        ]);
        if (!$allowed) {
            return ['success' => false, 'message' => 'No valid fields to update.'];
        }

        [$set, $vals] = $this->buildSet($allowed);
        $this->execute("UPDATE properties SET $set WHERE id = ?", [...$vals, $id]);
        return ['success' => true, 'message' => 'Property updated.'];
    }

    public function delete(int $id): array
    {
        $prop = $this->find($id);
        if (!$prop) return ['success' => false, 'message' => 'Property not found.'];

        $occupied = (int)($prop['stats']['occupied'] ?? 0);
        if ($occupied > 0) {
            return ['success' => false, 'message' => "Cannot delete — $occupied unit(s) still occupied."];
        }

        $this->execute("UPDATE properties SET status = 'deleted' WHERE id = ?", [$id]);
        return ['success' => true, 'message' => 'Property deleted.'];
    }
}
