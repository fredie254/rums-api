-- ============================================================
-- RUMS Migration 009 — Document Management
-- Secure document storage with versioning and access logging.
-- ============================================================

CREATE TABLE IF NOT EXISTS documents (
    id            INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    uuid          CHAR(36)       NOT NULL UNIQUE          COMMENT 'Public identifier (v4 UUID)',
    title         VARCHAR(200)   NOT NULL,
    description   TEXT           NULL,
    document_type ENUM(
                    'lease','tenant','property','certificate',
                    'financial','maintenance','other'
                  ) NOT NULL DEFAULT 'other',
    category      VARCHAR(100)   NULL                     COMMENT 'Sub-type within document_type',
    -- What entity this document belongs to
    entity_type   ENUM('lease','tenant','property','unit','general') NOT NULL DEFAULT 'general',
    entity_id     INT UNSIGNED   NULL,
    -- File storage
    file_name     VARCHAR(255)   NOT NULL                 COMMENT 'Original filename shown to users',
    stored_name   VARCHAR(255)   NOT NULL                 COMMENT 'UUID-based disk name',
    file_path     VARCHAR(500)   NOT NULL                 COMMENT 'Relative to DOCUMENT_STORAGE',
    file_size     INT UNSIGNED   NOT NULL DEFAULT 0       COMMENT 'Bytes',
    mime_type     VARCHAR(100)   NOT NULL,
    -- Versioning
    version       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    parent_id     INT UNSIGNED   NULL                     COMMENT 'Previous version\'s document id',
    is_latest     TINYINT(1)     NOT NULL DEFAULT 1,
    -- Access control
    access_level  ENUM('private','internal','shared') NOT NULL DEFAULT 'internal'
                  COMMENT 'private=uploader only, internal=staff, shared=tenant+staff',
    -- Soft delete
    is_deleted    TINYINT(1)     NOT NULL DEFAULT 0,
    deleted_at    DATETIME       NULL,
    deleted_by    INT UNSIGNED   NULL,
    -- Audit
    uploaded_by   INT UNSIGNED   NOT NULL,
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (parent_id)   REFERENCES documents(id) ON DELETE SET NULL,
    FOREIGN KEY (deleted_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Indexes ───────────────────────────────────────────────────
CREATE INDEX idx_docs_entity      ON documents (entity_type, entity_id);
CREATE INDEX idx_docs_type        ON documents (document_type, is_deleted);
CREATE INDEX idx_docs_latest      ON documents (uuid, is_latest, is_deleted);
CREATE INDEX idx_docs_parent      ON documents (parent_id);

-- ── Access log ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS document_access_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    action      ENUM('view','download','delete','upload','version') NOT NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  VARCHAR(500) NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    INDEX (document_id),
    INDEX (user_id),
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
