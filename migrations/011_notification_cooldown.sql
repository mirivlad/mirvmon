-- Opt-in rate limit for a repeating event: zero keeps the previous behaviour.
ALTER TABLE notification_settings
    ADD COLUMN cooldown_seconds INTEGER NOT NULL DEFAULT 0
        CHECK (cooldown_seconds BETWEEN 0 AND 86400);

CREATE INDEX notification_outbox_cooldown_idx
    ON notification_outbox(server_id, event_type, created_at DESC)
    WHERE server_id IS NOT NULL;
