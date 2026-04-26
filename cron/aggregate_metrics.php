#!/usr/bin/env php
<?php
// cron/aggregate_metrics.php
// Запускать каждый час: 0 * * * * /usr/bin/php /var/www/mon/cron/aggregate_metrics.php

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/DatabaseConfig.php';

// Получаем соединение
$pdo = new PDO("mysql:host={$config['host']};dbname={$config['db_name']};charset=utf8mb4", 
    $config['username'], $config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Вычисляем период - прошлый час (00:00 - 00:59)
$hourStart = new DateTime();
$hourStart->modify('-1 hour');
$hourStart->setMinute(0)->setSecond(0);

$hourEnd = clone $hourStart;
$hourEnd->modify('+59 minutes +59 seconds');

$periodStartStr = $hourStart->format('Y-m-d H:i:s');
$periodEndStr = $hourEnd->format('Y-m-d H:i:s');

// Получаем все серверы
$stmt = $pdo->query("SELECT id FROM servers WHERE deleted_at IS NULL");
$servers = $stmt->fetchAll(PDO::FETCH_COLUMN);

$processed = 0;
$errors = 0;

foreach ($servers as $serverId) {
    // Агрегируем данные за прошлый час для каждой метрики сервера
    $sql = "
        INSERT INTO server_metrics_trends (server_id, metric_name_id, period_start, avg_value, min_value, max_value, count_samples)
        SELECT 
            sm.server_id,
            sm.metric_name_id,
            :period_start,
            AVG(sm.value),
            MIN(sm.value),
            MAX(sm.value),
            COUNT(*)
        FROM server_metrics sm
        WHERE sm.server_id = :server_id
        AND sm.created_at >= :start_date
        AND sm.created_at <= :end_date
        GROUP BY sm.server_id, sm.metric_name_id
        ON DUPLICATE KEY UPDATE 
            avg_value = VALUES(avg_value),
            min_value = VALUES(min_value),
            max_value = VALUES(max_value),
            count_samples = VALUES(count_samples),
            created_at = NOW()
    ";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':server_id' => $serverId,
            ':period_start' => $periodStartStr,
            ':start_date' => $periodStartStr,
            ':end_date' => $periodEndStr
        ]);
        $processed++;
    } catch (Exception $e) {
        $errors++;
        error_log("Server $serverId: " . $e->getMessage());
    }
}

echo "Aggregated: $processed servers, $errors errors\n";