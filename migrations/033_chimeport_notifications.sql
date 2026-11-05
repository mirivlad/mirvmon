-- ChimePort is a third independent outbox transport.
-- Existing Email and Telegram rows, delivery state and deduplication keys stay untouched.
ALTER TABLE notification_outbox DROP CONSTRAINT notification_outbox_channel_check;
ALTER TABLE notification_outbox ADD CONSTRAINT notification_outbox_channel_check
    CHECK (channel IN ('email', 'telegram', 'chimeport'));
