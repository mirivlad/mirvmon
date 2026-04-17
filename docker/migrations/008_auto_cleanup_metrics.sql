-- 008: Авто-очистка старых метрик (старше 60 дней)
-- Запускается автоматически каждый день в 03:00

-- Создаём событие очистки (работает от mon_user если даны права EVENT)
-- Если mon_user не может создать событие — запустите вручную от root:
--   CREATE EVENT ... (см. ниже)
-- 
-- Для Docker: event_scheduler включается через docker-compose command
-- Для ручной установки: добавьте event_scheduler=ON в /etc/mysql/mariadb.conf.d/

-- Если есть привилегии — создаём событие:
CREATE EVENT IF NOT EXISTS daily_metrics_cleanup
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 3 HOUR
ON COMPLETION PRESERVE
DO
    DELETE FROM server_metrics WHERE created_at < NOW() - INTERVAL 60 DAY;
