-- 004: Глобальные настройки уведомлений

CREATE TABLE IF NOT EXISTS global_notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    smtp_host VARCHAR(255) DEFAULT '',
    smtp_port INT DEFAULT 587,
    smtp_username VARCHAR(255) DEFAULT '',
    smtp_password VARCHAR(255) DEFAULT '',
    smtp_encryption ENUM('tls', 'ssl', 'none') DEFAULT 'tls',
    smtp_from_email VARCHAR(255) DEFAULT '',
    telegram_bot_token VARCHAR(255) DEFAULT '',
    telegram_chat_id VARCHAR(100) DEFAULT '',
    telegram_proxy VARCHAR(255) DEFAULT 'http://127.0.0.1:1081',
    email_enabled TINYINT(1) DEFAULT 0,
    telegram_enabled TINYINT(1) DEFAULT 0,
    notify_on_warning TINYINT(1) DEFAULT 1,
    notify_on_critical TINYINT(1) DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Создаём запись по умолчанию если нет
INSERT INTO global_notification_settings (id) SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM global_notification_settings WHERE id = 1);
