-- Planned work should not page anyone: an open window suppresses the
-- notification, while the alert itself is still recorded and visible.
CREATE TABLE maintenance_windows (
    id BIGSERIAL PRIMARY KEY,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    starts_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ends_at TIMESTAMPTZ NOT NULL,
    reason VARCHAR(255),
    created_by VARCHAR(80),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Equal timestamps mean a window cancelled at once, which active() treats
    -- as closed because it compares ends_at strictly against the clock.
    CONSTRAINT maintenance_windows_period_check CHECK (ends_at >= starts_at)
);

CREATE INDEX maintenance_windows_server_idx
    ON maintenance_windows(server_id, ends_at DESC);
