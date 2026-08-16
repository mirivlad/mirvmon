CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_user_id BIGINT,
    actor_username VARCHAR(80) NOT NULL,
    actor_role VARCHAR(20),
    action VARCHAR(80) NOT NULL,
    object_type VARCHAR(40) NOT NULL,
    object_id VARCHAR(100),
    object_label VARCHAR(255),
    description TEXT NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX audit_log_occurred_at_idx
    ON audit_log(occurred_at DESC, id DESC);
CREATE INDEX audit_log_actor_idx
    ON audit_log(actor_user_id, occurred_at DESC);
CREATE INDEX audit_log_action_idx
    ON audit_log(action, occurred_at DESC);
CREATE INDEX audit_log_object_idx
    ON audit_log(object_type, object_id, occurred_at DESC);

CREATE FUNCTION mirvmon_reject_audit_log_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'audit_log is append-only'
        USING ERRCODE = '55000';
END;
$$;

CREATE TRIGGER audit_log_append_only
BEFORE UPDATE OR DELETE ON audit_log
FOR EACH ROW
EXECUTE FUNCTION mirvmon_reject_audit_log_mutation();
