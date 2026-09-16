CREATE TABLE observations (
    id BIGSERIAL PRIMARY KEY,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    metric_id BIGINT REFERENCES metric_names(id) ON DELETE SET NULL,
    kind VARCHAR(20) NOT NULL CHECK (kind IN ('anomaly', 'prediction')),
    detector VARCHAR(50) NOT NULL,
    fingerprint VARCHAR(255) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'handled', 'accepted_normal', 'resolved')),
    current_value DOUBLE PRECISION,
    baseline_value DOUBLE PRECISION,
    confidence DOUBLE PRECISION CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1)),
    forecast_at TIMESTAMPTZ,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    recurrence_count INTEGER NOT NULL DEFAULT 1 CHECK (recurrence_count >= 1),
    notification_cycle INTEGER NOT NULL DEFAULT 1 CHECK (notification_cycle >= 1),
    first_seen_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at TIMESTAMPTZ,
    handled_at TIMESTAMPTZ,
    handled_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    handled_by_username VARCHAR(80),
    accepted_at TIMESTAMPTZ,
    accepted_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    accepted_by_username VARCHAR(80),
    resolved_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (server_id, fingerprint)
);

CREATE INDEX observations_active_idx
    ON observations(status, last_seen_at DESC)
    WHERE status = 'active';
CREATE INDEX observations_server_history_idx
    ON observations(server_id, last_seen_at DESC);
CREATE INDEX observations_kind_status_idx
    ON observations(kind, status, last_seen_at DESC);

ALTER TABLE notification_outbox
    ADD COLUMN observation_id BIGINT REFERENCES observations(id) ON DELETE SET NULL;

CREATE INDEX notification_outbox_observation_idx
    ON notification_outbox(observation_id)
    WHERE observation_id IS NOT NULL;
