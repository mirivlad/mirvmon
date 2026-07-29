CREATE TABLE current_metric_values (
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    metric_id BIGINT NOT NULL REFERENCES metric_names(id) ON DELETE CASCADE,
    sample_time TIMESTAMPTZ NOT NULL,
    sample_id UUID NOT NULL,
    value DOUBLE PRECISION NOT NULL,
    PRIMARY KEY (server_id, metric_id)
);

CREATE INDEX current_metric_values_sample_time_idx
    ON current_metric_values(sample_time DESC);
