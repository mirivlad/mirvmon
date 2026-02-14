-- Структура базы данных для системы мониторинга

-- Таблица пользователей
CREATE DATABASE IF NOT EXISTS monitoring_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE monitoring_system;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role ENUM('admin', 'user') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица настроек уведомлений пользователей
CREATE TABLE user_notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    telegram_chat_id VARCHAR(50),
    email_for_alerts VARCHAR(100),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Таблица групп серверов
CREATE TABLE server_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50),
    color VARCHAR(20)
);

-- Таблица серверов
CREATE TABLE servers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address VARCHAR(255),
    group_id INT,
    description TEXT,
    last_metrics_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES server_groups(id)
);

-- Таблица названий метрик
CREATE TABLE metric_names (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    unit VARCHAR(20),
    description TEXT
);

-- Вставляем стандартные метрики
INSERT INTO metric_names (name, unit, description) VALUES
('cpu_load', '%', 'Загрузка процессора'),
('ram_used', '%', 'Использование оперативной памяти'),
('disk_used', '%', 'Использование диска'),
('network_in', 'MB/s', 'Скорость приема сети'),
('network_out', 'MB/s', 'Скорость передачи сети');

-- Таблица пороговых значений метрик
CREATE TABLE metric_thresholds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    metric_name_id INT NOT NULL,
    warning_threshold DECIMAL(8,2),
    critical_threshold DECIMAL(8,2),
    duration INT DEFAULT 0,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    FOREIGN KEY (metric_name_id) REFERENCES metric_names(id)
);

-- Таблица метрик серверов
CREATE TABLE server_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    metric_name_id INT NOT NULL,
    value DECIMAL(8,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_server_metric_time (server_id, metric_name_id, created_at),
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    FOREIGN KEY (metric_name_id) REFERENCES metric_names(id)
);

-- Таблица токенов агентов
CREATE TABLE agent_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT UNIQUE NOT NULL,
    token_hash VARCHAR(64) NOT NULL, -- SHA-256 hash
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);

-- Таблица алертов
CREATE TABLE alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    metric_name VARCHAR(50) NOT NULL,
    value DECIMAL(8,2),
    severity ENUM('warning', 'critical') NOT NULL,
    resolved BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
);