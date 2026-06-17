<?php
/**
 * RUMS API — Base Service
 *
 * All domain services extend this class.
 * Provides DB access, pagination, input sanitisation, and query helpers.
 */
abstract class BaseService
{
    protected PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getDB();
    }

    // ── Fetch helpers ─────────────────────────────────────────

    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    protected function fetchColumn(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    protected function execute(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    protected function insert(string $sql, array $params = []): int
    {
        $this->db->prepare($sql)->execute($params);
        return (int)$this->db->lastInsertId();
    }

    // ── Validation helpers ────────────────────────────────────

    /** Return names of fields that are missing or empty. */
    protected function requireFields(array $data, array $fields): array
    {
        $missing = [];
        foreach ($fields as $f) {
            if (!isset($data[$f]) || $data[$f] === '' || $data[$f] === null) {
                $missing[] = $f;
            }
        }
        return $missing;
    }

    /** Whitelist allowed keys from input. */
    protected function only(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }

    /**
     * Build a SET clause for UPDATE.
     * Returns [string $clause, array $values].
     */
    protected function buildSet(array $fields): array
    {
        $clauses = array_map(fn($k) => "$k = ?", array_keys($fields));
        return [implode(', ', $clauses), array_values($fields)];
    }

    // ── Paginated query ───────────────────────────────────────

    /**
     * Execute a paginated SELECT.
     * Returns ['data' => [], 'meta' => [...]].
     */
    protected function paginatedQuery(
        string $sql,
        array  $params,
        string $countSql,
        array  $countParams,
        int    $page,
        int    $perPage
    ): array {
        $perPage    = max(1, min($perPage, 200));
        $page       = max(1, $page);
        $total      = (int)$this->fetchColumn($countSql, $countParams);
        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        $offset     = ($page - 1) * $perPage;

        $stmt = $this->db->prepare("$sql LIMIT ? OFFSET ?");
        foreach ($params as $k => $v) {
            $stmt->bindValue(is_int($k) ? $k + 1 : $k, $v);
        }
        $stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset,  PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(),
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'total_pages'  => $totalPages,
                'from'         => $offset + 1,
                'to'           => min($offset + $perPage, $total),
            ],
        ];
    }
}
