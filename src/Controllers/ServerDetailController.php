<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Metrics\MetricValueFormatter;
use App\I18n\Translator;
use App\Repositories\AvailabilityRepository;
use App\Repositories\MaintenanceWindowRepository;
use App\Repositories\MetricRepository;
use App\Repositories\ServerRepository;
use App\Services\AgentUpdateService;
use App\Services\ServerStatusService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

/**
 * @phpstan-type MetricPoint array<string, mixed>
 * @phpstan-type GroupedMetrics array<string, list<MetricPoint>>
 * @phpstan-type CurrentMetrics array<string, MetricPoint>
 * @phpstan-type Chart array<string, mixed>
 */
final class ServerDetailController
{
    private const PERIODS = [
        '1h' => '-1 hour',
        '6h' => '-6 hours',
        '24h' => '-24 hours',
        '7d' => '-7 days',
        '30d' => '-30 days',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly ServerRepository $servers,
        private readonly MetricRepository $metrics,
        private readonly MaintenanceWindowRepository $maintenance,
        private readonly ?AgentUpdateService $agentUpdates,
        private readonly ServerStatusService $status,
        private readonly Translator $translator
    ) {
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }

        $server = $this->servers->find($serverId);
        if ($server === null) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }
        $server = $this->status->enrich([$server])[0];
        $agentToken = $this->pdo->prepare(
            'SELECT token_generation FROM agent_tokens WHERE server_id = :server_id'
        );
        $agentToken->execute(['server_id' => $serverId]);
        $tokenGeneration = $agentToken->fetchColumn();

        $query = $request->getQueryParams();
        $period = is_string($query['period'] ?? null) && isset(self::PERIODS[$query['period']])
            ? $query['period']
            : '24h';
        $zoom = is_string($query['zoom'] ?? null) ? $query['zoom'] : null;
        [$startDate, $endDate] = $this->metricRange($query, $period, $zoom);
        $totalMinutes = max(
            1,
            (int) ceil(($endDate->getTimestamp() - $startDate->getTimestamp()) / 60)
        );

        $displayMetrics = $server['display_metrics'] === [] ? null : $server['display_metrics'];
        $queryMetricNames = $displayMetrics === null ? [] : $this->expandDisplayMetrics($displayMetrics);
        $series = $queryMetricNames === []
            ? [
                'source' => $this->metrics->sourceForRange($startDate, $endDate),
                'bucket_seconds' => 0,
                'points' => [],
            ]
            : $this->metrics->series($serverId, $startDate, $endDate, $queryMetricNames);
        $groupedMetrics = $this->groupMetricPoints($series['points']);
        $currentMetrics = $queryMetricNames === [] ? [] : $this->metrics->latestValues($serverId, $queryMetricNames);
        $existingThresholds = $this->servers->thresholds($serverId);
        $latestUptime = $this->metrics->latestUptime($serverId);

        $simpleMetricCharts = $this->buildSimpleMetricCharts(
            $groupedMetrics,
            $displayMetrics,
            $existingThresholds,
            $currentMetrics
        );
        $networkCharts = $this->buildNetworkCharts($groupedMetrics, $displayMetrics);
        $diskIoCharts = $this->buildDiskIoCharts($groupedMetrics, $displayMetrics);
        $temperatureChart = $this->buildTemperatureChart($groupedMetrics, $displayMetrics);
        $diskCharts = $this->buildDiskCharts($groupedMetrics, $displayMetrics, $currentMetrics);
        $uptimeChart = $this->buildUptimeChart($groupedMetrics, $displayMetrics);
        $availabilityChart = [];
        if ($this->isMetricSelected('availability', $displayMetrics)) {
            $availability = (new AvailabilityRepository($this->pdo))->timeline(
                $serverId,
                $startDate,
                $endDate
            );
            $availabilityChart = $this->buildAvailabilityChart($availability);
        }
        $summaryCards = $this->buildSummaryCards(
            $groupedMetrics,
            $displayMetrics,
            $temperatureChart,
            $diskCharts,
            $networkCharts,
            $currentMetrics
        );
        if (($availabilityChart['known'] ?? false) === true) {
            $summaryCards[] = [
                'title' => $this->translator->trans('metric.availability_period'),
                'value' => $availabilityChart['availabilityPercent'] . '%',
                'subtitle' => $this->translator->trans('metric.downtime_outages', [
                    'downtime' => $availabilityChart['downtimeText'],
                    'outages' => $availabilityChart['outages'],
                ]),
            ];
        }

        return $this->twig->render($response, 'servers/detail.twig', [
            'title' => $this->translator->trans('server.page_title', ['name' => $server['name']]),
            'server' => $server,
            'has_agent_token' => $tokenGeneration !== false,
            'requires_token_rotation' => $tokenGeneration === null,
            'agent_update' => $this->agentUpdates?->statusForServer($serverId),
            'metrics' => $groupedMetrics,
            'displayMetrics' => $displayMetrics,
            'simpleMetricCharts' => $simpleMetricCharts,
            'networkCharts' => $networkCharts,
            'diskIoCharts' => $diskIoCharts,
            'temperatureChart' => $temperatureChart,
            'diskCharts' => $diskCharts,
            'uptimeChart' => $uptimeChart,
            'availabilityChart' => $availabilityChart,
            'summaryCards' => $summaryCards,
            'allMetricTypes' => $this->metrics->metricTypes($serverId),
            'existingThresholds' => $existingThresholds,
            'allServices' => $this->servers->services($serverId),
            'monitorServices' => $this->servers->monitoredServices($serverId),
            'maintenance' => $this->maintenance->active($serverId),
            'latestUptime' => $latestUptime,
            'uptimeText' => $this->formatUptime($latestUptime['value'] ?? null),
            'startDate' => $startDate->format('Y-m-d\TH:i'),
            'endDate' => $endDate->format('Y-m-d\TH:i'),
            'aggregation' => [
                'source' => $series['source'],
                'seconds' => $series['bucket_seconds'],
            ],
            'totalMinutes' => $totalMinutes,
            'period' => $period,
            'zoom' => $zoom,
        ]);
    }

    /** @param array<string, mixed> $args */
    private function serverId(array $args): ?int
    {
        $serverId = filter_var(
            $args['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        return $serverId === false ? null : $serverId;
    }

    /** @param array<string, mixed> $query @return array{DateTimeImmutable, DateTimeImmutable} */
    private function metricRange(array $query, string $period, ?string $zoom): array
    {
        $end = new DateTimeImmutable();
        $start = $end->modify(self::PERIODS[$period]);
        if (is_string($query['start'] ?? null) && is_string($query['end'] ?? null)) {
            try {
                $requestedStart = new DateTimeImmutable($query['start']);
                $requestedEnd = new DateTimeImmutable($query['end']);
                if ($requestedEnd > $requestedStart) {
                    $start = $requestedStart;
                    $end = $requestedEnd;
                }
            } catch (Throwable) {
                // Keep the selected safe period for malformed optional dates.
            }
        }
        if ($zoom !== null && isset(self::PERIODS[$zoom])) {
            $zoomStart = $end->modify(self::PERIODS[$zoom]);
            if ($zoomStart > $start) {
                $start = $zoomStart;
            }
        }
        $maximumStart = $end->modify('-2 years');
        if ($start < $maximumStart) {
            $start = $maximumStart;
        }
        return [$start, $end];
    }

    /** @param list<array<string, mixed>> $points @return array<string, list<array<string, mixed>>> */
    private function groupMetricPoints(array $points): array
    {
        $grouped = [];
        foreach ($points as $point) {
            $grouped[$point['name']][] = $point;
        }
        $priority = ['cpu_load', 'ram_used', 'uptime', 'disk_used'];
        uksort($grouped, static function (string $left, string $right) use ($priority): int {
            $leftPriority = array_search($left, $priority, true);
            $rightPriority = array_search($right, $priority, true);
            $leftOrder = $leftPriority === false ? PHP_INT_MAX : $leftPriority;
            $rightOrder = $rightPriority === false ? PHP_INT_MAX : $rightPriority;
            return $leftOrder <=> $rightOrder ?: strnatcasecmp($left, $right);
        });
        return $grouped;
    }

    /** @param list<string> $displayMetrics @return list<string> */
    private function expandDisplayMetrics(array $displayMetrics): array
    {
        $expanded = [];
        foreach ($displayMetrics as $metricName) {
            if ($metricName === 'availability') {
                continue;
            }
            $expanded[$metricName] = $metricName;
            if ($metricName === 'ram_used') {
                $expanded['ram_total_gb'] = 'ram_total_gb';
            }
            if (str_starts_with($metricName, 'disk_used_')) {
                $suffix = substr($metricName, strlen('disk_used_'));
                $expanded['disk_total_gb_' . $suffix] = 'disk_total_gb_' . $suffix;
            }
        }
        return array_values($expanded);
    }

    /** @param GroupedMetrics $groupedMetrics @param list<string>|null $displayMetrics @param array<string, mixed> $existingThresholds @param CurrentMetrics $currentMetrics @return list<Chart> */
    private function buildSimpleMetricCharts(
        array $groupedMetrics,
        ?array $displayMetrics,
        array $existingThresholds,
        array $currentMetrics
    ): array {
        $charts = [];
        $config = [
            'cpu_load' => ['title' => $this->translator->trans('metric.cpu_load'), 'color' => '#0d6efd'],
            'ram_used' => ['title' => $this->translator->trans('metric.ram_used'), 'color' => '#198754'],
        ];
        foreach ($config as $metricName => $meta) {
            if (!$this->isMetricSelected($metricName, $displayMetrics) || empty($groupedMetrics[$metricName])) {
                continue;
            }
            $latestPoint = $currentMetrics[$metricName] ?? $this->latestPoint($groupedMetrics[$metricName]);
            $charts[] = [
                'id' => $metricName,
                'title' => $meta['title'],
                'unit' => $latestPoint['unit'] ?? '',
                'color' => $meta['color'],
                'labels' => $this->extractLabels($groupedMetrics[$metricName]),
                'timestamps' => $this->extractTimestamps($groupedMetrics[$metricName]),
                'values' => $this->extractValues($groupedMetrics[$metricName]),
                'lastValue' => round((float) ($latestPoint['value'] ?? 0), 2),
                'lastTime' => $this->formatPointTime($latestPoint),
                'thresholds' => $existingThresholds[$metricName] ?? null,
                'details' => $metricName === 'ram_used'
                    ? $this->buildRamDetails($groupedMetrics, $currentMetrics)
                    : null,
            ];
        }
        usort($charts, fn ($a, $b) => strnatcasecmp($a['title'], $b['title']));
        return $charts;
    }

    /** @param GroupedMetrics $groupedMetrics @param list<string>|null $displayMetrics @return list<Chart> */
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
                    'label' => $this->translator->trans('metric.network_in'),
                    'color' => '#198754',
                    'values' => $this->extractValues($groupedMetrics[$inMetric]),
                ];
            }
            if ($showOut) {
                $datasets[] = [
                    'label' => $this->translator->trans('metric.network_out'),
                    'color' => '#dc3545',
                    'values' => $this->extractValues($groupedMetrics[$outMetric]),
                ];
            }
            $charts[] = [
                'id' => $iface,
                'title' => $this->translator->trans('metric.network', ['name' => $iface]),
                'unit' => $baseSeries[0]['unit'] ?? '',
                'labels' => $this->extractLabels($baseSeries),
                'timestamps' => $this->extractTimestamps($baseSeries),
                'datasets' => $datasets,
            ];
        }
        usort($charts, fn ($a, $b) => strnatcasecmp($a['id'], $b['id']));
        return $charts;
    }

    /** @param GroupedMetrics $groupedMetrics @param list<string>|null $displayMetrics @return list<Chart> */
    private function buildDiskIoCharts(array $groupedMetrics, ?array $displayMetrics): array
    {
        $devices = [];
        foreach (array_keys($groupedMetrics) as $metricName) {
            if (str_starts_with($metricName, 'disk_read_')) {
                $devices[substr($metricName, strlen('disk_read_'))] = true;
            }
            if (str_starts_with($metricName, 'disk_write_')) {
                $devices[substr($metricName, strlen('disk_write_'))] = true;
            }
        }
        $charts = [];
        foreach (array_keys($devices) as $device) {
            $readMetric = 'disk_read_' . $device;
            $writeMetric = 'disk_write_' . $device;
            $showRead = $this->isMetricSelected($readMetric, $displayMetrics) && !empty($groupedMetrics[$readMetric]);
            $showWrite = $this->isMetricSelected($writeMetric, $displayMetrics) && !empty($groupedMetrics[$writeMetric]);
            if (!$showRead && !$showWrite) {
                continue;
            }
            $baseSeries = $showRead ? $groupedMetrics[$readMetric] : $groupedMetrics[$writeMetric];
            $datasets = [];
            if ($showRead) {
                $datasets[] = [
                    'label' => $this->translator->trans('metric.disk_read'),
                    'color' => '#0d6efd',
                    'values' => $this->extractValues($groupedMetrics[$readMetric]),
                ];
            }
            if ($showWrite) {
                $datasets[] = [
                    'label' => $this->translator->trans('metric.disk_write'),
                    'color' => '#fd7e14',
                    'values' => $this->extractValues($groupedMetrics[$writeMetric]),
                ];
            }
            $charts[] = [
                'id' => $device,
                'title' => $this->translator->trans('metric.disk_io', ['name' => $device]),
                'unit' => $baseSeries[0]['unit'] ?? 'B/s',
                'labels' => $this->extractLabels($baseSeries),
                'timestamps' => $this->extractTimestamps($baseSeries),
                'datasets' => $datasets,
            ];
        }
        usort($charts, fn ($a, $b) => strnatcasecmp($a['id'], $b['id']));
        return $charts;
    }

    /** @param GroupedMetrics $groupedMetrics @param list<string>|null $displayMetrics @return Chart */
    private function buildTemperatureChart(array $groupedMetrics, ?array $displayMetrics): array
    {
        $datasets = [];
        $labels = [];
        $timestamps = [];
        $colors = ['#dc3545', '#fd7e14', '#0dcaf0', '#6f42c1', '#20c997', '#ffc107', '#6610f2', '#198754'];
        $colorIndex = 0;
        $tempSeries = [];
        foreach ($groupedMetrics as $metricName => $points) {
            if (!str_starts_with($metricName, 'temp_') || !$this->isMetricSelected($metricName, $displayMetrics) || empty($points)) {
                continue;
            }
            if (!$labels) {
                $labels = $this->extractLabels($points);
                $timestamps = $this->extractTimestamps($points);
            }
            $tempSeries[] = [
                'label' => $this->formatMetricLabel($metricName),
                'metricName' => $metricName,
                'values' => $this->extractValues($points),
            ];
        }
        usort($tempSeries, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));
        foreach ($tempSeries as $series) {
            $datasets[] = [
                'label' => $series['label'],
                'metricName' => $series['metricName'],
                'color' => $colors[$colorIndex % count($colors)],
                'values' => $series['values'],
            ];
            $colorIndex++;
        }
        return [
            'unit' => '°C',
            'labels' => $labels,
            'timestamps' => $timestamps,
            'datasets' => $datasets,
        ];
    }

    /** @param GroupedMetrics $groupedMetrics @param list<string>|null $displayMetrics @param CurrentMetrics $currentMetrics @return list<Chart> */
    private function buildDiskCharts(array $groupedMetrics, ?array $displayMetrics, array $currentMetrics): array
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
            $latestPoint = $currentMetrics[$metricName] ?? $this->latestPoint($points);
            $latestTotal = $currentMetrics[$totalMetric] ?? $this->latestPoint($groupedMetrics[$totalMetric] ?? []);
            $percent = (float) ($latestPoint['value'] ?? 0);
            $totalGb = (float) ($latestTotal['value'] ?? 0);
            $usedGb = $totalGb > 0 ? round(($percent / 100) * $totalGb, 1) : null;
            $freeGb = $totalGb > 0 ? round($totalGb - $usedGb, 1) : null;
            $charts[] = [
                'id' => $suffix,
                'title' => $this->formatDiskTitle($suffix),
                'percent' => round($percent, 1),
                'totalGb' => $totalGb > 0 ? round($totalGb, 1) : null,
                'usedGb' => $usedGb,
                'freeGb' => $freeGb,
                'updatedAt' => $this->formatPointTime($latestPoint),
            ];
        }
        usort($charts, fn ($a, $b) => strnatcasecmp($a['title'], $b['title']));
        return $charts;
    }

    /** @param GroupedMetrics $groupedMetrics @param list<string>|null $displayMetrics @return Chart */
    private function buildUptimeChart(array $groupedMetrics, ?array $displayMetrics): array
    {
        if (!$this->isMetricSelected('uptime', $displayMetrics) || empty($groupedMetrics['uptime'])) {
            return [];
        }
        $points = $groupedMetrics['uptime'];
        $latest = $this->latestPoint($points);
        return [
            'title' => $this->translator->trans('metric.uptime_os'),
            'unit' => 'uptime',
            'labels' => $this->extractLabels($points),
            'timestamps' => $this->extractTimestamps($points),
            'values' => $this->extractValues($points),
            'lastText' => $this->formatUptime($latest['value'] ?? null),
            'lastTime' => $this->formatPointTime($latest),
        ];
    }

    /** @param array<string, mixed> $availability @return Chart */
    private function buildAvailabilityChart(array $availability): array
    {
        if (($availability['known'] ?? false) !== true) {
            return ['known' => false, 'labels' => [], 'values' => []];
        }
        $labels = [];
        $values = [];
        foreach ($availability['points'] as $point) {
            $labels[] = (new DateTimeImmutable((string) $point['time']))->format('d.m H:i');
            $values[] = (int) $point['value'];
        }
        return [
            'known' => true,
            'labels' => $labels,
            'values' => $values,
            'availabilityPercent' => (float) ($availability['availability_percent'] ?? 0),
            'downtimeText' => $this->formatUptime($availability['downtime_seconds'] ?? 0)
                ?? '0 ' . $this->translator->trans('common.minutes'),
            'outages' => (int) ($availability['outages'] ?? 0),
        ];
    }

    /** @param GroupedMetrics $groupedMetrics @param list<string>|null $displayMetrics @param Chart $temperatureChart @param list<Chart> $diskCharts @param list<Chart> $networkCharts @param CurrentMetrics $currentMetrics @return list<Chart> */
    private function buildSummaryCards(
        array $groupedMetrics,
        ?array $displayMetrics,
        array $temperatureChart,
        array $diskCharts,
        array $networkCharts,
        array $currentMetrics
    ): array {
        $cards = [];
        $latestCpu = $currentMetrics['cpu_load'] ?? $this->latestPoint($groupedMetrics['cpu_load'] ?? []);
        if ($this->isMetricSelected('cpu_load', $displayMetrics) && isset($latestCpu['value'])) {
            $cards[] = [
                'title' => $this->translator->trans('metric.cpu_now'),
                'value' => round((float) $latestCpu['value'], 2) . '%',
                'subtitle' => $this->formatPointTime($latestCpu),
            ];
        }
        $latestRam = $currentMetrics['ram_used'] ?? $this->latestPoint($groupedMetrics['ram_used'] ?? []);
        if ($this->isMetricSelected('ram_used', $displayMetrics) && isset($latestRam['value'])) {
            $ramDetails = $this->buildRamDetails($groupedMetrics, $currentMetrics);
            $cards[] = [
                'title' => $this->translator->trans('metric.ram_now'),
                'value' => round((float) $latestRam['value'], 2) . '%',
                'subtitle' => $ramDetails
                    ? $this->translator->trans('metric.total_used_free', [
                        'total' => $ramDetails['totalGb'],
                        'used' => $ramDetails['usedGb'],
                        'free' => $ramDetails['freeGb'],
                    ])
                    : $this->formatPointTime($latestRam),
            ];
        }
        if (!empty($temperatureChart['datasets'])) {
            $hottest = null;
            foreach ($temperatureChart['datasets'] as $dataset) {
                $current = $currentMetrics[$dataset['metricName']]['value']
                    ?? $dataset['values'][count($dataset['values']) - 1]
                    ?? null;
                if ($current === null) {
                    continue;
                }
                if ($hottest === null || $current > $hottest['value']) {
                    $hottest = ['label' => $dataset['label'], 'value' => $current];
                }
            }
            if ($hottest) {
                $cards[] = [
                    'title' => $this->translator->trans('metric.hottest_sensor'),
                    'value' => $hottest['value'] . '°C',
                    'subtitle' => $hottest['label'],
                ];
            }
        }
        if (!empty($diskCharts)) {
            $topDisk = $diskCharts[0];
            foreach ($diskCharts as $disk) {
                if ($disk['percent'] > $topDisk['percent']) {
                    $topDisk = $disk;
                }
            }
            $cards[] = [
                'title' => $this->translator->trans('metric.busiest_disk'),
                'value' => $topDisk['percent'] . '%',
                'subtitle' => $topDisk['title'],
            ];
        }
        if (!empty($networkCharts)) {
            $topNetwork = null;
            foreach ($networkCharts as $chart) {
                $peak = 0.0;
                foreach ($chart['datasets'] as $dataset) {
                    foreach ($dataset['values'] as $value) {
                        $peak = max($peak, (float) $value);
                    }
                }
                if ($topNetwork === null || $peak > $topNetwork['value']) {
                    $topNetwork = ['label' => $chart['title'], 'value' => $peak, 'unit' => $chart['unit'] ?? ''];
                }
            }
            if ($topNetwork !== null) {
                $cards[] = [
                    'title' => $this->translator->trans('metric.busiest_interface'),
                    'value' => (new MetricValueFormatter())->format($topNetwork['value'], $topNetwork['unit']),
                    'subtitle' => $topNetwork['label'],
                ];
            }
        }
        return $cards;
    }

    /** @param GroupedMetrics $groupedMetrics @param CurrentMetrics $currentMetrics @return array{totalGb: float, usedGb: float, freeGb: float}|null */
    private function buildRamDetails(array $groupedMetrics, array $currentMetrics = []): ?array
    {
        $latestRam = $currentMetrics['ram_used'] ?? $this->latestPoint($groupedMetrics['ram_used'] ?? []);
        $latestTotal = $currentMetrics['ram_total_gb'] ?? $this->latestPoint($groupedMetrics['ram_total_gb'] ?? []);
        if (!isset($latestRam['value'], $latestTotal['value'])) {
            return null;
        }
        $percentUsed = (float) $latestRam['value'];
        $totalGb = (float) $latestTotal['value'];
        if ($totalGb <= 0) {
            return null;
        }
        $usedGb = round(($percentUsed / 100) * $totalGb, 1);
        $freeGb = round($totalGb - $usedGb, 1);
        return [
            'totalGb' => round($totalGb, 1),
            'usedGb' => $usedGb,
            'freeGb' => $freeGb,
        ];
    }

    /** @param list<string>|null $displayMetrics */
    private function isMetricSelected(string $metricName, ?array $displayMetrics): bool
    {
        return is_array($displayMetrics) && in_array($metricName, $displayMetrics, true);
    }

    /** @param list<MetricPoint> $points @return list<string> */
    private function extractLabels(array $points): array
    {
        $labels = [];
        foreach ($points as $point) {
            $labels[] = $this->formatPointTime($point, 'd.m H:i');
        }
        return $labels;
    }

    /** @param list<MetricPoint> $points @return list<float> */
    private function extractValues(array $points): array
    {
        $values = [];
        foreach ($points as $point) {
            $values[] = round((float) ($point['value'] ?? 0), 2);
        }
        return $values;
    }

    /** @param list<MetricPoint> $points @return list<mixed> */
    private function extractTimestamps(array $points): array
    {
        $timestamps = [];
        foreach ($points as $point) {
            $timestamps[] = $point['time_bucket'] ?? $point['sample_time'] ?? $point['created_at'] ?? null;
        }
        return $timestamps;
    }

    /** @param MetricPoint $point */
    private function formatPointTime(array $point, string $format = 'd.m.Y H:i:s'): string
    {
        $raw = $point['time_bucket'] ?? $point['sample_time'] ?? $point['created_at'] ?? null;
        if (!$raw) {
            return '';
        }
        return (new DateTimeImmutable((string) $raw))->format($format);
    }

    /** @param list<MetricPoint> $points @return MetricPoint */
    private function latestPoint(array $points): array
    {
        return $points === [] ? [] : $points[array_key_last($points)];
    }

    private function formatMetricLabel(string $metricName): string
    {
        if ($metricName === 'cpu_load') {
            return $this->translator->trans('metric.cpu_load');
        }
        if ($metricName === 'ram_used') {
            return $this->translator->trans('metric.ram_used');
        }
        if ($metricName === 'temp_system') {
            return $this->translator->trans('metric.temp_system');
        }
        if (str_starts_with($metricName, 'temp_')) {
            return $this->translator->trans('metric.temperature', [
                'name' => str_replace('_', ' ', substr($metricName, strlen('temp_'))),
            ]);
        }
        return str_replace('_', ' ', $metricName);
    }

    private function formatDiskTitle(string $suffix): string
    {
        return match ($suffix) {
            'root' => $this->translator->trans('metric.root'),
            'home' => '/home',
            'boot' => '/boot',
            'mnt_data' => '/mnt/data',
            default => '/' . str_replace('_', '/', $suffix),
        };
    }

    private function formatUptime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $seconds = (int) round((float) $value);
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
            $parts[] = $days . ' ' . $this->translator->trans('common.days');
        }
        if ($hours > 0 || $days > 0) {
            $parts[] = $hours . ' ' . $this->translator->trans('common.hours');
        }
        $parts[] = $minutes . ' ' . $this->translator->trans('common.minutes');
        return implode(' ', $parts);
    }

    /** @param array<string, string> $args */
    public function startMaintenance(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $minutes = filter_var(
            $body['duration_minutes'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 10080]]
        );
        try {
            if ($minutes === false) {
                throw new InvalidArgumentException($this->translator->trans('maintenance.validation.duration'));
            }
            $this->maintenance->start(
                $serverId,
                $minutes * 60,
                is_string($body['reason'] ?? null) ? $body['reason'] : null,
                is_string($_SESSION['username'] ?? null) ? $_SESSION['username'] : null
            );
            $_SESSION['flash_message'] = $this->translator->trans(
                'maintenance.flash.enabled',
                ['minutes' => $minutes]
            );
            $_SESSION['flash_type'] = 'success';
        } catch (InvalidArgumentException $exception) {
            $_SESSION['flash_message'] = $exception->getMessage();
            $_SESSION['flash_type'] = 'danger';
        } catch (Throwable) {
            $_SESSION['flash_message'] = $this->translator->trans('maintenance.flash.enable_failed');
            $_SESSION['flash_type'] = 'danger';
        }
        return $response->withHeader('Location', '/servers/' . $serverId)->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function cancelMaintenance(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }
        try {
            $closed = $this->maintenance->cancel($serverId);
            $_SESSION['flash_message'] = $this->translator->trans(
                $closed > 0 ? 'maintenance.flash.ended' : 'maintenance.flash.none'
            );
            $_SESSION['flash_type'] = $closed > 0 ? 'success' : 'warning';
        } catch (Throwable) {
            $_SESSION['flash_message'] = $this->translator->trans('maintenance.flash.end_failed');
            $_SESSION['flash_type'] = 'danger';
        }
        return $response->withHeader('Location', '/servers/' . $serverId)->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function saveThresholds(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }
        $params = $request->getParsedBody();
        $params = is_array($params) ? $params : [];
        $defaults = $this->servers->thresholdDefaults();
        $thresholds = [];
        $saved = [];
        $usedDefaults = [];
        foreach ($this->metrics->metricTypes($serverId) as $metricType) {
            $warning = $params[$metricType['name'] . '_warning'] ?? '';
            $critical = $params[$metricType['name'] . '_critical'] ?? '';
            $duration = $params[$metricType['name'] . '_duration'] ?? '';
            $recovery = $params[$metricType['name'] . '_recovery'] ?? '';
            if ($warning === '' && $critical === '') {
                continue;
            }
            $warningValue = $warning === '' ? $defaults['warning'] : $this->finiteFloat($warning);
            $criticalValue = $critical === '' ? $defaults['critical'] : $this->finiteFloat($critical);
            $durationValue = $duration === '' ? $defaults['duration'] : filter_var(
                $duration,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 86400]]
            );
            $recoveryValue = $recovery === '' ? $defaults['recovery'] : filter_var(
                $recovery,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 86400]]
            );
            if (
                $warningValue === null
                || $criticalValue === null
                || $durationValue === false
                || $recoveryValue === false
                || $criticalValue < $warningValue
            ) {
                $_SESSION['flash_message'] = $this->translator->trans('threshold.validation');
                $_SESSION['flash_type'] = 'danger';
                return $response->withHeader('Location', "/servers/{$serverId}?tab=thresholds")->withStatus(302);
            }
            $thresholds[] = [
                'metric_id' => $metricType['id'],
                'warning' => $warningValue,
                'critical' => $criticalValue,
                'duration' => (int) $durationValue,
                'recovery' => (int) $recoveryValue,
            ];
            $used = [];
            if ($warning === '') {
                $used[] = 'warning=' . $defaults['warning'];
            }
            if ($critical === '') {
                $used[] = 'critical=' . $defaults['critical'];
            }
            if ($duration === '') {
                $used[] = 'duration=' . $defaults['duration'];
            }
            if ($recovery === '') {
                $used[] = 'recovery=' . $defaults['recovery'];
            }
            if ($used !== []) {
                $usedDefaults[] = $metricType['name'] . ' (' . implode(', ', $used) . ')';
            } else {
                $saved[] = $metricType['name'];
            }
        }
        $this->servers->replaceThresholds($serverId, $thresholds);
        $messages = [];
        if ($saved !== []) {
            $messages[] = $this->translator->trans('threshold.saved', ['items' => implode(', ', $saved)]);
        }
        if ($usedDefaults !== []) {
            $messages[] = $this->translator->trans(
                'threshold.defaults_used',
                ['items' => implode(', ', $usedDefaults)]
            );
        }
        if ($messages === []) {
            $messages[] = $this->translator->trans('threshold.all_removed');
        }
        $_SESSION['flash_message'] = implode('. ', $messages);
        $_SESSION['flash_type'] = $usedDefaults !== [] ? 'warning' : 'success';
        return $response->withHeader('Location', "/servers/{$serverId}?tab=thresholds")->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function saveServices(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }
        $params = $request->getParsedBody();
        $params = is_array($params) ? $params : [];
        $services = $params['services'] ?? [];
        if (is_string($services)) {
            try {
                $services = json_decode($services, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $services = [];
            }
        }
        $services = is_array($services) ? $services : [];
        $services = array_slice(array_values(array_unique(array_filter(
            $services,
            static fn (mixed $service): bool => is_string($service)
                && $service !== ''
                && strlen($service) <= 255
        ))), 0, 100);
        $this->servers->saveMonitoredServices($serverId, $services);
        return $response->withHeader('Location', "/servers/{$serverId}?tab=services")->withStatus(302);
    }

    private function finiteFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }
}
