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
            SELECT s.*, sg.name as group_name, sg.icon as group_icon, sg.color as group_color,
            (SELECT MAX(sm.created_at) FROM server_metrics sm WHERE sm.server_id = s.id) as last_seen
            FROM servers s
            LEFT JOIN server_groups sg ON s.group_id = sg.id
            WHERE s.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $server = $stmt->fetch();

        if (!$server) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }

        // Получаем настройки отображаемых метрик
        $displayMetricsSetting = $server['display_metrics'] ?? null;
        $displayMetrics = null;
        if ($displayMetricsSetting) {
            $decoded = json_decode($displayMetricsSetting, true);
            if (is_array($decoded) && count($decoded) > 0) {
                $displayMetrics = $decoded;
            }
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
        
        // Формируем фильтр метрик из настроек сервера.
        // Дополнительные метрики подгружаем автоматически, если они нужны для отображения.
        $metricsFilter = '';
        $metricParams = [];
        $queryMetricNames = $displayMetrics ? $this->expandDisplayMetrics($displayMetrics) : [];
        if ($queryMetricNames) {
            $placeholders = [];
            foreach (array_values($queryMetricNames) as $i => $metricName) {
                $key = ':metric_' . $i;
                $placeholders[] = $key;
                $metricParams[$key] = $metricName;
            }
            $metricsFilter = 'AND mn.name IN (' . implode(', ', $placeholders) . ')';
        }
        
// Определяем источник данных на основе периода
        // <= 24 часа: raw данные (высокая точность)
        // > 24 часов: trends таблица (агрегированные данные)
        
        $isRawData = $totalMinutes <= 1440; // 24 часа = 1440 минут
        
        // Запрос с агрегацией если нужно
        if ($isRawData) {
            // Читаем из raw данных
            $timeExpr = "DATE_FORMAT(sm.created_at, '{$bucketFormat}')";
            $sql = "
                SELECT 
                    AVG(t.value) as value,
                    t.name,
                    t.unit,
                    t.time_bucket
                FROM (
                    SELECT 
                        sm.value, 
                        mn.name, 
                        mn.unit,
                        {$timeExpr} as time_bucket,
                        ROW_NUMBER() OVER (
                            PARTITION BY mn.name, {$timeExpr} 
                            ORDER BY sm.created_at DESC
                        ) as rn
                    FROM server_metrics sm
                    INNER JOIN metric_names mn ON mn.id = sm.metric_name_id
                    WHERE sm.server_id = :id
                    AND sm.created_at >= :start_date
                    AND sm.created_at <= :end_date
                    AND 1=1 {$metricsFilter}
                ) t
                WHERE t.rn = 1
                GROUP BY t.name, t.unit, t.time_bucket
                ORDER BY t.time_bucket ASC
            ";
            $stmt = $this->pdo->prepare($sql);
            $executeParams = array_merge([':id' => $id, ':start_date' => $startStr, ':end_date' => $endStr], $metricParams);
            $stmt->execute($executeParams);
        } else {
            // Читаем из trends таблицы (быстрый запрос для больших периодов)
            $periodStartStr = $startDate->format('Y-m-d H:00:00');
            $periodEndStr = $endDate->format('Y-m-d H:59:59');
            
            $sql = "
                SELECT 
                    t.avg_value as value,
                    mn.name,
                    mn.unit,
                    t.period_start as time_bucket
                FROM server_metrics_trends t
                INNER JOIN metric_names mn ON mn.id = t.metric_name_id
                WHERE t.server_id = :id
                AND t.period_start >= :period_start
                AND t.period_start <= :period_end
                AND 1=1 {$metricsFilter}
                ORDER BY t.period_start ASC
            ";
            $stmt = $this->pdo->prepare($sql);
            $executeParams = array_merge([':id' => $id, ':period_start' => $periodStartStr, ':period_end' => $periodEndStr], $metricParams);
            $stmt->execute($executeParams);
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

        // Типы метрик — только те что отображаются на графиках и есть у сервера
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT mn.id, mn.name, mn.unit
            FROM metric_names mn
            JOIN server_metrics sm ON sm.metric_name_id = mn.id
            WHERE sm.server_id = :id
            AND mn.name != 'uptime'
            AND (
                mn.name IN ('cpu_load', 'ram_used')
                OR mn.name LIKE 'disk_used_%'
                OR mn.name LIKE 'net_in_%'
                OR mn.name LIKE 'net_out_%'
                OR mn.name LIKE 'temp_%'
            )
            ORDER BY 
                CASE 
                    WHEN mn.name = 'cpu_load' THEN 1
                    WHEN mn.name = 'ram_used' THEN 2
                    WHEN mn.name LIKE 'disk_used_%' THEN 3
                    WHEN mn.name LIKE 'net_in_%' THEN 4
                    WHEN mn.name LIKE 'net_out_%' THEN 5
                    WHEN mn.name LIKE 'temp_%' THEN 6
                END,
                mn.name
        ");
        $stmt->execute([':id' => $id]);
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

        // Получаем последние значения метрик (для виджета аптайма)
        $stmt = $this->pdo->prepare("
            SELECT mn.name, sm.value, sm.created_at
            FROM server_metrics sm
            JOIN metric_names mn ON sm.metric_name_id = mn.id
            WHERE sm.server_id = :id
            AND mn.name = 'uptime'
            ORDER BY sm.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $latestUptime = $stmt->fetch();

        $simpleMetricCharts = $this->buildSimpleMetricCharts($groupedMetrics, $displayMetrics);
        $networkCharts = $this->buildNetworkCharts($groupedMetrics, $displayMetrics);
        $temperatureChart = $this->buildTemperatureChart($groupedMetrics, $displayMetrics);
        $diskCharts = $this->buildDiskCharts($groupedMetrics, $displayMetrics);

        $templateData = [
            'title' => 'Сервер: ' . $server['name'],
            'server' => $server,
            'metrics' => $groupedMetrics,
            'displayMetrics' => $displayMetrics,
            'simpleMetricCharts' => $simpleMetricCharts,
            'networkCharts' => $networkCharts,
            'temperatureChart' => $temperatureChart,
            'diskCharts' => $diskCharts,
            'allMetricTypes' => $allMetricTypes,
            'existingThresholds' => $existingThresholds,
            'allServices' => $allServices,
            'monitorServices' => $monitorServices,
            'latestUptime' => $latestUptime,
            'uptimeText' => $this->formatUptime($latestUptime['value'] ?? null),
            'startDate' => $startDate->format('Y-m-d\TH:i'),
            'endDate' => $endDate->format('Y-m-d\TH:i'),
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

    private function expandDisplayMetrics(array $displayMetrics): array
    {
        $expanded = [];

        foreach ($displayMetrics as $metricName) {
            if ($metricName === 'uptime') {
                continue;
            }

            $expanded[$metricName] = $metricName;

            if (str_starts_with($metricName, 'disk_used_')) {
                $suffix = substr($metricName, strlen('disk_used_'));
                $expanded['disk_total_gb_' . $suffix] = 'disk_total_gb_' . $suffix;
            }
        }

        return array_values($expanded);
    }

    private function buildSimpleMetricCharts(array $groupedMetrics, ?array $displayMetrics): array
    {
        $charts = [];
        $config = [
            'cpu_load' => ['title' => 'Загрузка CPU', 'color' => '#0d6efd'],
            'ram_used' => ['title' => 'Использование RAM', 'color' => '#198754'],
        ];

        foreach ($config as $metricName => $meta) {
            if (!$this->isMetricSelected($metricName, $displayMetrics) || empty($groupedMetrics[$metricName])) {
                continue;
            }

            $charts[] = [
                'id' => $metricName,
                'title' => $meta['title'],
                'unit' => $groupedMetrics[$metricName][0]['unit'] ?? '',
                'color' => $meta['color'],
                'labels' => $this->extractLabels($groupedMetrics[$metricName]),
                'timestamps' => $this->extractTimestamps($groupedMetrics[$metricName]),
                'values' => $this->extractValues($groupedMetrics[$metricName]),
                'lastValue' => round((float)($groupedMetrics[$metricName][0]['value'] ?? 0), 2),
                'lastTime' => $this->formatPointTime($groupedMetrics[$metricName][0] ?? []),
            ];
        }

        return $charts;
    }

    private function buildNetworkCharts(array $groupedMetrics, ?array $displayMetrics): array
    {
        $interfaces = [];

        foreach (array_keys($groupedMetrics) as $metricName) {
            if (str_starts_with($metricName, 'net_in_')) {
                $interfaces[substr($metricName, strlen('net_in_'))] = true;
            }
            if (str_starts_with($metricName, 'net_out_')) {
                $interfaces[substr($metricName, strlen('net_out_'))] = true;
            }
        }

        $charts = [];
        foreach (array_keys($interfaces) as $iface) {
            $inMetric = 'net_in_' . $iface;
            $outMetric = 'net_out_' . $iface;
            $showIn = $this->isMetricSelected($inMetric, $displayMetrics) && !empty($groupedMetrics[$inMetric]);
            $showOut = $this->isMetricSelected($outMetric, $displayMetrics) && !empty($groupedMetrics[$outMetric]);

            if (!$showIn && !$showOut) {
                continue;
            }

            $baseSeries = $showIn ? $groupedMetrics[$inMetric] : $groupedMetrics[$outMetric];
            $datasets = [];

            if ($showIn) {
                $datasets[] = [
                    'label' => 'Входящий трафик',
                    'color' => '#198754',
                    'values' => $this->extractValues($groupedMetrics[$inMetric]),
                ];
            }

            if ($showOut) {
                $datasets[] = [
                    'label' => 'Исходящий трафик',
                    'color' => '#dc3545',
                    'values' => $this->extractValues($groupedMetrics[$outMetric]),
                ];
            }

            $charts[] = [
                'id' => $iface,
                'title' => 'Сеть: ' . $iface,
                'unit' => $baseSeries[0]['unit'] ?? '',
                'labels' => $this->extractLabels($baseSeries),
                'timestamps' => $this->extractTimestamps($baseSeries),
                'datasets' => $datasets,
            ];
        }

        return $charts;
    }

    private function buildTemperatureChart(array $groupedMetrics, ?array $displayMetrics): array
    {
        $datasets = [];
        $labels = [];
        $colors = ['#dc3545', '#fd7e14', '#0dcaf0', '#6f42c1', '#20c997', '#ffc107', '#6610f2', '#198754'];
        $colorIndex = 0;

        foreach ($groupedMetrics as $metricName => $points) {
            if (!str_starts_with($metricName, 'temp_') || !$this->isMetricSelected($metricName, $displayMetrics) || empty($points)) {
                continue;
            }

            if (!$labels) {
                $labels = $this->extractLabels($points);
            }

            $datasets[] = [
                'label' => $this->formatMetricLabel($metricName),
                'color' => $colors[$colorIndex % count($colors)],
                'values' => $this->extractValues($points),
            ];
            $colorIndex++;
        }

        return [
            'unit' => '°C',
            'labels' => $labels,
            'timestamps' => $labels ? $this->extractTimestamps($points) : [],
            'datasets' => $datasets,
        ];
    }

    private function buildDiskCharts(array $groupedMetrics, ?array $displayMetrics): array
    {
        $charts = [];

        foreach ($groupedMetrics as $metricName => $points) {
            if (
                !str_starts_with($metricName, 'disk_used_')
                || $metricName === 'disk_used'
                || !$this->isMetricSelected($metricName, $displayMetrics)
                || empty($points)
            ) {
                continue;
            }

            $suffix = substr($metricName, strlen('disk_used_'));
            $totalMetric = 'disk_total_gb_' . $suffix;
            $percent = (float)($points[0]['value'] ?? 0);
            $totalGb = isset($groupedMetrics[$totalMetric][0]['value']) ? (float)$groupedMetrics[$totalMetric][0]['value'] : 0.0;
            $usedGb = $totalGb > 0 ? round(($percent / 100) * $totalGb, 1) : null;
            $freeGb = $totalGb > 0 ? round($totalGb - $usedGb, 1) : null;

            $charts[] = [
                'id' => $suffix,
                'title' => $this->formatDiskTitle($suffix),
                'percent' => round($percent, 1),
                'totalGb' => $totalGb > 0 ? round($totalGb, 1) : null,
                'usedGb' => $usedGb,
                'freeGb' => $freeGb,
                'updatedAt' => $this->formatPointTime($points[0]),
            ];
        }

        return $charts;
    }

    private function isMetricSelected(string $metricName, ?array $displayMetrics): bool
    {
        return is_array($displayMetrics) && in_array($metricName, $displayMetrics, true);
    }

    private function extractLabels(array $points): array
    {
        $labels = [];

        foreach ($points as $point) {
            $labels[] = $this->formatPointTime($point, 'd.m H:i');
        }

        return $labels;
    }

    private function extractValues(array $points): array
    {
        $values = [];

        foreach ($points as $point) {
            $values[] = round((float)($point['value'] ?? 0), 2);
        }

        return $values;
    }

    private function extractTimestamps(array $points): array
    {
        $timestamps = [];

        foreach ($points as $point) {
            $timestamps[] = $point['time_bucket'] ?? $point['created_at'] ?? null;
        }

        return $timestamps;
    }

    private function formatPointTime(array $point, string $format = 'd.m.Y H:i:s'): string
    {
        $raw = $point['time_bucket'] ?? $point['created_at'] ?? null;
        if (!$raw) {
            return '';
        }

        return (new DateTime($raw))->format($format);
    }

    private function formatMetricLabel(string $metricName): string
    {
        if ($metricName === 'cpu_load') {
            return 'Загрузка CPU';
        }

        if ($metricName === 'ram_used') {
            return 'Использование RAM';
        }

        if (str_starts_with($metricName, 'temp_')) {
            return 'Температура ' . str_replace('_', ' ', substr($metricName, strlen('temp_')));
        }

        return str_replace('_', ' ', $metricName);
    }

    private function formatDiskTitle(string $suffix): string
    {
        return match ($suffix) {
            'root' => '/ (корень)',
            'home' => '/home',
            'boot' => '/boot',
            'mnt_data' => '/mnt/data',
            default => '/' . str_replace('_', '/', $suffix),
        };
    }

    private function formatUptime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $seconds = (int)round((float)$value);
        if ($seconds < 0) {
            return null;
        }

        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ' д';
        }
        if ($hours > 0 || $days > 0) {
            $parts[] = $hours . ' ч';
        }
        $parts[] = $minutes . ' мин';

        return implode(' ', $parts);
    }

    public function saveThresholds(Request $request, Response $response, $args)
    {
        $id = $args['id'];
        $params = $request->getParsedBody();

        // Получаем только метрики которые есть у сервера и отображаются на графиках
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT mn.id, mn.name, mn.unit
            FROM metric_names mn
            JOIN server_metrics sm ON sm.metric_name_id = mn.id
            WHERE sm.server_id = :id
            AND mn.name != 'uptime'
            AND (
                mn.name IN ('cpu_load', 'ram_used')
                OR mn.name LIKE 'disk_used_%'
                OR mn.name LIKE 'net_in_%'
                OR mn.name LIKE 'net_out_%'
                OR mn.name LIKE 'temp_%'
            )
            ORDER BY 
                CASE 
                    WHEN mn.name = 'cpu_load' THEN 1
                    WHEN mn.name = 'ram_used' THEN 2
                    WHEN mn.name LIKE 'disk_used_%' THEN 3
                    WHEN mn.name LIKE 'net_in_%' THEN 4
                    WHEN mn.name LIKE 'net_out_%' THEN 5
                    WHEN mn.name LIKE 'temp_%' THEN 6
                END,
                mn.name
        ");
        $stmt->execute([':id' => $id]);
        $metricTypes = $stmt->fetchAll();

        $stmt = $this->pdo->prepare("DELETE FROM metric_thresholds WHERE server_id = :server_id");
        $stmt->execute([':server_id' => $id]);

        $insertStmt = $this->pdo->prepare("
            INSERT INTO metric_thresholds (server_id, metric_name_id, warning_threshold, critical_threshold, duration)
            VALUES (:server_id, :metric_name_id, :warning_threshold, :critical_threshold, :duration)
        ");

        // Дефолтные значения порогов (пока хардкод, потом из настроек)
        $defaultWarning = 80;
        $defaultCritical = 90;
        $defaultDuration = 0;

        $saved = [];
        $usedDefaults = [];

        foreach ($metricTypes as $metricType) {
            $warning = $params[$metricType['name'] . '_warning'] ?? '';
            $critical = $params[$metricType['name'] . '_critical'] ?? '';
            $duration = $params[$metricType['name'] . '_duration'] ?? '';

            // Сохраняем если хотя бы один порог заполнен
            if ($warning !== '' || $critical !== '') {
                // Если не указано - используем дефолт
                $warningVal = $warning !== '' ? (float)$warning : $defaultWarning;
                $criticalVal = $critical !== '' ? (float)$critical : $defaultCritical;
                $durationVal = $duration !== '' ? (int)$duration : $defaultDuration;

                $insertStmt->execute([
                    ':server_id' => $id,
                    ':metric_name_id' => $metricType['id'],
                    ':warning_threshold' => $warningVal,
                    ':critical_threshold' => $criticalVal,
                    ':duration' => $durationVal
                ]);

                $name = $metricType['name'];
                $used = [];
                if ($warning === '') $used[] = 'warning=' . $defaultWarning;
                if ($critical === '') $used[] = 'critical=' . $defaultCritical;
                if ($duration === '') $used[] = 'duration=' . $defaultDuration;

                if (count($used) > 0) {
                    $usedDefaults[] = $name . ' (' . implode(', ', $used) . ')';
                } else {
                    $saved[] = $name;
                }
            }
        }

        // Формируем flash сообщение
        $messages = [];
        if (count($saved) > 0) {
            $messages[] = 'Сохранено: ' . implode(', ', $saved);
        }
        if (count($usedDefaults) > 0) {
            $messages[] = 'Для остальных подставлены значения по умолчанию: ' . implode(', ', $usedDefaults);
        }
        if (count($messages) === 0) {
            $messages[] = 'Все пороги удалены';
        }

        $_SESSION['flash_message'] = implode('. ', $messages);
        $_SESSION['flash_type'] = count($usedDefaults) > 0 ? 'warning' : 'success';

        return $response->withHeader('Location', "/servers/{$id}?tab=thresholds")->withStatus(302);
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
