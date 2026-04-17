-- 009_add_offline_settings.sql
-- Добавляет настройки для уведомлений при offline и дефолтные параметры

-- Поля для offline уведомлений в таблице servers
ALTER TABLE servers ADD COLUMN offline_timeout INT DEFAULT 300 COMMENT 'Таймаут в секундах до считания сервера offline (0 = выключено)';
ALTER TABLE servers ADD COLUMN notify_on_offline TINYINT(1) DEFAULT 1 COMMENT 'Уведомлять при offline';
ALTER TABLE servers ADD COLUMN last_offline_alert_at TIMESTAMP NULL COMMENT 'Время последнего алерта offline (анти-спам)';

-- Таблица дефолтных параметров
CREATE TABLE IF NOT EXISTS default_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Вставляем дефолтные значения
INSERT INTO default_settings (setting_key, setting_value, description) VALUES
('offline_check_interval', '60', 'Интервал проверки offline в секундах'),
('default_offline_timeout', '300', 'Дефолтный таймаут offline в секундах'),
('default_warning_threshold', '70', 'Дефолтный warning threshold (%)'),
('default_critical_threshold', '90', 'Дефолтный critical threshold (%)'),
('default_duration', '0', 'Дефолтная длительность превышения порога в минутах')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Таблица для алертов offline (отдельная от метрик)
CREATE TABLE IF NOT EXISTS offline_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    triggered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved TINYINT(1) DEFAULT 0,
    resolved_at TIMESTAMP NULL,
    notified_at TIMESTAMP NULL,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Индекс для быстрого поиска активных offline алертов
CREATE INDEX idx_offline_alerts_active ON offline_alerts (server_id, resolved);
