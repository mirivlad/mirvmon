-- level_shift_v2 replaces v1 episode lifecycle. Existing accepted-normal v1
-- patterns are intentionally kept so v2 can use them as compatibility hints.
UPDATE observations
SET status = 'resolved',
    resolved_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
WHERE kind = 'anomaly'
  AND detector = 'level_shift_v1'
  AND status IN ('active', 'handled');
