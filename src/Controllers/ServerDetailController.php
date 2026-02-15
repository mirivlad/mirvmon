<?php
// src/Controllers/ServerDetailController.php

namespace App\Controllers;

use App\Models\Model;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ServerDetailController extends Model
{
    private $twig;

    public function __construct(Twig $twig)
    {
        parent::__construct();
        $this->twig = $twig;
    }

    public function show(Request $request, Response $response, $args)
    {
        $id = $args['id'];

        // Получаем информацию о сервере
        $stmt = $this->pdo->prepare("
            SELECT s.*, sg.name as group_name, sg.icon as group_icon, sg.color as group_color
            FROM servers s
            LEFT JOIN server_groups sg ON s.group_id = sg.id
            WHERE s.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $server = $stmt->fetch();

        if (!$server) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }

        // Получаем период и зум
        $period = $request->getQueryParams()['period'] ?? '24h';
        $zoom = $request->getQueryParams()['zoom'] ?? null;

        // Конфигурация агрегации
        $aggConfig = $this->getAggregationConfig($period, $zoom);
        
        $interval = $aggConfig['interval'];
        $groupBy = $aggConfig['groupBy'];
        
        // Запрос с агрегацией если нужно
        if ($groupBy) {
            // Используем агрегацию на основе aggregate_minutes
            $bucketFormat = match(true) {
                // Экранируем % для DATE_FORMAT
                $aggConfig['aggregate_minutes'] >= 60 => '%Y-%m-%d %H:00',  // 1 hour+
                $aggConfig['aggregate_minutes'] >= 15 => '%Y-%m-%d %H:%i', // 15-59 min
                default => '%Y-%m-%d %H:%i:00'                              // 1-14 min
            };
            
            $sql = "
                SELECT 
                    AVG(sm.value) as value,
                    mn.name,
                    mn.unit,
                    DATE_FORMAT(sm.created_at, '{$bucketFormat}') as time_bucket
                FROM server_metrics sm
                JOIN metric_names mn ON sm.metric_name_id = mn.id
                WHERE sm.server_id = :id
                AND sm.created_at >= DATE_SUB(NOW(), {$interval})
                {$groupBy}
                ORDER BY time_bucket DESC
            ";
        } else {
            $sql = "
                SELECT sm.value, mn.name, mn.unit, sm.created_at
                FROM server_metrics sm
                JOIN metric_names mn ON sm.metric_name_id = mn.id
                WHERE sm.server_id = :id
                AND sm.created_at >= DATE_SUB(NOW(), {$interval})
                ORDER BY sm.created_at DESC
            ";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $metrics = $stmt->fetchAll();

        // Группируем метрики
        $groupedMetrics = [];
        foreach ($metrics as $metric) {
            $metricName = $metric['name'];
            if (!isset($groupedMetrics[$metricName])) {
                $groupedMetrics[$metricName] = [];
            }
            $groupedMetrics[$metricName][] = $metric;
        }

        // Пороги
        $stmt = $this->pdo->prepare("
            SELECT mt.warning_threshold, mt.critical_threshold, mt.duration, mn.name
            FROM metric_thresholds mt
            JOIN metric_names mn ON mt.metric_name_id = mn.id
            WHERE mt.server_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $existingThresholds = [];
        foreach ($stmt->fetchAll() as $threshold) {
            $existingThresholds[$threshold['name']] = [
                'warning' => $threshold['warning_threshold'],
                'critical' => $threshold['critical_threshold'],
                'duration' => $threshold['duration']
            ];
        }

        // Типы метрик
        $stmt = $this->pdo->query("SELECT id, name, unit FROM metric_names WHERE name NOT LIKE '%\_proc' ORDER BY name");
        $allMetricTypes = $stmt->fetchAll();

        // Сервисы
        $stmt = $this->pdo->prepare("
            SELECT service_name, status, load_state, active_state, sub_state
            FROM service_status WHERE server_id = :server_id ORDER BY service_name
        ");
        $stmt->execute([':server_id' => $id]);
        $allServices = $stmt->fetchAll();

        // Мониторинг сервисов
        $stmt = $this->pdo->prepare("SELECT monitor_services FROM agent_configs WHERE server_id = :server_id");
        $stmt->execute([':server_id' => $id]);
        $agentConfig = $stmt->fetch();

        $monitorServices = [];
        if ($agentConfig && !empty($agentConfig['monitor_services'])) {
            $monitorServices = json_decode($agentConfig['monitor_services'], true) ?? [];
        }

        $templateData = [
            'title' => 'Сервер: ' . $server['name'],
            'server' => $server,
            'metrics' => $groupedMetrics,
            'allMetricTypes' => $allMetricTypes,
            'existingThresholds' => $existingThresholds,
            'allServices' => $allServices,
            'monitorServices' => $monitorServices,
            'period' => $period,
            'zoom' => $zoom,
            'aggregation' => $aggConfig,
            'request' => $request->getQueryParams()
        ];

        return $this->twig->render($response, 'servers/detail.twig', $templateData);
    }
    
    private function getAggregationConfig(string $period, ?string $zoom): array
    {
        // Target: ~200-400 points on chart regardless of period
        
        if ($zoom) {
            return match($zoom) {
                // Short periods - show all points
                '1h' => ['interval' => 'INTERVAL 1 HOUR', 'groupBy' => null, 'aggregate_minutes' => 0],
                '6h' => ['interval' => 'INTERVAL 6 HOUR', 'groupBy' => null, 'aggregate_minutes' => 0],
                // Medium period - light aggregation
                '24h' => ['interval' => 'INTERVAL 24 HOUR', 'groupBy' => "GROUP BY mn.id, DATE_FORMAT(sm.created_at, '%Y-%m-%d %H:%i')", 'aggregate_minutes' => 1],
                // Long periods - strong aggregation
                '7d' => ['interval' => 'INTERVAL 7 DAY', 'groupBy' => "GROUP BY mn.id, DATE_FORMAT(sm.created_at, '%Y-%m-%d %H:%i')", 'aggregate_minutes' => 15],
                '30d' => ['interval' => 'INTERVAL 30 DAY', 'groupBy' => "GROUP BY mn.id, DATE_FORMAT(sm.created_at, '%Y-%m-%d %H:00')", 'aggregate_minutes' => 60],
                default => ['interval' => 'INTERVAL 24 HOUR', 'groupBy' => null, 'aggregate_minutes' => 0]
            };
        }
        
        // Default: base period aggregation
        return match($period) {
            '7d' => ['interval' => 'INTERVAL 7 DAY', 'groupBy' => "GROUP BY mn.id, DATE_FORMAT(sm.created_at, '%Y-%m-%d %H:%i')", 'aggregate_minutes' => 15],
            '30d' => ['interval' => 'INTERVAL 30 DAY', 'groupBy' => "GROUP BY mn.id, DATE_FORMAT(sm.created_at, '%Y-%m-%d %H:00')", 'aggregate_minutes' => 60],
            default => ['interval' => 'INTERVAL 24 HOUR', 'groupBy' => null, 'aggregate_minutes' => 0]
        };
    }

    public function saveThresholds(Request $request, Response $response, $args)
    {
        $id = $args['id'];
        $params = $request->getParsedBody();

        $stmt = $this->pdo->query("SELECT id, name FROM metric_names WHERE name NOT LIKE '%\_proc' ORDER BY name");
        $metricTypes = $stmt->fetchAll();

        $stmt = $this->pdo->prepare("DELETE FROM metric_thresholds WHERE server_id = :server_id");
        $stmt->execute([':server_id' => $id]);

        $insertStmt = $this->pdo->prepare("
            INSERT INTO metric_thresholds (server_id, metric_name_id, warning_threshold, critical_threshold, duration)
            VALUES (:server_id, :metric_name_id, :warning_threshold, :critical_threshold, :duration)
        ");

        foreach ($metricTypes as $metricType) {
            $warning = $params[$metricType['name'] . '_warning'] ?? '';
            $critical = $params[$metricType['name'] . '_critical'] ?? '';
            $duration = $params[$metricType['name'] . '_duration'] ?? 0;

            if ($warning !== '' && $critical !== '') {
                $insertStmt->execute([
                    ':server_id' => $id,
                    ':metric_name_id' => $metricType['id'],
                    ':warning_threshold' => $warning,
                    ':critical_threshold' => $critical,
                    ':duration' => $duration
                ]);
            }
        }

        return $response->withHeader('Location', "/servers/{$id}")->withStatus(302);
    }

    public function saveServices(Request $request, Response $response, $args)
    {
        $id = $args['id'];
        $params = $request->getParsedBody();
        $services = $params['services'] ?? [];

        if (is_string($services)) {
            $services = json_decode($services, true) ?? [];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO agent_configs (server_id, interval_seconds, monitor_services, enabled)
            VALUES (:server_id, 60, :services, TRUE)
            ON DUPLICATE KEY UPDATE monitor_services = VALUES(monitor_services), updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([':server_id' => $id, ':services' => json_encode($services)]);

        return $response->withHeader('Location', "/servers/{$id}?tab=services")->withStatus(302);
    }
}
