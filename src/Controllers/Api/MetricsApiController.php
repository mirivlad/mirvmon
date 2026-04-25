<?php
// src/Controllers/Api/MetricsApiController.php

namespace App\Controllers\Api;

use App\Models\Model;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use DateTime;

class MetricsApiController extends Model
{
    private const MAX_POINTS = 500;

    public function getServerMetrics(Request $request, Response $response, $args)
    {
        $id = $args['id'];

        // Параметры
        $queryParams = $request->getQueryParams();
        $period = $queryParams['period'] ?? '7d';
        $startParam = $queryParams['start'] ?? null;
        $endParam = $queryParams['end'] ?? null;
        $zoom = $queryParams['zoom'] ?? null;

        // Вычисляем даты
        $endDate = new DateTime();
        $startDate = clone $endDate;

        if ($startParam && $endParam) {
            // Используем переданные даты
            $startDate = new DateTime($startParam);
            $endDate = new DateTime($endParam);
        } else {
            // Вычисляем по period
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
        }

        // Вычисляем интервал агрегации
        $totalMinutes = ($endDate->getTimestamp() - $startDate->getTimestamp()) / 60;
        $aggregationMinutes = max(1, ceil($totalMinutes / self::MAX_POINTS));
        $aggregationSeconds = $aggregationMinutes * 60;

        // Получаем метрики для графиков (исключая uptime и top_*)
        $stmt = $this->pdo->prepare("
            SELECT 
                mn.name,
                mn.unit,
                FLOOR(TIMESTAMPDIFF(SECOND, :start, sm.created_at) / :agg) as bucket,
                AVG(sm.value) as value,
                MAX(sm.created_at) as created_at
            FROM server_metrics sm
            JOIN metric_names mn ON sm.metric_name_id = mn.id
            WHERE sm.server_id = :server_id
            AND sm.created_at BETWEEN :start AND :end
            AND mn.name NOT IN ('uptime')
            AND mn.name NOT LIKE '%_proc'
            AND (
                mn.name IN ('cpu_load', 'ram_used')
                OR mn.name LIKE 'disk_used_%'
                OR mn.name LIKE 'net_in_%'
                OR mn.name LIKE 'net_out_%'
                OR mn.name LIKE 'temp_%'
            )
            GROUP BY mn.name, bucket
            ORDER BY mn.name, bucket
        ");

        $stmt->execute([
            ':server_id' => $id,
            ':start' => $startDate->format('Y-m-d H:i:s'),
            ':end' => $endDate->format('Y-m-d H:i:s'),
            ':agg' => $aggregationSeconds
        ]);

        $rawData = $stmt->fetchAll();

        // Группируем по метрикам
        $datasets = [];
        $labels = [];
        $minTime = null;
        $maxTime = null;

        foreach ($rawData as $row) {
            $metricName = $row['name'];
            $time = $row['created_at'];
            
            if (!isset($datasets[$metricName])) {
                $datasets[$metricName] = [];
            }

            $datasets[$metricName][] = (float)$row['value'];
            
            // Собираем уникальные метки времени
            if (!in_array($time, $labels)) {
                $labels[] = $time;
            }
            
            if ($minTime === null || $time < $minTime) $minTime = $time;
            if ($maxTime === null || $time > $maxTime) $maxTime = $time;
        }

        // Форматируем labels
        $formattedLabels = array_map(function($label) {
            return (new DateTime($label))->format('d.m H:i');
        }, $labels);

        // Получаем TOP процессы для последней точки
        $topCpu = [];
        $topRam = [];

        $stmt = $this->pdo->prepare("
            SELECT mn.name, sm.value
            FROM server_metrics sm
            JOIN metric_names mn ON sm.metric_name_id = mn.id
            WHERE sm.server_id = :server_id AND mn.name = 'top_cpu_proc'
            ORDER BY sm.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([':server_id' => $id]);
        $topData = $stmt->fetchAll();
        if ($topData) {
            $topCpu = json_decode($topData[0]['value'] ?? '[]', true) ?? [];
        }

        $stmt = $this->pdo->prepare("
            SELECT sm.value
            FROM server_metrics sm
            JOIN metric_names mn ON sm.metric_name_id = mn.id
            WHERE sm.server_id = :server_id AND mn.name = 'top_ram_proc'
            ORDER BY sm.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':server_id' => $id]);
        $topRamData = $stmt->fetch();
        if ($topRamData) {
            $topRam = json_decode($topRamData['value'] ?? '[]', true) ?? [];
        }

        // Формируем ответ
        $result = [
            'period' => $period,
            'start' => $startDate->format('Y-m-d H:i:s'),
            'end' => $endDate->format('Y-m-d H:i:s'),
            'aggregation_minutes' => $aggregationMinutes,
            'total_points' => count($labels),
            'labels' => $formattedLabels,
            'datasets' => $datasets,
            'top_cpu' => $topCpu,
            'top_ram' => $topRam
        ];

        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}