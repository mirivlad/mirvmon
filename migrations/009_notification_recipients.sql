-- A single global mailbox could not express "this server is Ivan's problem".
ALTER TABLE servers
    ADD COLUMN notification_telegram_chat_id VARCHAR(100),
    ADD COLUMN notification_emails JSONB NOT NULL DEFAULT '[]'::jsonb;

ALTER TABLE notification_settings
    ADD COLUMN smtp_recipients JSONB NOT NULL DEFAULT '[]'::jsonb;

UPDATE notification_settings
SET smtp_recipients = jsonb_build_array(smtp_recipient_email)
WHERE smtp_recipient_email IS NOT NULL;

ALTER TABLE notification_settings
    DROP COLUMN smtp_recipient_email;

-- One row per recipient, so a rejected address cannot hold up the others.
ALTER TABLE notification_outbox
    ADD COLUMN recipient VARCHAR(320);
