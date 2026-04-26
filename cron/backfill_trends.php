#!/usr/bin/env php
<?php
// cron/backfill_trends.php - запустить один раз для заполнения трендов
require __DIR__ . '/../vendor/autoload.php';

$pdo = new PDO("mysql:host=localhost;dbname=monitoring_system;charset=utf8mb4", "mon_user", "mon_password_123", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Заполняем за последние 48 часов (можно увеличить)
$hours = 48;
$processed = 0;
$errors = 0;

for ($i = 1; $i <= $hours; $i++) {
    $hourStart = new DateTime();
    $hourStart->modify("-{$i} hour");
    $hourStart->setMinute(0)->setSecond(0);
    
    $hourEnd = clone $hourStart;
    $hourEnd->modify('+59 minutes +59 seconds');
    
    $periodStartStr = $hourStart->format('Y-m-d H:00:00');
    $periodEndStr = $hourEnd->format('Y-m-d H:59:59');
    
    // Получаем все серверы
    $stmt = $pdo->query("SELECT id FROM servers WHERE deleted_at IS NULL");
    $servers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($servers as $serverId) {
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
        }
    }
    
    echo "Processed hour: $periodStartStr ($processed servers)\n";
}

echo "Done! Processed: $processed, Errors: $errors\n";