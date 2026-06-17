-- ============================================================
-- RUMS Migration 008 — Report Analytics
-- Scheduled reports: store schedule definitions; runs are
-- tracked in communication_logs (email) or just logged here.
-- ============================================================

CREATE TABLE IF NOT EXISTS report_schedules (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150)  NOT NULL,
    report_type   ENUM(
                    'financial','occupancy','rent_collection',
                    'arrears','maintenance','tenant_analytics',
                    'aging','deposits','dashboard'
                  ) NOT NULL,
    format        ENUM('csv','pdf') NOT NULL DEFAULT 'csv',
    filters       JSON          NULL COMMENT 'e.g. {"property_id":1,"year":2025}',
    frequency     ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'monthly',
    -- For weekly: 0=Sun…6=Sat. For monthly: 1-28.
    run_day       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    run_hour      TINYINT UNSIGNED NOT NULL DEFAULT 7  COMMENT '24h, server TZ',
    recipients    JSON          NOT NULL COMMENT 'array of email strings',
    is_active     TINYINT(1)    NOT NULL DEFAULT 1,
    last_run_at   DATETIME      NULL,
    next_run_at   DATETIME      NULL,
    created_by    INT UNSIGNED  NOT NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_report_schedules_active_next ON report_schedules (is_active, next_run_at);
