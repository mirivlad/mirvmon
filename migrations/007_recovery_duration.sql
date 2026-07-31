ALTER TABLE metric_thresholds
    ADD COLUMN recovery_duration_seconds INTEGER NOT NULL DEFAULT 300
        CHECK (recovery_duration_seconds >= 0);

INSERT INTO app_settings(setting_key, setting_value, description) VALUES
    (
        'default_recovery_duration_seconds',
        '300'::jsonb,
        'Длительность возврата ниже порога перед восстановлением, секунд'
    )
ON CONFLICT (setting_key) DO NOTHING;
