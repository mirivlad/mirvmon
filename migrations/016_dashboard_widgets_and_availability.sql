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

-- display_metrics now stores dashboard widget ids rather than every raw metric.
-- Keep the existing column so deployments can migrate without a destructive
-- schema change, but collapse paired/supporting metrics into one UI choice.
UPDATE servers AS server
SET display_metrics = COALESCE((
    SELECT jsonb_agg(widget ORDER BY widget)
    FROM (
        SELECT DISTINCT CASE
            WHEN value IN ('cpu_load', 'ram_used', 'uptime') THEN value
            WHEN value = 'temperature' OR value LIKE 'temp\_%' ESCAPE '\\'
                THEN 'temperatures'
            WHEN value LIKE 'disk_used\_%' ESCAPE '\\'
                THEN value
            WHEN value LIKE 'net_in\_%' ESCAPE '\\'
                THEN 'net_' || substr(value, 8)
            WHEN value LIKE 'net_out\_%' ESCAPE '\\'
                THEN 'net_' || substr(value, 9)
            WHEN value LIKE 'disk_read\_%' ESCAPE '\\'
                THEN 'disk_io_' || substr(value, 11)
            WHEN value LIKE 'disk_write\_%' ESCAPE '\\'
                THEN 'disk_io_' || substr(value, 12)
            ELSE NULL
        END AS widget
        FROM jsonb_array_elements_text(server.display_metrics) AS selected(value)
    ) AS converted
    WHERE widget IS NOT NULL
), '[]'::jsonb);
