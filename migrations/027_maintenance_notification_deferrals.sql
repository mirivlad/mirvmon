-- Maintenance suppresses delivery, not monitoring. Persist the latest suppressed
-- notification so an issue that survives the window can be reported once after
-- maintenance ends.
CREATE TABLE maintenance_notification_deferrals (
    id BIGSERIAL PRIMARY KEY,
    server_id BIGINT REFERENCES servers(id) ON DELETE CASCADE,
    website_id BIGINT REFERENCES websites(id) ON DELETE CASCADE,
    alert_id BIGINT REFERENCES alerts(id) ON DELETE CASCADE,
    observation_id BIGINT REFERENCES observations(id) ON DELETE CASCADE,
    event_type VARCHAR(50) NOT NULL,
    payload JSONB NOT NULL,
    deduplication_key VARCHAR(255) NOT NULL,
    suppressed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK ((server_id IS NOT NULL)::integer + (website_id IS NOT NULL)::integer = 1),
    CHECK ((alert_id IS NOT NULL)::integer + (observation_id IS NOT NULL)::integer = 1)
);

CREATE UNIQUE INDEX maintenance_notification_deferrals_alert_idx
    ON maintenance_notification_deferrals(alert_id)
    WHERE alert_id IS NOT NULL;

CREATE UNIQUE INDEX maintenance_notification_deferrals_observation_idx
    ON maintenance_notification_deferrals(observation_id)
    WHERE observation_id IS NOT NULL;

CREATE INDEX maintenance_notification_deferrals_server_idx
    ON maintenance_notification_deferrals(server_id, suppressed_at)
    WHERE server_id IS NOT NULL;

CREATE INDEX maintenance_notification_deferrals_website_idx
    ON maintenance_notification_deferrals(website_id, suppressed_at)
    WHERE website_id IS NOT NULL;
