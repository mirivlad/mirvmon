ALTER TABLE observations
    DROP CONSTRAINT IF EXISTS observations_server_id_fingerprint_key;

-- v0.7.0 could leave several band-specific anomaly rows active for one metric.
-- Keep only the most recently seen row as the current episode before enforcing
-- the v0.7.2 invariant: one open anomaly episode per detector + metric.
WITH ranked AS (
    SELECT id,
           row_number() OVER (
               PARTITION BY server_id, metric_id, detector
               ORDER BY last_seen_at DESC, id DESC
           ) AS position
    FROM observations
    WHERE kind = 'anomaly' AND status IN ('active', 'handled')
)
UPDATE observations
SET status = 'resolved',
    resolved_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
FROM ranked
WHERE observations.id = ranked.id
  AND ranked.position > 1;

CREATE UNIQUE INDEX observations_prediction_fingerprint_unique
    ON observations(server_id, fingerprint)
    WHERE kind = 'prediction';

CREATE UNIQUE INDEX observations_anomaly_open_episode_unique
    ON observations(server_id, metric_id, detector)
    WHERE kind = 'anomaly' AND status IN ('active', 'handled');

CREATE INDEX observations_anomaly_normal_pattern_idx
    ON observations(server_id, fingerprint, accepted_at DESC)
    WHERE kind = 'anomaly' AND status = 'accepted_normal';
