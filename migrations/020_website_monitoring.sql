-- Server groups become shared monitoring groups without changing row or FK IDs.
ALTER TABLE server_groups RENAME TO monitoring_groups;
ALTER TRIGGER server_groups_set_updated_at ON monitoring_groups
    RENAME TO monitoring_groups_set_updated_at;

CREATE TABLE websites (
    id BIGSERIAL PRIMARY KEY,
    group_id BIGINT REFERENCES monitoring_groups(id) ON DELETE SET NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    registration_domain VARCHAR(253),
    domain_check_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    default_interval_seconds INTEGER NOT NULL DEFAULT 60
        CHECK (default_interval_seconds BETWEEN 10 AND 86400),
    tls_warning_days INTEGER NOT NULL DEFAULT 21
        CHECK (tls_warning_days BETWEEN 1 AND 3650),
    tls_critical_days INTEGER NOT NULL DEFAULT 7
        CHECK (tls_critical_days BETWEEN 0 AND 3650),
    domain_warning_days INTEGER NOT NULL DEFAULT 30
        CHECK (domain_warning_days BETWEEN 1 AND 3650),
    domain_critical_days INTEGER NOT NULL DEFAULT 7
        CHECK (domain_critical_days BETWEEN 0 AND 3650),
    notification_telegram_chat_id VARCHAR(100),
    notification_emails JSONB NOT NULL DEFAULT '[]'::jsonb,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    paused_at TIMESTAMPTZ,
    domain_next_check_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT websites_domain_configuration_check CHECK (
        domain_check_enabled = FALSE OR registration_domain IS NOT NULL
    ),
    CONSTRAINT websites_tls_thresholds_check CHECK (
        tls_critical_days <= tls_warning_days
    ),
    CONSTRAINT websites_domain_thresholds_check CHECK (
        domain_critical_days <= domain_warning_days
    )
);

CREATE INDEX websites_group_id_idx ON websites(group_id);
CREATE INDEX websites_active_idx ON websites(is_active, id);
CREATE INDEX websites_domain_due_idx ON websites(domain_next_check_at, id)
    WHERE is_active = TRUE AND domain_check_enabled = TRUE;

