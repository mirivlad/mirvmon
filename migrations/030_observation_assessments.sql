CREATE TABLE observation_assessments (
    observation_id BIGINT NOT NULL REFERENCES observations(id) ON DELETE CASCADE,
    recurrence_count INTEGER NOT NULL CHECK (recurrence_count >= 1),
    outcome VARCHAR(20) NOT NULL CHECK (outcome IN ('actionable', 'normal', 'intervention', 'uncertain')),
    assessed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assessed_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    assessed_by_username VARCHAR(80),
    PRIMARY KEY (observation_id, recurrence_count)
);

INSERT INTO observation_assessments(observation_id, recurrence_count, outcome, assessed_at,
                                    assessed_by_user_id, assessed_by_username)
SELECT id, recurrence_count, 'normal', accepted_at, accepted_by_user_id, accepted_by_username
FROM observations
WHERE kind = 'anomaly' AND status = 'accepted_normal' AND accepted_at IS NOT NULL;
