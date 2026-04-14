-- 007: Создаём админа по умолчанию (если нет ни одного пользователя)
-- Пароль: mirvmon2026 (обязательно смените при первом входе!)
-- Хеш сгенерирован через PHP password_hash('mirvmon2026', PASSWORD_DEFAULT)

INSERT INTO users (username, password_hash, email, role)
SELECT 'admin', '$2y$10$F1HMd92MKiuBPJhm4V6CfOEhzPr7YHvFayO8Yn7wL0UAd05uQYd1u', 'admin@localhost', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM users LIMIT 1);

-- Создаём настройки уведомлений для админа
INSERT INTO user_notification_settings (user_id, enabled_notifications, notify_on_warning, notify_on_critical)
SELECT id, 1, 1, 1 FROM users WHERE username = 'admin'
AND NOT EXISTS (SELECT 1 FROM user_notification_settings WHERE user_id = users.id)
LIMIT 1;
