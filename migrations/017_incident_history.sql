ALTER TABLE alerts
    ADD COLUMN resolved_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN resolved_by_username VARCHAR(80);

CREATE INDEX alerts_history_resolved_at_idx
    ON alerts(resolved_at DESC, id DESC)
    WHERE resolved = TRUE;

CREATE INDEX server_availability_events_state_time_idx
    ON server_availability_events(state, occurred_at DESC, id DESC);
