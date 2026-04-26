#!/usr/bin/env php
<?php
// cron/backfill_trends.php
// Запуск:
//   php /var/www/mon/cron/backfill_trends.php
//   php /var/www/mon/cron/backfill_trends.php 30
// По умолчанию заполняет trends за последние 30 дней.

require __DIR__ . '/../vendor/autoload.php';

use Config\DatabaseConfig;

$days = isset($argv[1]) ? max(1, (int)$argv[1]) : 30;
$pdo = DatabaseConfig::getInstance();

$startedAt = new DateTime();
$rangeStart = (clone $startedAt)->modify("-{$days} days")->format('Y-m-d H:i:s');

echo "Backfilling server_metrics_trends for the last {$days} days...\n";
echo "Range start: {$rangeStart}\n";

$sql = "
    INSERT INTO server_metrics_trends (
        server_id,
        metric_name_id,
        period_start,
        avg_value,
        min_value,
        max_value,
        count_samples
    )
    SELECT
        sm.server_id,
        sm.metric_name_id,
        DATE_FORMAT(sm.created_at, '%Y-%m-%d %H:00:00') AS period_start,
        AVG(CAST(sm.value AS DECIMAL(20,4))) AS avg_value,
        MIN(CAST(sm.value AS DECIMAL(20,4))) AS min_value,
        MAX(CAST(sm.value AS DECIMAL(20,4))) AS max_value,
        COUNT(*) AS count_samples
    FROM server_metrics sm
    INNER JOIN metric_names mn ON mn.id = sm.metric_name_id
    WHERE sm.created_at >= :range_start
      AND mn.name NOT IN ('top_cpu_proc', 'top_ram_proc')
    GROUP BY
        sm.server_id,
        sm.metric_name_id,
        DATE_FORMAT(sm.created_at, '%Y-%m-%d %H:00:00')
    ON DUPLICATE KEY UPDATE
        avg_value = VALUES(avg_value),
        min_value = VALUES(min_value),
        max_value = VALUES(max_value),
        count_samples = VALUES(count_samples),
        created_at = NOW()
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':range_start' => $rangeStart]);

$duration = (new DateTime())->getTimestamp() - $startedAt->getTimestamp();
echo "Done in {$duration} sec.\n";
