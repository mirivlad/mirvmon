-- 003: Таблица конфигурации агентов

CREATE TABLE IF NOT EXISTS agent_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL UNIQUE,
    interval_seconds INT DEFAULT 60,
    monitor_services LONGTEXT COMMENT 'Массив сервисов для мониторинга' CHECK (json_valid(monitor_services)),
    enabled TINYINT(1) DEFAULT 1 COMMENT 'Включен ли агент',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
