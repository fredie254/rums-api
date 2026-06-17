<?php
require_once __DIR__ . '/BaseService.php';

/**
 * RUMS — Document Service
 *
 * Handles secure file upload, storage, retrieval, versioning and
 * soft-deletion for the document management module.
 *
 * Storage layout:
 *   {DOCUMENT_STORAGE}/{entity_type}/{entity_id}/{stored_name}
 *
 * Access control (enforced at endpoint level, helpers here):
 *   admin/manager  → all documents
 *   accountant/auditor → read only, all
 *   tenant         → own tenant docs + shared access_level docs for their property
 *   staff          → internal + shared docs
 */
class DocumentService extends BaseService
{
    // Allowed MIME types and their canonical extensions
    private const ALLOWED_TYPES = [
        'application/pdf'                                                          => 'pdf',
        'application/msword'                                                       => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel'                                                 => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
        'image/jpeg'                                                               => 'jpg',
        'image/png'                                                                => 'png',
        'image/webp'                                                               => 'webp',
        'image/gif'                                                                => 'gif',
        'text/plain'                                                               => 'txt',
        'text/csv'                                                                 => 'csv',
    ];

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

    private string $storageBase;

    public function __construct(?PDO $db = null)
    {
        parent::__construct($db);
        $this->storageBase = rtrim(BASE_PATH . '/storage/documents', '/');
        if (!is_dir($this->storageBase)) {
            @mkdir($this->storageBase, 0750, true);
        }
    }

    // ── Upload ─────────────────────────────────────────────────

