-- NULL means that an existing installation still uses its Docker environment.
-- As soon as the admin saves the web form, the explicit DB configuration wins.
ALTER TABLE notification_settings
    ADD COLUMN chimeport_enabled BOOLEAN DEFAULT NULL,
    ADD COLUMN chimeport_base_url VARCHAR(512),
    ADD COLUMN chimeport_channel VARCHAR(80),
    ADD COLUMN chimeport_token_encrypted BYTEA;
