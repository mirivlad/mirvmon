ALTER TABLE agent_configs
    ADD COLUMN website_probe_enabled BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE websites
    ADD COLUMN central_probe_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN probe_quorum SMALLINT NOT NULL DEFAULT 1
        CHECK (probe_quorum BETWEEN 1 AND 1000);

CREATE TABLE website_probe_agents (
    website_id BIGINT NOT NULL REFERENCES websites(id) ON DELETE CASCADE,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (website_id, server_id)
);

CREATE INDEX website_probe_agents_server_idx
    ON website_probe_agents(server_id, website_id);

CREATE TABLE website_probe_samples (
    sample_time TIMESTAMPTZ NOT NULL,
    website_id BIGINT NOT NULL REFERENCES websites(id) ON DELETE CASCADE,
    endpoint_id BIGINT NOT NULL,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    sample_id UUID NOT NULL,
    transport_available BOOLEAN NOT NULL,
    status_code SMALLINT CHECK (status_code BETWEEN 100 AND 599),
    total_ms DOUBLE PRECISION CHECK (total_ms >= 0),
    error_kind VARCHAR(40),
    safe_message VARCHAR(500),
    PRIMARY KEY (sample_time, endpoint_id, server_id, sample_id),
    CONSTRAINT website_probe_samples_endpoint_site_fk
        FOREIGN KEY (endpoint_id, website_id)
        REFERENCES website_endpoints(id, website_id) ON DELETE CASCADE
);

SELECT create_hypertable(
    'website_probe_samples',
    by_range('sample_time', INTERVAL '1 day'),
    if_not_exists => TRUE,
    create_default_indexes => FALSE
);

CREATE INDEX website_probe_samples_lookup_idx
    ON website_probe_samples(website_id, endpoint_id, server_id, sample_time DESC);

CREATE INDEX website_probe_samples_server_idx
    ON website_probe_samples(server_id, sample_time DESC);

SELECT add_retention_policy(
    'website_probe_samples',
    drop_after => INTERVAL '30 days',
    if_not_exists => TRUE
);
