-- Landlord notification preferences: which channels are active
CREATE TABLE IF NOT EXISTS notification_preferences (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    landlord_id   INT UNSIGNED NOT NULL,
    email_enabled TINYINT(1)   NOT NULL DEFAULT 1,
    sms_enabled   TINYINT(1)   NOT NULL DEFAULT 1,
    inapp_enabled TINYINT(1)   NOT NULL DEFAULT 1,
    -- Granular event toggles
    notify_payment      TINYINT(1) NOT NULL DEFAULT 1,
    notify_maintenance  TINYINT(1) NOT NULL DEFAULT 1,
    notify_lease_expiry TINYINT(1) NOT NULL DEFAULT 1,
    notify_overdue      TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_landlord (landlord_id),
    CONSTRAINT fk_notifpref_landlord FOREIGN KEY (landlord_id) REFERENCES landlords(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
