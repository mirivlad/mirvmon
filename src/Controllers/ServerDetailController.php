<?php
// src/Controllers/ServerDetailController.php

namespace App\Controllers;

use App\Models\Model;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use DateTime;

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

        // Получаем параметры
        $queryParams = $request->getQueryParams();
        $startDate = $queryParams['start'] ?? null;
        $endDate = $queryParams['end'] ?? null;
        $period = $queryParams['period'] ?? '24h';
        $zoom = $queryParams['zoom'] ?? null;
        
        // Если даты не указаны, вычисляем по period
        if (!$startDate || !$endDate) {
            $endDate = new DateTime();
            $startDate = clone $endDate;
            
            switch ($period) {
                case '1h':
                    $startDate->modify('-1 hour');
                    break;
                case '6h':
                    $startDate->modify('-6 hours');
                    break;
                case '7d':
                    $startDate->modify('-7 days');
                    break;
                case '30d':
                    $startDate->modify('-30 days');
                    break;
                case '24h':
                default:
                    $startDate->modify('-24 hours');
                    break;
            }
        } else {
            $startDate = new DateTime($startDate);
            $endDate = new DateTime($endDate);
        }
        
        // Применяем zoom — ограничиваем end по zoom-периоду
        if ($zoom && $zoom !== 'auto') {
            $zoomEnd = new DateTime();
            $zoomStart = clone $zoomEnd;
            switch ($zoom) {
                case '1h':
                    $zoomStart->modify('-1 hour');
                    break;
                case '6h':
                    $zoomStart->modify('-6 hours');
                    break;
                case '24h':
                    $zoomStart->modify('-24 hours');
                    break;
                case '7d':
                    $zoomStart->modify('-7 days');
                    break;
                case '30d':
                    $zoomStart->modify('-30 days');
                    break;
            }
            // Zoom не может выйти за рамки выбранного периода
            if ($zoomStart < $startDate) $zoomStart = clone $startDate;
            if ($zoomEnd > $endDate) $zoomEnd = clone $endDate;
            $startDate = $zoomStart;
            $endDate = $zoomEnd;
        }
        
        // Валидация: end > start
        if ($endDate <= $startDate) {
            $endDate = clone $startDate;
            $endDate->modify('+24 hours');
        }
        
        // Вычисляем длительность периода для агрегации
        $interval = $startDate->diff($endDate);
        $totalMinutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
        
        // Конфигурация агрегации на основе дат
        $aggConfig = $this->getAggregationConfigFromDates($startDate, $endDate, $totalMinutes);
        
        $groupBy = $aggConfig['groupBy'];
        $bucketFormat = $aggConfig['format'];
        
        // Форматируем даты для SQL
        $startStr = $startDate->format('Y-m-d H:i:s');
        $endStr = $endDate->format('Y-m-d H:i:s');
        
        // Запрос с агрегацией если нужно
        if ($groupBy) {
            $sql = "
                SELECT 
                    AVG(sm.value) as value,
                    mn.name,
                    mn.unit,
                    DATE_FORMAT(sm.created_at, '{$bucketFormat}') as time_bucket
                FROM server_metrics sm
                JOIN metric_names mn ON sm.metric_name_id = mn.id
                WHERE sm.server_id = :id
                AND sm.created_at >= :start_date
                AND sm.created_at <= :end_date
                {$groupBy}
                ORDER BY time_bucket ASC
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $id, ':start_date' => $startStr, ':end_date' => $endStr]);
        } else {
            $sql = "
                SELECT sm.value, mn.name, mn.unit, sm.created_at
                FROM server_metrics sm
                JOIN metric_names mn ON sm.metric_name_id = mn.id
                WHERE sm.server_id = :id
                AND sm.created_at >= :start_date
                AND sm.created_at <= :end_date
                ORDER BY sm.created_at ASC
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $id, ':start_date' => $startStr, ':end_date' => $endStr]);
        }
        
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

        // Сортируем метрики в фиксированном порядке: cpu_load → ram_used → disk_used → остальные
        $priorityOrder = ['cpu_load', 'ram_used', 'disk_used'];
        $sortedMetrics = [];
        
        // Сначала добавляем приоритетные метрики в нужном порядке
        foreach ($priorityOrder as $metricName) {
            if (isset($groupedMetrics[$metricName])) {
                $sortedMetrics[$metricName] = $groupedMetrics[$metricName];
                unset($groupedMetrics[$metricName]);
            }
        }
        
        // Затем добавляем остальные метрики (например, top_cpu_proc, top_ram_proc)
        foreach ($groupedMetrics as $metricName => $metricData) {
            $sortedMetrics[$metricName] = $metricData;
        }
        
        $groupedMetrics = $sortedMetrics;

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
        $stmt = $this->pdo->query("SELECT id, name, unit FROM metric_names WHERE name NOT LIKE '%\_proc' AND name NOT LIKE 'disk_total_gb_%' AND name != 'disk_used' AND name != 'ram_total_gb' AND name NOT IN ('net_in', 'net_out') AND name NOT LIKE 'network_%' ORDER BY name");
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
            'startDate' => $startDate->format('Y-m-d\T H:i'),
            'endDate' => $endDate->format('Y-m-d\T H:i'),
            'aggregation' => $aggConfig,
            'totalMinutes' => $totalMinutes,
            'period' => $period,
            'zoom' => $zoom
        ];

        return $this->twig->render($response, 'servers/detail.twig', $templateData);
    }
    
    private function getAggregationConfigFromDates(DateTime $startDate, DateTime $endDate, int $totalMinutes): array
    {
        // Target: ~400 points on chart for optimal performance
        // Formula: aggregate_minutes = total_minutes / 400
        
        $targetPoints = 400;
        $aggregateMinutes = ceil($totalMinutes / $targetPoints);
        
        // Определяем формат группировки на основе длительности агрегации
        if ($aggregateMinutes <= 1) {
            // Менее 1 минуты — без агрегации
            return [
                'groupBy' => null,
                'format' => '%Y-%m-%d %H:%i:%s',
                'aggregate_minutes' => 0
            ];
        } elseif ($aggregateMinutes < 60) {
            // Минуты — группировка по минутам
            return [
                'groupBy' => "GROUP BY mn.id, DATE_FORMAT(sm.created_at, '%Y-%m-%d %H:%i')",
                'format' => '%Y-%m-%d %H:%i',
                'aggregate_minutes' => $aggregateMinutes
            ];
        } elseif ($aggregateMinutes < 1440) {
            // Часы — группировка по часам
            return [
                'groupBy' => "GROUP BY mn.id, DATE_FORMAT(sm.created_at, '%Y-%m-%d %H:00')",
                'format' => '%Y-%m-%d %H:00',
                'aggregate_minutes' => $aggregateMinutes
            ];
        } else {
            // Дни — группировка по дням
            return [
                'groupBy' => "GROUP BY mn.id, DATE_FORMAT(sm.created_at, '%Y-%m-%d')",
                'format' => '%Y-%m-%d',
                'aggregate_minutes' => $aggregateMinutes
            ];
        }
    }

    public function saveThresholds(Request $request, Response $response, $args)
    {
        $id = $args['id'];
        $params = $request->getParsedBody();

        $stmt = $this->pdo->query("SELECT id, name FROM metric_names WHERE name NOT LIKE '%\_proc' AND name NOT LIKE 'disk_total_gb_%' AND name != 'disk_used' AND name != 'ram_total_gb' AND name NOT IN ('net_in', 'net_out') AND name NOT LIKE 'network_%' ORDER BY name");
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
