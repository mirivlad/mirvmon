CREATE TABLE server_availability_state (
    server_id BIGINT PRIMARY KEY REFERENCES servers(id) ON DELETE CASCADE,
    state VARCHAR(10) NOT NULL CHECK (state IN ('online', 'offline')),
    changed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE server_availability_events (
    id BIGSERIAL PRIMARY KEY,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    state VARCHAR(10) NOT NULL CHECK (state IN ('online', 'offline')),
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX server_availability_events_server_time_idx
    ON server_availability_events(server_id, occurred_at DESC);

INSERT INTO server_availability_state (server_id, state, changed_at)
SELECT
    servers.id,
    CASE
        WHEN servers.is_active = TRUE
         AND agent_tokens.last_used_at IS NOT NULL
         AND (
            servers.offline_timeout_seconds = 0
            OR agent_tokens.last_used_at > CURRENT_TIMESTAMP
                - servers.offline_timeout_seconds * INTERVAL '1 second'
         )
        THEN 'online'
        ELSE 'offline'
    END,
    CURRENT_TIMESTAMP
FROM servers
LEFT JOIN agent_tokens ON agent_tokens.server_id = servers.id;

INSERT INTO server_availability_events (server_id, state, occurred_at)
SELECT server_id, state, changed_at
FROM server_availability_state;
