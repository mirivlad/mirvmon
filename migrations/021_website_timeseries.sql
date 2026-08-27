CREATE TABLE website_check_samples (
    sample_time TIMESTAMPTZ NOT NULL,
    website_id BIGINT NOT NULL REFERENCES websites(id) ON DELETE CASCADE,
    endpoint_id BIGINT NOT NULL,
    sample_id UUID NOT NULL,
    probe_kind VARCHAR(10) NOT NULL DEFAULT 'app' CHECK (probe_kind = 'app'),
    probe_id VARCHAR(100),
    manual BOOLEAN NOT NULL DEFAULT FALSE,
    transport_available BOOLEAN NOT NULL,
    assertions_passed BOOLEAN NOT NULL,
    status_code SMALLINT CHECK (status_code BETWEEN 100 AND 599),
    configured_url TEXT NOT NULL,
    final_url TEXT,
    redirect_count SMALLINT NOT NULL DEFAULT 0 CHECK (redirect_count BETWEEN 0 AND 10),
    dns_ms DOUBLE PRECISION,
    tcp_ms DOUBLE PRECISION,
    tls_ms DOUBLE PRECISION,
    ttfb_ms DOUBLE PRECISION,
    total_ms DOUBLE PRECISION,
    error_kind VARCHAR(40),
    diagnostics JSONB NOT NULL DEFAULT '{}'::jsonb,
    PRIMARY KEY (sample_time, endpoint_id, sample_id),
    CONSTRAINT website_check_samples_endpoint_site_fk
        FOREIGN KEY (endpoint_id, website_id)
        REFERENCES website_endpoints(id, website_id) ON DELETE CASCADE,
    CONSTRAINT website_check_samples_app_probe_check CHECK (
        probe_kind <> 'app' OR probe_id IS NULL
    )
);

SELECT create_hypertable(
    'website_check_samples',
    by_range('sample_time', INTERVAL '1 day'),
    if_not_exists => TRUE,
    create_default_indexes => FALSE
);

CREATE INDEX website_check_samples_lookup_idx
    ON website_check_samples(website_id, endpoint_id, sample_time DESC);
CREATE INDEX website_check_samples_sample_id_idx
    ON website_check_samples(website_id, sample_id, sample_time DESC);

CREATE MATERIALIZED VIEW website_check_samples_hourly
WITH (timescaledb.continuous) AS
SELECT
    time_bucket(INTERVAL '1 hour', sample_time) AS bucket,
    website_id,
    endpoint_id,
    count(*) AS sample_count,
    sum(transport_available::integer) AS transport_available_count,
    sum(assertions_passed::integer) AS assertions_passed_count,
    avg(transport_available::integer) AS transport_available_ratio,
    avg(assertions_passed::integer) AS assertions_passed_ratio,
    count(ttfb_ms) AS ttfb_count,
    min(ttfb_ms) AS ttfb_min_ms,
    avg(ttfb_ms) AS ttfb_avg_ms,
    max(ttfb_ms) AS ttfb_max_ms,
    count(total_ms) AS total_count,
    min(total_ms) AS total_min_ms,
    avg(total_ms) AS total_avg_ms,
    max(total_ms) AS total_max_ms
FROM website_check_samples
GROUP BY bucket, website_id, endpoint_id
WITH NO DATA;

CREATE INDEX website_check_samples_hourly_lookup_idx
    ON website_check_samples_hourly(website_id, endpoint_id, bucket DESC);

CREATE MATERIALIZED VIEW website_check_samples_daily
WITH (timescaledb.continuous) AS
SELECT
    time_bucket(INTERVAL '1 day', sample_time) AS bucket,
    website_id,
    endpoint_id,
    count(*) AS sample_count,
    sum(transport_available::integer) AS transport_available_count,
    sum(assertions_passed::integer) AS assertions_passed_count,
    avg(transport_available::integer) AS transport_available_ratio,
    avg(assertions_passed::integer) AS assertions_passed_ratio,
    count(ttfb_ms) AS ttfb_count,
    min(ttfb_ms) AS ttfb_min_ms,
    avg(ttfb_ms) AS ttfb_avg_ms,
    max(ttfb_ms) AS ttfb_max_ms,
    count(total_ms) AS total_count,
    min(total_ms) AS total_min_ms,
    avg(total_ms) AS total_avg_ms,
    max(total_ms) AS total_max_ms
FROM website_check_samples
GROUP BY bucket, website_id, endpoint_id
WITH NO DATA;

CREATE INDEX website_check_samples_daily_lookup_idx
    ON website_check_samples_daily(website_id, endpoint_id, bucket DESC);

SELECT add_continuous_aggregate_policy(
    'website_check_samples_hourly',
    start_offset => INTERVAL '30 days',
    end_offset => INTERVAL '5 minutes',
    schedule_interval => INTERVAL '5 minutes',
    if_not_exists => TRUE
);

SELECT add_continuous_aggregate_policy(
    'website_check_samples_daily',
    start_offset => INTERVAL '31 days',
    end_offset => INTERVAL '1 hour',
    schedule_interval => INTERVAL '1 hour',
    if_not_exists => TRUE
);

ALTER TABLE website_check_samples SET (
    timescaledb.enable_columnstore = TRUE,
    timescaledb.segmentby = 'website_id,endpoint_id',
    timescaledb.orderby = 'sample_time DESC,sample_id'
);

CALL add_columnstore_policy(
    'website_check_samples',
    after => INTERVAL '7 days',
    if_not_exists => TRUE
);

SELECT add_retention_policy(
    'website_check_samples',
    drop_after => INTERVAL '30 days',
    if_not_exists => TRUE
);

SELECT add_retention_policy(
    'website_check_samples_hourly',
    drop_after => INTERVAL '365 days',
    if_not_exists => TRUE
);
