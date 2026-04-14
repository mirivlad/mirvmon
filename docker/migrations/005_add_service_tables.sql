-- 005: Таблицы мониторинга сервисов

CREATE TABLE IF NOT EXISTS service_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    service_name VARCHAR(255) NOT NULL,
    status ENUM('running', 'stopped', 'unknown') NOT NULL,
    load_state VARCHAR(50) DEFAULT NULL,
    active_state VARCHAR(50) DEFAULT NULL,
    sub_state VARCHAR(50) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_server_service (server_id, service_name),
    KEY idx_server_updated (server_id, updated_at),
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    service_name VARCHAR(100) NOT NULL,
    status ENUM('stopped', 'running', 'unknown') NOT NULL,
    severity ENUM('warning', 'critical') DEFAULT 'warning' COMMENT 'Уровень важности',
    resolved TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    KEY idx_server_service (server_id, service_name),
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
