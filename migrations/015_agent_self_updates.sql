ALTER TABLE servers
    ADD COLUMN agent_artifact VARCHAR(64),
    ADD COLUMN agent_capabilities JSONB NOT NULL DEFAULT '[]'::jsonb
        CHECK (jsonb_typeof(agent_capabilities) = 'array');

CREATE TABLE agent_update_commands (
    id UUID PRIMARY KEY,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    target_version VARCHAR(32) NOT NULL,
    target_artifact VARCHAR(64) NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'pending'
        CHECK (state IN (
            'pending',
            'accepted',
            'downloading',
            'installing',
            'awaiting_restart',
            'succeeded',
            'failed'
        )),
    error_code VARCHAR(64),
    requested_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMPTZ,
    CHECK (
        (state IN ('succeeded', 'failed') AND completed_at IS NOT NULL)
        OR (state NOT IN ('succeeded', 'failed') AND completed_at IS NULL)
    )
);

CREATE UNIQUE INDEX agent_update_commands_one_active
    ON agent_update_commands(server_id)
    WHERE state NOT IN ('succeeded', 'failed');

CREATE INDEX agent_update_commands_server_created
    ON agent_update_commands(server_id, created_at DESC);
