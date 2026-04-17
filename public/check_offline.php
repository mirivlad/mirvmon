<?php
// public/check_offline.php
// CRON скрипт для проверки offline серверов и отправки уведомлений
// Запуск: */1 * * * * php /var/www/mon/public/check_offline.php

date_default_timezone_set("Asia/Irkutsk");

require_once __DIR__ . '/../vendor/autoload.php';

use Config\DatabaseConfig;
use App\Services\NotificationService;

echo "[" . date('Y-m-d H:i:s') . "] Checking offline servers...\n";

$pdo = DatabaseConfig::getInstance();
$notificationService = new NotificationService();

$stmt = $pdo->query("
    SELECT 
        s.id,
        s.name,
        s.offline_timeout,
        s.notify_on_offline,
        s.last_offline_alert_at,
        s.last_metrics_at,
        TIMESTAMPDIFF(SECOND, s.last_metrics_at, NOW()) as seconds_since_update,
        (SELECT COUNT(*) FROM offline_alerts oa WHERE oa.server_id = s.id AND oa.resolved = 0) as active_offline_alerts
    FROM servers s
    WHERE s.offline_timeout > 0
");

$servers = $stmt->fetchAll();

foreach ($servers as $server) {
    $timeout = (int)$server['offline_timeout'];
    $secondsSinceUpdate = (int)$server['seconds_since_update'];
    $isOffline = $secondsSinceUpdate > $timeout;
    $activeAlerts = (int)$server['active_offline_alerts'];

    if ($isOffline) {
        if ($activeAlerts === 0) {
            $stmtInsert = $pdo->prepare("
                INSERT INTO offline_alerts (server_id, triggered_at, resolved, notified_at)
                VALUES (:server_id, NOW(), 0, NOW())
            ");
            $stmtInsert->execute([':server_id' => $server['id']]);

            if ($server['notify_on_offline']) {
                $notificationService->sendOfflineNotification($server['name'], $secondsSinceUpdate);
                echo "[OFFLINE] Server '{$server['name']}' is offline (no metrics for {$secondsSinceUpdate}s)\n";
            }

            $stmtUpdate = $pdo->prepare("
                UPDATE servers SET last_offline_alert_at = NOW() WHERE id = :id
            ");
            $stmtUpdate->execute([':id' => $server['id']]);
        }
    } else {
        if ($activeAlerts > 0) {
            $stmtResolve = $pdo->prepare("
                UPDATE offline_alerts 
                SET resolved = 1, resolved_at = NOW() 
                WHERE server_id = :server_id AND resolved = 0
            ");
            $stmtResolve->execute([':server_id' => $server['id']]);

            $notificationService->sendOnlineNotification($server['name']);
            echo "[ONLINE] Server '{$server['name']}' is back online\n";
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Offline check completed. Processed {$stmt->rowCount()} servers.\n";