CREATE TRIGGER websites_set_updated_at
    BEFORE UPDATE ON websites
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE website_endpoints (
    id BIGSERIAL PRIMARY KEY,
    website_id BIGINT NOT NULL REFERENCES websites(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    url TEXT NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    method VARCHAR(4) NOT NULL DEFAULT 'GET' CHECK (method IN ('GET', 'HEAD')),
    interval_seconds INTEGER CHECK (interval_seconds BETWEEN 10 AND 86400),
    timeout_seconds INTEGER NOT NULL DEFAULT 15
        CHECK (timeout_seconds BETWEEN 1 AND 60),
    follow_redirects BOOLEAN NOT NULL DEFAULT TRUE,
    max_redirects SMALLINT NOT NULL DEFAULT 10
        CHECK (max_redirects BETWEEN 0 AND 10),
    status_check_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    expected_status_ranges JSONB NOT NULL
        DEFAULT '[{"min":200,"max":299}]'::jsonb,
    warning_total_ms INTEGER CHECK (warning_total_ms BETWEEN 1 AND 60000),
    critical_total_ms INTEGER CHECK (critical_total_ms BETWEEN 1 AND 60000),
    auth_type VARCHAR(10) NOT NULL DEFAULT 'none'
        CHECK (auth_type IN ('none', 'basic', 'bearer')),
    auth_encrypted BYTEA,
    headers_encrypted BYTEA,
    credential_redirect_hosts JSONB NOT NULL DEFAULT '[]'::jsonb,
    allow_self_signed BOOLEAN NOT NULL DEFAULT FALSE,
    tls_expiry_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    next_http_check_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT website_endpoints_response_thresholds_check CHECK (
        critical_total_ms IS NULL
        OR warning_total_ms IS NULL
        OR critical_total_ms >= warning_total_ms
    ),
    CONSTRAINT website_endpoints_id_site_unique UNIQUE (id, website_id)
);

CREATE UNIQUE INDEX website_endpoints_one_primary_idx
    ON website_endpoints(website_id) WHERE is_primary = TRUE;
CREATE INDEX website_endpoints_website_idx ON website_endpoints(website_id, id);
CREATE INDEX website_endpoints_http_due_idx
    ON website_endpoints(next_http_check_at, website_id, id);

CREATE TRIGGER website_endpoints_set_updated_at
    BEFORE UPDATE ON website_endpoints
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE website_content_checks (
    id BIGSERIAL PRIMARY KEY,
    endpoint_id BIGINT NOT NULL REFERENCES website_endpoints(id) ON DELETE CASCADE,
    kind VARCHAR(20) NOT NULL CHECK (kind IN ('page_text', 'css')),
    selector VARCHAR(1000),
    expected_text VARCHAR(2000) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    CONSTRAINT website_content_checks_shape_check CHECK (
        (kind = 'page_text' AND selector IS NULL)
        OR (kind = 'css' AND selector IS NOT NULL)
    )
);

CREATE INDEX website_content_checks_endpoint_idx
    ON website_content_checks(endpoint_id, sort_order, id);

CREATE TABLE website_endpoint_state (
    endpoint_id BIGINT PRIMARY KEY,
    website_id BIGINT NOT NULL,
    transport_state VARCHAR(20) NOT NULL DEFAULT 'no_data'
        CHECK (transport_state IN (
            'no_data', 'healthy', 'possible_problem', 'problem', 'recovering', 'paused'
        )),
    assertion_state VARCHAR(20) NOT NULL DEFAULT 'no_data'
        CHECK (assertion_state IN (
            'no_data', 'healthy', 'possible_problem', 'problem', 'recovering', 'paused'
        )),
    performance_state VARCHAR(20) NOT NULL DEFAULT 'no_data'
        CHECK (performance_state IN (
            'no_data', 'healthy', 'possible_problem', 'problem', 'recovering', 'paused'
        )),
    performance_severity VARCHAR(10)
        CHECK (performance_severity IN ('warning', 'critical')),
    transport_failures SMALLINT NOT NULL DEFAULT 0,
    transport_successes SMALLINT NOT NULL DEFAULT 0,
    assertion_failures SMALLINT NOT NULL DEFAULT 0,
    assertion_successes SMALLINT NOT NULL DEFAULT 0,
    performance_failures SMALLINT NOT NULL DEFAULT 0,
    performance_successes SMALLINT NOT NULL DEFAULT 0,
    transport_series_started_at TIMESTAMPTZ,
    assertion_series_started_at TIMESTAMPTZ,
    performance_series_started_at TIMESTAMPTZ,
    last_sample_at TIMESTAMPTZ,
    last_status_code SMALLINT,
    last_final_url TEXT,
    last_redirect_count SMALLINT NOT NULL DEFAULT 0,
    last_ttfb_ms DOUBLE PRECISION,
    last_total_ms DOUBLE PRECISION,
    last_error_kind VARCHAR(40),
    last_safe_message VARCHAR(1000),
    CONSTRAINT website_endpoint_state_endpoint_site_fk
        FOREIGN KEY (endpoint_id, website_id)
        REFERENCES website_endpoints(id, website_id) ON DELETE CASCADE
);

CREATE INDEX website_endpoint_state_website_idx
    ON website_endpoint_state(website_id, endpoint_id);

CREATE TABLE website_state (
    website_id BIGINT PRIMARY KEY REFERENCES websites(id) ON DELETE CASCADE,
    status VARCHAR(20) NOT NULL DEFAULT 'no_data'
        CHECK (status IN (
            'healthy', 'unavailable', 'problem', 'degraded', 'slow',
            'warning', 'critical', 'no_data', 'paused'
        )),
    primary_endpoint_id BIGINT,
    active_problem_count INTEGER NOT NULL DEFAULT 0,
    possible_problem_text VARCHAR(80),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT website_state_primary_endpoint_fk
        FOREIGN KEY (primary_endpoint_id, website_id)
        REFERENCES website_endpoints(id, website_id)
);

CREATE INDEX website_state_status_idx ON website_state(status, website_id);
CREATE INDEX website_state_primary_endpoint_idx
    ON website_state(primary_endpoint_id, website_id)
    WHERE primary_endpoint_id IS NOT NULL;

CREATE TRIGGER website_state_set_updated_at
    BEFORE UPDATE ON website_state
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE website_tls_targets (
    id BIGSERIAL PRIMARY KEY,
    website_id BIGINT NOT NULL REFERENCES websites(id) ON DELETE CASCADE,
    endpoint_id BIGINT NOT NULL,
    hostname VARCHAR(253) NOT NULL,
    port INTEGER NOT NULL CHECK (port BETWEEN 1 AND 65535),
    source VARCHAR(12) NOT NULL CHECK (source IN ('configured', 'redirect')),
    allow_self_signed BOOLEAN NOT NULL DEFAULT FALSE,
    next_check_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT website_tls_targets_endpoint_site_fk
        FOREIGN KEY (endpoint_id, website_id)
        REFERENCES website_endpoints(id, website_id) ON DELETE CASCADE,
    CONSTRAINT website_tls_targets_endpoint_host_unique
        UNIQUE (endpoint_id, hostname, port),
    CONSTRAINT website_tls_targets_id_site_unique UNIQUE (id, website_id),
    CONSTRAINT website_tls_targets_self_signed_source_check CHECK (
        source = 'configured' OR allow_self_signed = FALSE
    )
);

CREATE INDEX website_tls_targets_website_idx
    ON website_tls_targets(website_id, endpoint_id);
CREATE INDEX website_tls_targets_due_idx
    ON website_tls_targets(next_check_at, website_id, id);

CREATE TABLE website_tls_state (
    tls_target_id BIGINT PRIMARY KEY
        REFERENCES website_tls_targets(id) ON DELETE CASCADE,
    status VARCHAR(20) NOT NULL DEFAULT 'no_data'
        CHECK (status IN ('no_data', 'healthy', 'warning', 'critical', 'error')),
    subject VARCHAR(1000),
    issuer VARCHAR(1000),
    sans JSONB NOT NULL DEFAULT '[]'::jsonb,
    fingerprint_sha256 CHAR(64),
    not_before TIMESTAMPTZ,
    not_after TIMESTAMPTZ,
    error_kind VARCHAR(40),
    checked_at TIMESTAMPTZ,
    retry_count SMALLINT NOT NULL DEFAULT 0
);

CREATE TABLE website_certificate_events (
    id BIGSERIAL PRIMARY KEY,
    tls_target_id BIGINT NOT NULL REFERENCES website_tls_targets(id) ON DELETE CASCADE,
    previous_fingerprint_sha256 CHAR(64),
    fingerprint_sha256 CHAR(64) NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX website_certificate_events_target_time_idx
    ON website_certificate_events(tls_target_id, occurred_at DESC, id DESC);

CREATE TABLE website_domain_state (
    website_id BIGINT PRIMARY KEY REFERENCES websites(id) ON DELETE CASCADE,
    status VARCHAR(20) NOT NULL DEFAULT 'no_data'
        CHECK (status IN (
            'no_data', 'healthy', 'warning', 'critical', 'unsupported', 'unknown'
        )),
    expires_at TIMESTAMPTZ,
    registrar VARCHAR(500),
    source VARCHAR(20),
    error_kind VARCHAR(40),
    checked_at TIMESTAMPTZ,
    retry_count SMALLINT NOT NULL DEFAULT 0
);

CREATE TABLE website_check_jobs (
    id BIGSERIAL PRIMARY KEY,
    website_id BIGINT NOT NULL REFERENCES websites(id) ON DELETE CASCADE,
    endpoint_id BIGINT,
    tls_target_id BIGINT,
    kind VARCHAR(10) NOT NULL CHECK (kind IN ('http', 'tls', 'domain')),
    state VARCHAR(10) NOT NULL DEFAULT 'pending'
        CHECK (state IN ('pending', 'leased')),
    manual BOOLEAN NOT NULL DEFAULT FALSE,
    priority SMALLINT NOT NULL DEFAULT 0 CHECK (priority BETWEEN 0 AND 100),
    scheduled_for TIMESTAMPTZ NOT NULL,
    available_at TIMESTAMPTZ NOT NULL,
    lease_owner VARCHAR(80),
    lease_until TIMESTAMPTZ,
    attempts SMALLINT NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 10),
    safe_error_kind VARCHAR(40),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT website_check_jobs_endpoint_site_fk
        FOREIGN KEY (endpoint_id, website_id)
        REFERENCES website_endpoints(id, website_id) ON DELETE CASCADE,
    CONSTRAINT website_check_jobs_tls_target_site_fk
        FOREIGN KEY (tls_target_id, website_id)
        REFERENCES website_tls_targets(id, website_id) ON DELETE CASCADE,
    CONSTRAINT website_check_jobs_target_kind_check CHECK (
        (kind = 'http' AND endpoint_id IS NOT NULL AND tls_target_id IS NULL)
        OR (kind = 'tls' AND endpoint_id IS NULL AND tls_target_id IS NOT NULL)
        OR (kind = 'domain' AND endpoint_id IS NULL AND tls_target_id IS NULL)
    ),
    CONSTRAINT website_check_jobs_lease_shape_check CHECK (
        (state = 'pending' AND lease_owner IS NULL AND lease_until IS NULL)
        OR (state = 'leased' AND lease_owner IS NOT NULL AND lease_until IS NOT NULL)
    )
);

CREATE UNIQUE INDEX website_check_jobs_dedupe_idx
    ON website_check_jobs(
        website_id,
        kind,
        (COALESCE(endpoint_id, 0)),
        (COALESCE(tls_target_id, 0)),
        scheduled_for,
        manual
    )
    WHERE state IN ('pending', 'leased');
CREATE INDEX website_check_jobs_due_idx
    ON website_check_jobs(priority DESC, available_at, id)
    WHERE state = 'pending';
CREATE INDEX website_check_jobs_endpoint_idx ON website_check_jobs(endpoint_id)
    WHERE endpoint_id IS NOT NULL;
CREATE INDEX website_check_jobs_tls_target_idx ON website_check_jobs(tls_target_id)
    WHERE tls_target_id IS NOT NULL;

-- Alerts remain one subsystem while accepting exactly one server or website source.
ALTER TABLE alerts ALTER COLUMN server_id DROP NOT NULL;
ALTER TABLE alerts
    ADD COLUMN website_id BIGINT REFERENCES websites(id) ON DELETE CASCADE,
    ADD COLUMN endpoint_id BIGINT,
    ADD COLUMN details JSONB NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN resolution_reason VARCHAR(40),
    DROP CONSTRAINT alerts_kind_check,
    ADD CONSTRAINT alerts_kind_check CHECK (kind IN (
        'metric', 'service', 'offline', 'website_http', 'website_assertion',
        'website_performance', 'website_tls', 'website_domain'
    )),
    ADD CONSTRAINT alerts_source_check CHECK (
        (server_id IS NOT NULL)::integer + (website_id IS NOT NULL)::integer = 1
    ),
    ADD CONSTRAINT alerts_endpoint_site_fk
        FOREIGN KEY (endpoint_id, website_id)
        REFERENCES website_endpoints(id, website_id) ON DELETE CASCADE,
    ADD CONSTRAINT alerts_endpoint_source_check CHECK (
        endpoint_id IS NULL OR website_id IS NOT NULL
    );

CREATE UNIQUE INDEX alerts_one_active_website_kind_idx
    ON alerts(website_id, (COALESCE(endpoint_id, 0)), kind)
    WHERE resolved = FALSE AND website_id IS NOT NULL;
CREATE INDEX alerts_website_history_idx
    ON alerts(website_id, created_at DESC, id DESC)
    WHERE website_id IS NOT NULL;

ALTER TABLE notification_outbox
    ADD COLUMN website_id BIGINT REFERENCES websites(id) ON DELETE SET NULL,
    ADD CONSTRAINT notification_outbox_source_check CHECK (
        server_id IS NULL OR website_id IS NULL
    );

CREATE INDEX notification_outbox_website_cooldown_idx
    ON notification_outbox(website_id, event_type, created_at DESC)
    WHERE website_id IS NOT NULL;

ALTER TABLE maintenance_windows ALTER COLUMN server_id DROP NOT NULL;
ALTER TABLE maintenance_windows
    ADD COLUMN website_id BIGINT REFERENCES websites(id) ON DELETE CASCADE,
    ADD CONSTRAINT maintenance_windows_source_check CHECK (
        (server_id IS NOT NULL)::integer + (website_id IS NOT NULL)::integer = 1
    );

CREATE INDEX maintenance_windows_website_idx
    ON maintenance_windows(website_id, ends_at DESC)
    WHERE website_id IS NOT NULL;

CREATE TABLE website_availability_events (
    id BIGSERIAL PRIMARY KEY,
    website_id BIGINT NOT NULL REFERENCES websites(id) ON DELETE CASCADE,
    endpoint_id BIGINT NOT NULL,
    state VARCHAR(12) NOT NULL CHECK (state IN ('available', 'unavailable')),
    occurred_at TIMESTAMPTZ NOT NULL,
    alert_id BIGINT REFERENCES alerts(id) ON DELETE SET NULL,
    CONSTRAINT website_availability_events_endpoint_site_fk
        FOREIGN KEY (endpoint_id, website_id)
        REFERENCES website_endpoints(id, website_id) ON DELETE CASCADE
);

CREATE INDEX website_availability_events_website_time_idx
    ON website_availability_events(website_id, occurred_at DESC, id DESC);
CREATE INDEX website_availability_events_endpoint_time_idx
    ON website_availability_events(endpoint_id, occurred_at DESC, id DESC);
CREATE INDEX website_availability_events_alert_idx
    ON website_availability_events(alert_id) WHERE alert_id IS NOT NULL;

INSERT INTO app_settings(setting_key, setting_value, description) VALUES
    ('website_default_interval_seconds', '60'::jsonb, 'Интервал HTTP-проверки сайта по умолчанию, секунд'),
    ('website_http_timeout_seconds', '15'::jsonb, 'Общий deadline HTTP-проверки сайта, секунд'),
    ('website_tls_warning_days', '21'::jsonb, 'Порог предупреждения TLS, дней'),
    ('website_tls_critical_days', '7'::jsonb, 'Критический порог TLS, дней'),
    ('website_domain_warning_days', '30'::jsonb, 'Порог предупреждения регистрации домена, дней'),
    ('website_domain_critical_days', '7'::jsonb, 'Критический порог регистрации домена, дней'),
    ('website_worker_concurrency', '10'::jsonb, 'Параллельность централизованных проверок сайтов')
ON CONFLICT (setting_key) DO NOTHING;
