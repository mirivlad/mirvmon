CREATE TABLE website_check_schedule_cursor (
    id SMALLINT PRIMARY KEY CHECK (id = 1),
    next_kind SMALLINT NOT NULL DEFAULT 0 CHECK (next_kind BETWEEN 0 AND 2)
);

INSERT INTO website_check_schedule_cursor (id, next_kind)
VALUES (1, 0)
ON CONFLICT (id) DO NOTHING;