    /**
     * Store a file and create a document record.
     *
     * @param array $file    Element from $_FILES: [name, type, tmp_name, size, error]
     * @param array $meta    [title, description?, document_type, category?, entity_type, entity_id?, access_level?]
     * @param int   $userId  Uploader's user id
     * @param int|null $parentId  Set when uploading a new version (documents.id of parent)
     */
    public function upload(array $file, array $meta, int $userId, ?int $parentId = null): array
    {
        // ── Validate file ──────────────────────────────────────
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => $this->uploadErrorMessage($file['error'])];
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            return ['success' => false, 'error' => 'File too large. Maximum size is 10 MB.'];
        }

        // Detect MIME type from actual file content (not trusted client claim)
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!array_key_exists($mimeType, self::ALLOWED_TYPES)) {
            return ['success' => false, 'error' => "File type '$mimeType' is not allowed."];
        }

        // ── Required meta ──────────────────────────────────────
        if (empty($meta['title']))         return ['success' => false, 'error' => 'title is required.'];
        if (empty($meta['document_type'])) return ['success' => false, 'error' => 'document_type is required.'];
        if (empty($meta['entity_type']))   $meta['entity_type'] = 'general';

        // ── Version handling ───────────────────────────────────
        $version = 1;
        if ($parentId) {
            $parent = $this->fetchOne("SELECT version FROM documents WHERE id = ? AND is_deleted = 0", [$parentId]);
            if (!$parent) return ['success' => false, 'error' => 'Parent document not found.'];
            $version = (int)$parent['version'] + 1;
            // Mark parent (and all prior versions) as not latest
            $this->execute(
                "UPDATE documents SET is_latest = 0 WHERE id = ? OR parent_id = ?",
                [$parentId, $parentId]
            );
        }

        // ── Generate UUID and stored path ──────────────────────
        $uuid       = $this->generateUUID();
        $ext        = self::ALLOWED_TYPES[$mimeType];
        $entityDir  = ($meta['entity_type'] ?? 'general') . '/' . ($meta['entity_id'] ?? '0');
        $storedName = $uuid . '.' . $ext;
        $relPath    = $entityDir . '/' . $storedName;
        $absDir     = $this->storageBase . '/' . $entityDir;
        $absPath    = $this->storageBase . '/' . $relPath;

        if (!is_dir($absDir)) {
            if (!@mkdir($absDir, 0750, true)) {
                return ['success' => false, 'error' => 'Could not create storage directory.'];
            }
        }

        if (!move_uploaded_file($file['tmp_name'], $absPath)) {
            return ['success' => false, 'error' => 'Failed to store file. Check server permissions.'];
        }

        // ── Insert record ──────────────────────────────────────
        try {
            $id = $this->insert(
                "INSERT INTO documents
                    (uuid, title, description, document_type, category,
                     entity_type, entity_id, file_name, stored_name, file_path,
                     file_size, mime_type, version, parent_id, is_latest,
                     access_level, uploaded_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)",
                [
                    $uuid,
                    $meta['title'],
                    $meta['description'] ?? null,
                    $meta['document_type'],
                    $meta['category']    ?? null,
                    $meta['entity_type'],
                    !empty($meta['entity_id']) ? (int)$meta['entity_id'] : null,
                    $file['name'],
                    $storedName,
                    $relPath,
                    $file['size'],
                    $mimeType,
                    $version,
                    $parentId,
                    $meta['access_level'] ?? 'internal',
                    $userId,
                ]
            );
        } catch (Throwable $e) {
            @unlink($absPath); // rollback file
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }

        $this->logAccess($id, $userId, $parentId ? 'version' : 'upload');

        return [
            'success'  => true,
            'id'       => $id,
            'uuid'     => $uuid,
            'version'  => $version,
            'file_name'=> $file['name'],
            'file_size'=> $file['size'],
            'mime_type'=> $mimeType,
        ];
    }

    // ── List ───────────────────────────────────────────────────

    public function list(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ['d.is_deleted = 0', 'd.is_latest = 1'];
        $params = [];

        if (!empty($filters['entity_type'])) { $where[] = 'd.entity_type = ?'; $params[] = $filters['entity_type']; }
        if (!empty($filters['entity_id']))   { $where[] = 'd.entity_id = ?';   $params[] = (int)$filters['entity_id']; }
        if (!empty($filters['document_type'])){ $where[] = 'd.document_type = ?'; $params[] = $filters['document_type']; }
        if (!empty($filters['category']))    { $where[] = 'd.category = ?';    $params[] = $filters['category']; }
        if (!empty($filters['access_level'])){ $where[] = 'd.access_level = ?'; $params[] = $filters['access_level']; }
        if (!empty($filters['search']))      {
            $where[]  = '(d.title LIKE ? OR d.file_name LIKE ? OR d.description LIKE ?)';
            $s        = '%' . $filters['search'] . '%';
            $params   = array_merge($params, [$s, $s, $s]);
        }
        // Tenant isolation
        if (!empty($filters['tenant_id'])) {
            $where[] = "(
                (d.entity_type = 'tenant' AND d.entity_id = ?)
                OR d.access_level = 'shared'
            )";
            $params[] = (int)$filters['tenant_id'];
        }

        $w   = 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT d.uuid, d.title, d.document_type, d.category,
                    d.entity_type, d.entity_id, d.file_name, d.file_size,
                    d.mime_type, d.version, d.access_level, d.created_at,
                    CONCAT(u.first_name,' ',u.last_name) AS uploaded_by_name
                FROM documents d
                LEFT JOIN users u ON u.id = d.uploaded_by
                $w ORDER BY d.created_at DESC";

        $cntSql = "SELECT COUNT(*) FROM documents d $w";

        return $this->paginatedQuery($sql, $params, $cntSql, $params, $page, $perPage);
    }

    // ── Find ───────────────────────────────────────────────────

    public function find(string $uuid): ?array
    {
        return $this->fetchOne(
            "SELECT d.*,
                CONCAT(u.first_name,' ',u.last_name) AS uploaded_by_name,
                u.email AS uploaded_by_email,
                p.title AS parent_title, p.version AS parent_version
             FROM documents d
             LEFT JOIN users u     ON u.id  = d.uploaded_by
             LEFT JOIN documents p ON p.id  = d.parent_id
             WHERE d.uuid = ? AND d.is_deleted = 0",
            [$uuid]
        );
    }

    // ── Version history ────────────────────────────────────────

    public function versions(string $uuid): array
    {
        // Find the root of the version chain
        $doc = $this->fetchOne(
            "SELECT id, parent_id FROM documents WHERE uuid = ? AND is_deleted = 0", [$uuid]
        );
        if (!$doc) return [];

        // Walk up to root
        $rootId = $doc['id'];
        $pid    = $doc['parent_id'];
        while ($pid) {
            $par    = $this->fetchOne("SELECT id, parent_id FROM documents WHERE id = ?", [$pid]);
            $rootId = $par['id'];
            $pid    = $par['parent_id'] ?? null;
        }

        // Fetch entire chain
        return $this->fetchAll(
            "SELECT d.uuid, d.title, d.version, d.file_name, d.file_size,
                d.mime_type, d.is_latest, d.created_at,
                CONCAT(u.first_name,' ',u.last_name) AS uploaded_by_name
             FROM documents d
             LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE (d.id = ? OR d.parent_id = ?) AND d.is_deleted = 0
             ORDER BY d.version DESC",
            [$rootId, $rootId]
        );
    }

    // ── Stream / Download ──────────────────────────────────────

    /**
     * Validate access, log it, then stream the file to the browser.
     * Exits on success or returns ['success' => false] on error.
     */
    public function stream(string $uuid, int $userId, string $userRole, ?int $tenantId = null): array
    {
        $doc = $this->find($uuid);
        if (!$doc) return ['success' => false, 'error' => 'Document not found.', 'code' => 404];

        // Access control
        if (!$this->canAccess($doc, $userRole, $tenantId)) {
            return ['success' => false, 'error' => 'Access denied.', 'code' => 403];
        }

        $absPath = $this->storageBase . '/' . $doc['file_path'];
        if (!file_exists($absPath)) {
            return ['success' => false, 'error' => 'File not found on disk.', 'code' => 404];
        }

        $this->logAccess((int)$doc['id'], $userId, 'download');

        // Stream
        header('Content-Type: '        . $doc['mime_type']);
        header('Content-Length: '      . filesize($absPath));
        header('Content-Disposition: attachment; filename="' . addslashes($doc['file_name']) . '"');
        header('Cache-Control: private, no-cache');
        header('X-Content-Type-Options: nosniff');

        readfile($absPath);
        exit;
    }

    // ── Soft delete ────────────────────────────────────────────

    public function delete(string $uuid, int $userId, string $userRole): array
    {
        $doc = $this->find($uuid);
        if (!$doc) return ['success' => false, 'error' => 'Document not found.'];

        // Only admin/manager or the uploader can delete
        if (!in_array($userRole, ['admin', 'manager']) && (int)$doc['uploaded_by'] !== $userId) {
            return ['success' => false, 'error' => 'You do not have permission to delete this document.'];
        }

        $this->execute(
            "UPDATE documents SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? WHERE uuid = ?",
            [$userId, $uuid]
        );

        $this->logAccess((int)$doc['id'], $userId, 'delete');

        return ['success' => true];
    }

    // ── Update metadata ────────────────────────────────────────

    public function update(string $uuid, array $data, int $userId): array
    {
        $doc = $this->find($uuid);
        if (!$doc) return ['success' => false, 'error' => 'Document not found.'];

        $allowed = ['title', 'description', 'category', 'access_level'];
        [$set, $vals] = $this->buildSet($this->only($data, $allowed));
        if (!$set) return ['success' => false, 'error' => 'Nothing to update.'];

        $vals[] = $uuid;
        $this->execute("UPDATE documents SET $set WHERE uuid = ? AND is_deleted = 0", $vals);

        return ['success' => true];
    }

    // ── Access log query ───────────────────────────────────────

    public function accessLogs(string $uuid, int $limit = 50): array
    {
        return $this->fetchAll(
            "SELECT dal.action, dal.ip_address, dal.created_at,
                CONCAT(u.first_name,' ',u.last_name) AS user_name, u.role
             FROM document_access_logs dal
             JOIN documents d ON d.id = dal.document_id
             LEFT JOIN users u ON u.id = dal.user_id
             WHERE d.uuid = ?
             ORDER BY dal.created_at DESC
             LIMIT $limit",
            [$uuid]
        );
    }

    // ── Stats ──────────────────────────────────────────────────

    public function stats(): array
    {
        return $this->fetchOne(
            "SELECT
                COUNT(*)                               AS total,
                SUM(is_deleted = 0 AND is_latest = 1) AS active,
                SUM(is_deleted = 1)                    AS deleted,
                SUM(file_size) / 1024 / 1024           AS total_size_mb,
                SUM(document_type = 'lease')           AS lease_docs,
                SUM(document_type = 'tenant')          AS tenant_docs,
                SUM(document_type = 'property')        AS property_docs,
                SUM(document_type = 'certificate')     AS certificate_docs
             FROM documents"
        ) ?: [];
    }

    // ── Access control helper ──────────────────────────────────

    public function canAccess(array $doc, string $role, ?int $tenantId): bool
    {
        if (in_array($role, ['admin', 'manager', 'accountant', 'auditor'])) return true;

        // Tenant: only own docs + shared docs
        if ($role === 'tenant' && $tenantId) {
            if ($doc['access_level'] === 'shared') return true;
            if ($doc['entity_type'] === 'tenant' && (int)$doc['entity_id'] === $tenantId) return true;
            return false;
        }

        // Staff: internal + shared
        if ($doc['access_level'] === 'internal' || $doc['access_level'] === 'shared') return true;

        return false;
    }

    // ── Tenant ID lookup ───────────────────────────────────────

    public function getTenantIdForUser(int $userId): ?int
    {
        $row = $this->fetchOne("SELECT id FROM tenants WHERE user_id = ?", [$userId]);
        return $row ? (int)$row['id'] : null;
    }

    // ── Privates ───────────────────────────────────────────────

    private function generateUUID(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function logAccess(int $documentId, int $userId, string $action): void
    {
        try {
            $this->execute(
                "INSERT INTO document_access_logs (document_id, user_id, action, ip_address, user_agent) VALUES (?,?,?,?,?)",
                [
                    $documentId,
                    $userId,
                    $action,
                    $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
                    isset($_SERVER['HTTP_USER_AGENT']) ? mb_strimwidth($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
                ]
            );
        } catch (Throwable) {}
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum allowed size.',
            UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporary directory missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            default              => "Upload error (code $code).",
        };
    }
}
