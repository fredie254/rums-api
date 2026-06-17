-- ── Migration 010: Multi-Factor Authentication ──────────────────
-- TOTP-based 2FA per user with backup codes and pending challenge tokens.

CREATE TABLE mfa_secrets (
    id          INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED   NOT NULL UNIQUE,
    secret      VARCHAR(512)   NOT NULL,              -- AES-256-GCM encrypted TOTP base32 secret
    is_enabled  TINYINT(1)     NOT NULL DEFAULT 0,
    enabled_at  DATETIME       NULL,
    created_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- One-time backup codes (8 per user), stored as bcrypt hashes
CREATE TABLE mfa_backup_codes (
    id          INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED   NOT NULL,
    code_hash   VARCHAR(255)   NOT NULL,              -- password_hash of the 8-char code
    used_at     DATETIME       NULL,
    created_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX idx_mfa_backup_user ON mfa_backup_codes (user_id);

-- Short-lived MFA challenge tokens (TTL 10 min), cleaned up by cron
CREATE TABLE mfa_pending (
    id              INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED   NOT NULL,
    pending_token   CHAR(64)       NOT NULL UNIQUE,   -- random_bytes(32) hex
    expires_at      DATETIME       NOT NULL,
    used            TINYINT(1)     NOT NULL DEFAULT 0,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX idx_mfa_pending_token   ON mfa_pending (pending_token);
CREATE INDEX idx_mfa_pending_expires ON mfa_pending (expires_at);
