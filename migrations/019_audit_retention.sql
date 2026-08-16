-- Audit rows are immutable during ordinary application/database operations.
-- Retention is the only supported deletion path and must be entered explicitly
-- through mirvmon_prune_audit_log().

CREATE OR REPLACE FUNCTION mirvmon_reject_audit_log_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE'
       AND current_setting('mirvmon.audit_retention_prune', true) = 'on' THEN
        RETURN OLD;
    END IF;

    RAISE EXCEPTION 'audit_log is append-only';
END;
$$;

CREATE OR REPLACE FUNCTION mirvmon_prune_audit_log(cutoff timestamptz)
RETURNS bigint
LANGUAGE plpgsql
AS $$
DECLARE
    deleted_rows bigint;
BEGIN
    IF cutoff IS NULL OR cutoff >= CURRENT_TIMESTAMP THEN
        RAISE EXCEPTION 'invalid audit retention cutoff';
    END IF;

    PERFORM set_config('mirvmon.audit_retention_prune', 'on', true);
    DELETE FROM audit_log WHERE occurred_at < cutoff;
    GET DIAGNOSTICS deleted_rows = ROW_COUNT;
    PERFORM set_config('mirvmon.audit_retention_prune', 'off', true);

    RETURN deleted_rows;
END;
$$;

COMMENT ON FUNCTION mirvmon_prune_audit_log(timestamptz) IS
    'Controlled retention-only deletion path for immutable MirvMon audit history.';
