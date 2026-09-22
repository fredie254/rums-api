-- Per-landlord M-Pesa credentials
-- Each landlord registers their own Safaricom shortcode/paybill.
-- All three webhook URLs point to the same RUMS endpoint;
-- the BusinessShortCode in the payload identifies which landlord.

CREATE TABLE IF NOT EXISTS mpesa_configs (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id         INT            NOT NULL,
    shortcode           VARCHAR(20)    NOT NULL,
    shortcode_type      ENUM('paybill','till') NOT NULL DEFAULT 'paybill',
    consumer_key        TEXT           NOT NULL,
    consumer_secret     TEXT           NOT NULL,
    passkey             TEXT           NOT NULL,
    environment         ENUM('sandbox','production') NOT NULL DEFAULT 'production',
    urls_registered     TINYINT(1)     NOT NULL DEFAULT 0,
    urls_registered_at  DATETIME       NULL,
    is_active           TINYINT(1)     NOT NULL DEFAULT 1,
    notes               TEXT           NULL,
    created_by          INT            NULL,
    created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_landlord  (landlord_id),
    UNIQUE KEY uk_shortcode (shortcode),
    FOREIGN KEY fk_mc_landlord  (landlord_id) REFERENCES landlords (id) ON DELETE CASCADE,
    FOREIGN KEY fk_mc_creator   (created_by)  REFERENCES users    (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
