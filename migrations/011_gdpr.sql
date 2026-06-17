-- ── Migration 011: GDPR Compliance ──────────────────────────────
-- Consent tracking, data export requests, and deletion requests.

-- Tracks user consent to terms/privacy/marketing with version + IP
CREATE TABLE consent_records (
    id            INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED   NOT NULL,
    consent_type  ENUM('terms','privacy','marketing') NOT NULL,
    version       VARCHAR(20)    NOT NULL DEFAULT '1.0',
    consented     TINYINT(1)     NOT NULL DEFAULT 1,   -- 1=given, 0=withdrawn
    ip_address    VARCHAR(45)    NULL,
    user_agent    VARCHAR(500)   NULL,
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX idx_consent_user_type ON consent_records (user_id, consent_type);

-- Data export requests (right to portability)
CREATE TABLE data_export_requests (
    id            INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED   NOT NULL,
    status        ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    download_token CHAR(64)      NULL,                 -- one-time download token
    token_expires  DATETIME      NULL,
    completed_at  DATETIME       NULL,
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX idx_export_user    ON data_export_requests (user_id);
CREATE INDEX idx_export_token   ON data_export_requests (download_token);

-- Data deletion requests (right to erasure, GDPR Art. 17)
CREATE TABLE data_deletion_requests (
    id            INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED   NOT NULL,
    reason        TEXT           NULL,
    status        ENUM('pending','processing','completed','rejected') NOT NULL DEFAULT 'pending',
    requested_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at  DATETIME       NULL,
    processed_by  INT UNSIGNED   NULL,
    admin_notes   TEXT           NULL,
    FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX idx_deletion_user   ON data_deletion_requests (user_id);
CREATE INDEX idx_deletion_status ON data_deletion_requests (status);

-- Soft-add: data retention policy column on users
ALTER TABLE users ADD COLUMN IF NOT EXISTS
    data_anonymized   TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

ALTER TABLE users ADD COLUMN IF NOT EXISTS
    anonymized_at     DATETIME   NULL                AFTER data_anonymized;
