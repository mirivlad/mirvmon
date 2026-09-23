ALTER TABLE website_check_jobs
    ADD COLUMN failed_at TIMESTAMPTZ;

ALTER TABLE website_check_jobs
    DROP CONSTRAINT website_check_jobs_state_check;
ALTER TABLE website_check_jobs
    ADD CONSTRAINT website_check_jobs_state_check
    CHECK (state IN ('pending', 'leased', 'failed'));

ALTER TABLE website_check_jobs
    DROP CONSTRAINT website_check_jobs_lease_shape_check;
ALTER TABLE website_check_jobs
    ADD CONSTRAINT website_check_jobs_lease_shape_check CHECK (
        (state IN ('pending', 'failed') AND lease_owner IS NULL AND lease_until IS NULL)
        OR (state = 'leased' AND lease_owner IS NOT NULL AND lease_until IS NOT NULL)
    );

ALTER TABLE website_check_jobs
    ADD CONSTRAINT website_check_jobs_failed_at_check CHECK (
        (state = 'failed' AND failed_at IS NOT NULL)
        OR (state <> 'failed' AND failed_at IS NULL)
    );

UPDATE website_check_jobs
SET state = 'failed',
    lease_owner = NULL,
    lease_until = NULL,
    failed_at = CURRENT_TIMESTAMP,
    safe_error_kind = COALESCE(safe_error_kind, 'retry_exhausted')
WHERE attempts >= 10
  AND (state = 'pending' OR lease_until < CURRENT_TIMESTAMP);

CREATE INDEX website_check_jobs_failed_idx
    ON website_check_jobs (failed_at DESC)
    WHERE state = 'failed';

CREATE INDEX website_check_jobs_exhausted_idx
    ON website_check_jobs (lease_until)
    WHERE attempts >= 10 AND state IN ('pending', 'leased');
