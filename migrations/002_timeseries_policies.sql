CREATE MATERIALIZED VIEW metric_samples_hourly
WITH (timescaledb.continuous) AS
SELECT
    time_bucket(INTERVAL '1 hour', sample_time) AS bucket,
    server_id,
    metric_id,
    avg(value) AS avg_value,
    min(value) AS min_value,
    max(value) AS max_value,
    count(*) AS sample_count
FROM metric_samples
GROUP BY bucket, server_id, metric_id
WITH NO DATA;

CREATE INDEX metric_samples_hourly_lookup_idx
    ON metric_samples_hourly(server_id, metric_id, bucket DESC);

CREATE MATERIALIZED VIEW metric_samples_daily
WITH (timescaledb.continuous) AS
SELECT
    time_bucket(INTERVAL '1 day', sample_time) AS bucket,
    server_id,
    metric_id,
    avg(value) AS avg_value,
    min(value) AS min_value,
    max(value) AS max_value,
    count(*) AS sample_count
FROM metric_samples
GROUP BY bucket, server_id, metric_id
WITH NO DATA;

CREATE INDEX metric_samples_daily_lookup_idx
    ON metric_samples_daily(server_id, metric_id, bucket DESC);

SELECT add_continuous_aggregate_policy(
    'metric_samples_hourly',
    start_offset => INTERVAL '7 days',
    end_offset => INTERVAL '5 minutes',
    schedule_interval => INTERVAL '5 minutes',
    if_not_exists => TRUE
);

SELECT add_continuous_aggregate_policy(
    'metric_samples_daily',
    start_offset => INTERVAL '61 days',
    end_offset => INTERVAL '1 hour',
    schedule_interval => INTERVAL '1 hour',
    if_not_exists => TRUE
);

ALTER TABLE metric_samples SET (
    timescaledb.enable_columnstore = TRUE,
    timescaledb.segmentby = 'server_id,metric_id',
    timescaledb.orderby = 'sample_time DESC,sample_id'
);

CALL add_columnstore_policy(
    'metric_samples',
    after => INTERVAL '7 days',
    if_not_exists => TRUE
);

ALTER TABLE process_snapshots SET (
    timescaledb.enable_columnstore = TRUE,
    timescaledb.segmentby = 'server_id',
    timescaledb.orderby = 'sample_time DESC,sample_id'
);

CALL add_columnstore_policy(
    'process_snapshots',
    after => INTERVAL '7 days',
    if_not_exists => TRUE
);

SELECT add_retention_policy(
    'metric_samples',
    drop_after => INTERVAL '60 days',
    if_not_exists => TRUE
);

SELECT add_retention_policy(
    'process_snapshots',
    drop_after => INTERVAL '60 days',
    if_not_exists => TRUE
);

SELECT add_retention_policy(
    'metric_samples_hourly',
    drop_after => INTERVAL '730 days',
    if_not_exists => TRUE
);
