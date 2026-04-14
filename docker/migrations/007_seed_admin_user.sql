-- 007: Создаём админа по умолчанию (если нет ни одного пользователя)

-- Пароль: admin (нужно сменить при первом входе!)
-- Хеш генерируется через password_hash('admin', PASSWORD_DEFAULT)
-- Это хеш от 'admin' — смените сразу после входа!
INSERT INTO users (username, password_hash, email, role)
SELECT 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@localhost', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM users LIMIT 1);

-- Создаём настройки уведомлений для админа
INSERT INTO user_notification_settings (user_id, enabled_notifications, notify_on_warning, notify_on_critical)
SELECT id, 1, 1, 1 FROM users WHERE username = 'admin'
AND NOT EXISTS (SELECT 1 FROM user_notification_settings WHERE user_id = users.id)
LIMIT 1;
