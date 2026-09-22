-- Per-landlord message template overrides.
-- landlord_id = NULL  →  global default (managed by super admin, existing rows)
-- landlord_id = N     →  landlord N's custom version of that template

ALTER TABLE message_templates
    ADD COLUMN landlord_id INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'NULL = global default; set = landlord override'
        AFTER id;

ALTER TABLE message_templates
    ADD CONSTRAINT fk_mt_landlord
        FOREIGN KEY (landlord_id) REFERENCES landlords (id) ON DELETE CASCADE;

-- One custom override per landlord per category+channel combination.
-- Landlords can have a different "payment SMS" from the default, but not two.
ALTER TABLE message_templates
    ADD UNIQUE KEY uk_landlord_cat_chan (landlord_id, category, channel);

-- Ensure all existing rows are treated as global defaults.
UPDATE message_templates SET landlord_id = NULL WHERE landlord_id IS NULL;
