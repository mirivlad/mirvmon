CREATE EXTENSION IF NOT EXISTS timescaledb;

CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(254),
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user'
        CHECK (role IN ('admin', 'operator', 'user')),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_notification_settings (
    user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    notify_on_warning BOOLEAN NOT NULL DEFAULT TRUE,
    notify_on_critical BOOLEAN NOT NULL DEFAULT TRUE,
    telegram_chat_id VARCHAR(100),
    email_for_alerts VARCHAR(254),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE server_groups (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50),
    color VARCHAR(20),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE servers (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address VARCHAR(255),
    group_id BIGINT REFERENCES server_groups(id) ON DELETE SET NULL,
    description TEXT,
    display_metrics JSONB NOT NULL DEFAULT '[]'::jsonb,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_metrics_at TIMESTAMPTZ,
    last_service_check_at TIMESTAMPTZ,
    service_check_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    offline_timeout_seconds INTEGER NOT NULL DEFAULT 300
        CHECK (offline_timeout_seconds >= 0),
    notify_on_offline BOOLEAN NOT NULL DEFAULT TRUE,
    last_offline_alert_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX servers_group_id_idx ON servers(group_id);
CREATE INDEX servers_last_metrics_at_idx ON servers(last_metrics_at DESC);

CREATE TABLE metric_names (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    unit VARCHAR(32),
    description TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO metric_names (name, unit, description) VALUES
    ('cpu_load', '%', 'Загрузка процессора'),
    ('ram_used', '%', 'Использование оперативной памяти'),
    ('disk_used', '%', 'Использование диска'),
    ('network_in', 'B/s', 'Скорость приёма данных'),
    ('network_out', 'B/s', 'Скорость передачи данных'),
    ('uptime', 's', 'Время работы')
ON CONFLICT (name) DO NOTHING;

CREATE TABLE metric_thresholds (
    id BIGSERIAL PRIMARY KEY,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    metric_id BIGINT NOT NULL REFERENCES metric_names(id) ON DELETE CASCADE,
    warning_threshold DOUBLE PRECISION,
    critical_threshold DOUBLE PRECISION,
    duration_seconds INTEGER NOT NULL DEFAULT 0 CHECK (duration_seconds >= 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (server_id, metric_id)
);

CREATE TABLE agent_tokens (
    id BIGSERIAL PRIMARY KEY,
    server_id BIGINT NOT NULL UNIQUE REFERENCES servers(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMPTZ
);

CREATE TABLE installer_tokens (
    id BIGSERIAL PRIMARY KEY,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX installer_tokens_active_idx
    ON installer_tokens(token_hash, expires_at)
    WHERE consumed_at IS NULL;

CREATE TABLE agent_configs (
    server_id BIGINT PRIMARY KEY REFERENCES servers(id) ON DELETE CASCADE,
    interval_seconds INTEGER NOT NULL DEFAULT 60
        CHECK (interval_seconds BETWEEN 10 AND 86400),
    monitor_services JSONB NOT NULL DEFAULT '[]'::jsonb,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE ingested_samples (
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    sample_id UUID NOT NULL,
    sample_time TIMESTAMPTZ NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (server_id, sample_id)
);

CREATE INDEX ingested_samples_received_at_idx ON ingested_samples(received_at);

CREATE TABLE metric_samples (
    sample_time TIMESTAMPTZ NOT NULL,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    metric_id BIGINT NOT NULL REFERENCES metric_names(id) ON DELETE CASCADE,
    sample_id UUID NOT NULL,
    value DOUBLE PRECISION NOT NULL,
    PRIMARY KEY (sample_time, server_id, metric_id, sample_id)
);

SELECT create_hypertable(
    'metric_samples',
    by_range('sample_time', INTERVAL '1 day'),
    if_not_exists => TRUE,
    create_default_indexes => FALSE
);

CREATE INDEX metric_samples_server_metric_time_idx
    ON metric_samples(server_id, metric_id, sample_time DESC);

CREATE TABLE process_snapshots (
    sample_time TIMESTAMPTZ NOT NULL,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    sample_id UUID NOT NULL,
    processes JSONB NOT NULL,
    PRIMARY KEY (sample_time, server_id, sample_id)
);

SELECT create_hypertable(
    'process_snapshots',
    by_range('sample_time', INTERVAL '1 day'),
    if_not_exists => TRUE,
    create_default_indexes => FALSE
);

CREATE INDEX process_snapshots_server_time_idx
    ON process_snapshots(server_id, sample_time DESC);

CREATE TABLE service_status (
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    service_name VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('running', 'stopped', 'unknown')),
    load_state VARCHAR(50),
    active_state VARCHAR(50),
    sub_state VARCHAR(50),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (server_id, service_name)
);

CREATE INDEX service_status_server_updated_idx
    ON service_status(server_id, updated_at DESC);

CREATE TABLE alerts (
    id BIGSERIAL PRIMARY KEY,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    metric_id BIGINT REFERENCES metric_names(id) ON DELETE SET NULL,
    kind VARCHAR(20) NOT NULL DEFAULT 'metric'
        CHECK (kind IN ('metric', 'service', 'offline')),
    subject VARCHAR(255),
    value DOUBLE PRECISION,
    severity VARCHAR(20) NOT NULL CHECK (severity IN ('warning', 'critical')),
    resolved BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMPTZ
);

CREATE INDEX alerts_active_idx ON alerts(server_id, severity, created_at DESC)
    WHERE resolved = FALSE;

CREATE TABLE notification_settings (
    id SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    smtp_host VARCHAR(255),
    smtp_port INTEGER NOT NULL DEFAULT 587 CHECK (smtp_port BETWEEN 1 AND 65535),
    smtp_username VARCHAR(255),
    smtp_password_encrypted BYTEA,
    smtp_encryption VARCHAR(10) NOT NULL DEFAULT 'tls'
        CHECK (smtp_encryption IN ('tls', 'ssl', 'none')),
    smtp_from_email VARCHAR(254),
    email_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    telegram_bot_token_encrypted BYTEA,
    telegram_chat_id VARCHAR(100),
    telegram_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    telegram_proxy_type VARCHAR(10)
        CHECK (telegram_proxy_type IN (
            'http', 'https', 'socks4', 'socks4a', 'socks5', 'socks5h'
        )),
    telegram_proxy_host VARCHAR(255),
    telegram_proxy_port INTEGER
        CHECK (telegram_proxy_port BETWEEN 1 AND 65535),
    telegram_proxy_username VARCHAR(255),
    telegram_proxy_password_encrypted BYTEA,
    notify_on_warning BOOLEAN NOT NULL DEFAULT TRUE,
    notify_on_critical BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO notification_settings(id) VALUES (1);

CREATE TABLE notification_outbox (
    id BIGSERIAL PRIMARY KEY,
    server_id BIGINT REFERENCES servers(id) ON DELETE SET NULL,
    alert_id BIGINT REFERENCES alerts(id) ON DELETE SET NULL,
    channel VARCHAR(20) NOT NULL CHECK (channel IN ('email', 'telegram')),
    event_type VARCHAR(50) NOT NULL,
    payload JSONB NOT NULL,
    deduplication_key VARCHAR(255) UNIQUE,
    status VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'processing', 'sent', 'failed')),
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    available_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at TIMESTAMPTZ,
    last_error TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMPTZ
);

CREATE INDEX notification_outbox_pending_idx
    ON notification_outbox(available_at, id)
    WHERE status IN ('pending', 'failed');
CREATE INDEX notification_outbox_alert_idx
    ON notification_outbox(alert_id)
    WHERE alert_id IS NOT NULL;

CREATE TABLE app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value JSONB NOT NULL,
    description TEXT,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO app_settings(setting_key, setting_value, description) VALUES
    ('default_offline_timeout', '300'::jsonb, 'Таймаут offline по умолчанию, секунд'),
    ('default_warning_threshold', '70'::jsonb, 'Warning threshold по умолчанию'),
    ('default_critical_threshold', '90'::jsonb, 'Critical threshold по умолчанию'),
    ('default_duration_seconds', '0'::jsonb, 'Длительность превышения порога, секунд')
ON CONFLICT (setting_key) DO NOTHING;

CREATE TABLE login_attempts (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(80) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    succeeded BOOLEAN NOT NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX login_attempts_lookup_idx
    ON login_attempts(username, source_hash, attempted_at DESC);

CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;

CREATE TRIGGER users_set_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER server_groups_set_updated_at
    BEFORE UPDATE ON server_groups
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER servers_set_updated_at
    BEFORE UPDATE ON servers
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER metric_thresholds_set_updated_at
    BEFORE UPDATE ON metric_thresholds
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER agent_configs_set_updated_at
    BEFORE UPDATE ON agent_configs
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER notification_settings_set_updated_at
    BEFORE UPDATE ON notification_settings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER app_settings_set_updated_at
    BEFORE UPDATE ON app_settings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
