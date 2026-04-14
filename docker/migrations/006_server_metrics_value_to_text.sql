-- 006: Меняем тип value в server_metrics на TEXT (для JSON процессов и т.д.)

ALTER TABLE server_metrics MODIFY COLUMN value TEXT;
