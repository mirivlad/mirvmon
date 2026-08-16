<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Metrics\MetricValueFormatter;
use App\I18n\Translator;
use DateTimeImmutable;

/**
 * Builds the server-detail metric read model. Keeping this presentation logic
 * outside the controller makes the operational page and settings page easier to
 * evolve independently.
 *
 * @phpstan-type MetricPoint array<string, mixed>
 * @phpstan-type GroupedMetrics array<string, list<MetricPoint>>
 * @phpstan-type CurrentMetrics array<string, MetricPoint>
 * @phpstan-type Chart array<string, mixed>
 */
final class ServerMetricViewBuilder
{
    public function __construct(private readonly Translator $translator)
    {
    }

    /**
     * @param list<array<string, mixed>> $points
     * @return GroupedMetrics
     */
    public function group(array $points): array
    {
        $grouped = [];
        foreach ($points as $point) {
            if (!is_string($point['name'] ?? null)) {
                continue;
            }
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

    /**
     * @param GroupedMetrics $grouped
     * @param list<string>|null $displayMetrics
     * @param array<string, mixed> $thresholds
     * @param CurrentMetrics $current
     * @return array{
     *     simple: list<Chart>,
     *     network: list<Chart>,
     *     disk_io: list<Chart>,
     *     temperature: Chart,
     *     disks: list<Chart>,
     *     uptime: Chart,
     *     summary: list<Chart>
     * }
     */
    public function build(
        array $grouped,
        ?array $displayMetrics,
        array $thresholds,
        array $current
    ): array {
        $simple = $this->simpleCharts($grouped, $displayMetrics, $thresholds, $current);
        $network = $this->networkCharts($grouped, $displayMetrics);
        $diskIo = $this->diskIoCharts($grouped, $displayMetrics);
        $temperature = $this->temperatureChart($grouped, $displayMetrics);
        $disks = $this->diskCharts($grouped, $displayMetrics, $current);
        $uptime = $this->uptimeChart($grouped, $displayMetrics);

        return [
            'simple' => $simple,
            'network' => $network,
            'disk_io' => $diskIo,
            'temperature' => $temperature,
            'disks' => $disks,
            'uptime' => $uptime,
            'summary' => $this->summaryCards(
                $grouped,
                $displayMetrics,
                $temperature,
                $disks,
                $network,
                $current
            ),
        ];
    }

    /**
     * @param array<string, mixed> $availability
     * @return Chart
     */
    public function availabilityChart(array $availability): array
    {
        if (($availability['known'] ?? false) !== true) {
            return ['known' => false, 'labels' => [], 'values' => []];
        }

        $labels = [];
        $values = [];
        foreach ($availability['points'] ?? [] as $point) {
            if (!is_array($point) || !isset($point['time'])) {
                continue;
            }
            $labels[] = (new DateTimeImmutable((string) $point['time']))->format('d.m H:i');
            $values[] = (int) ($point['value'] ?? 0);
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

    public function formatUptime(mixed $value): ?string
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

    /**
     * @param GroupedMetrics $grouped
     * @param list<string>|null $displayMetrics
     * @param array<string, mixed> $thresholds
     * @param CurrentMetrics $current
     * @return list<Chart>
     */
    private function simpleCharts(
        array $grouped,
        ?array $displayMetrics,
        array $thresholds,
        array $current
    ): array {
        $charts = [];
        $config = [
            'cpu_load' => [
                'title' => $this->translator->trans('metric.cpu_load'),
                'color' => '#0d6efd',
            ],
            'ram_used' => [
                'title' => $this->translator->trans('metric.ram_used'),
                'color' => '#198754',
            ],
        ];
        foreach ($config as $metricName => $meta) {
            if (!$this->selected($metricName, $displayMetrics) || empty($grouped[$metricName])) {
                continue;
            }
            $latest = $current[$metricName] ?? $this->latest($grouped[$metricName]);
            $charts[] = [
                'id' => $metricName,
                'title' => $meta['title'],
                'unit' => $latest['unit'] ?? '',
                'color' => $meta['color'],
                'labels' => $this->labels($grouped[$metricName]),
                'timestamps' => $this->timestamps($grouped[$metricName]),
                'values' => $this->values($grouped[$metricName]),
                'lastValue' => round((float) ($latest['value'] ?? 0), 2),
                'lastTime' => $this->pointTime($latest),
                'thresholds' => $thresholds[$metricName] ?? null,
                'details' => $metricName === 'ram_used'
                    ? $this->ramDetails($grouped, $current)
                    : null,
            ];
        }
        usort($charts, fn (array $a, array $b): int => strnatcasecmp((string) $a['title'], (string) $b['title']));
        return $charts;
    }

    /** @param GroupedMetrics $grouped @param list<string>|null $displayMetrics @return list<Chart> */
    private function networkCharts(array $grouped, ?array $displayMetrics): array
    {
        $interfaces = [];
        foreach (array_keys($grouped) as $name) {
            if (str_starts_with($name, 'net_in_')) {
                $interfaces[substr($name, 7)] = true;
            } elseif (str_starts_with($name, 'net_out_')) {
                $interfaces[substr($name, 8)] = true;
            }
        }

        $charts = [];
        foreach (array_keys($interfaces) as $iface) {
            $inName = 'net_in_' . $iface;
            $outName = 'net_out_' . $iface;
            $showIn = $this->selected($inName, $displayMetrics) && !empty($grouped[$inName]);
            $showOut = $this->selected($outName, $displayMetrics) && !empty($grouped[$outName]);
            if (!$showIn && !$showOut) {
                continue;
            }
            $base = $showIn ? $grouped[$inName] : $grouped[$outName];
            $datasets = [];
            if ($showIn) {
                $datasets[] = [
                    'label' => $this->translator->trans('metric.network_in'),
                    'color' => '#198754',
                    'values' => $this->values($grouped[$inName]),
                ];
            }
            if ($showOut) {
                $datasets[] = [
                    'label' => $this->translator->trans('metric.network_out'),
                    'color' => '#dc3545',
                    'values' => $this->values($grouped[$outName]),
                ];
            }
            $charts[] = [
                'id' => $iface,
                'title' => $this->translator->trans('metric.network', ['name' => $iface]),
                'unit' => $base[0]['unit'] ?? '',
                'labels' => $this->labels($base),
                'timestamps' => $this->timestamps($base),
                'datasets' => $datasets,
            ];
        }
        usort($charts, fn (array $a, array $b): int => strnatcasecmp((string) $a['id'], (string) $b['id']));
        return $charts;
    }

    /** @param GroupedMetrics $grouped @param list<string>|null $displayMetrics @return list<Chart> */
    private function diskIoCharts(array $grouped, ?array $displayMetrics): array
    {
        $devices = [];
        foreach (array_keys($grouped) as $name) {
            if (str_starts_with($name, 'disk_read_')) {
                $devices[substr($name, 10)] = true;
            } elseif (str_starts_with($name, 'disk_write_')) {
                $devices[substr($name, 11)] = true;
            }
        }

        $charts = [];
        foreach (array_keys($devices) as $device) {
            $readName = 'disk_read_' . $device;
            $writeName = 'disk_write_' . $device;
            $showRead = $this->selected($readName, $displayMetrics) && !empty($grouped[$readName]);
            $showWrite = $this->selected($writeName, $displayMetrics) && !empty($grouped[$writeName]);
            if (!$showRead && !$showWrite) {
                continue;
            }
            $base = $showRead ? $grouped[$readName] : $grouped[$writeName];
            $datasets = [];
            if ($showRead) {
                $datasets[] = [
                    'label' => $this->translator->trans('metric.disk_read'),
                    'color' => '#0d6efd',
                    'values' => $this->values($grouped[$readName]),
                ];
            }
            if ($showWrite) {
                $datasets[] = [
                    'label' => $this->translator->trans('metric.disk_write'),
                    'color' => '#fd7e14',
                    'values' => $this->values($grouped[$writeName]),
                ];
            }
            $charts[] = [
                'id' => $device,
                'title' => $this->translator->trans('metric.disk_io', ['name' => $device]),
                'unit' => $base[0]['unit'] ?? 'B/s',
                'labels' => $this->labels($base),
                'timestamps' => $this->timestamps($base),
                'datasets' => $datasets,
            ];
        }
        usort($charts, fn (array $a, array $b): int => strnatcasecmp((string) $a['id'], (string) $b['id']));
        return $charts;
    }

    /** @param GroupedMetrics $grouped @param list<string>|null $displayMetrics @return Chart */
    private function temperatureChart(array $grouped, ?array $displayMetrics): array
    {
        $labels = [];
        $timestamps = [];
        $series = [];
        foreach ($grouped as $name => $points) {
            if (!str_starts_with($name, 'temp_') || !$this->selected($name, $displayMetrics) || $points === []) {
                continue;
            }
            if ($labels === []) {
                $labels = $this->labels($points);
                $timestamps = $this->timestamps($points);
            }
            $series[] = [
                'label' => $this->metricLabel($name),
                'metricName' => $name,
                'values' => $this->values($points),
            ];
        }
        usort($series, fn (array $a, array $b): int => strnatcasecmp((string) $a['label'], (string) $b['label']));
        $colors = ['#dc3545', '#fd7e14', '#0dcaf0', '#6f42c1', '#20c997', '#ffc107', '#6610f2', '#198754'];
        $datasets = [];
        foreach ($series as $index => $item) {
            $datasets[] = [
                ...$item,
                'color' => $colors[$index % count($colors)],
            ];
        }
        return [
            'unit' => '°C',
            'labels' => $labels,
            'timestamps' => $timestamps,
            'datasets' => $datasets,
        ];
    }

    /** @param GroupedMetrics $grouped @param list<string>|null $displayMetrics @param CurrentMetrics $current @return list<Chart> */
    private function diskCharts(array $grouped, ?array $displayMetrics, array $current): array
    {
        $charts = [];
        foreach ($grouped as $name => $points) {
            if (
                !str_starts_with($name, 'disk_used_')
                || $name === 'disk_used'
                || !$this->selected($name, $displayMetrics)
                || $points === []
            ) {
                continue;
            }
            $suffix = substr($name, 10);
            $totalName = 'disk_total_gb_' . $suffix;
            $latest = $current[$name] ?? $this->latest($points);
            $latestTotal = $current[$totalName] ?? $this->latest($grouped[$totalName] ?? []);
            $percent = (float) ($latest['value'] ?? 0);
            $totalGb = (float) ($latestTotal['value'] ?? 0);
            $usedGb = $totalGb > 0 ? round(($percent / 100) * $totalGb, 1) : null;
            $freeGb = $totalGb > 0 ? round($totalGb - (float) $usedGb, 1) : null;
            $charts[] = [
                'id' => $suffix,
                'title' => $this->diskTitle($suffix),
                'percent' => round($percent, 1),
                'totalGb' => $totalGb > 0 ? round($totalGb, 1) : null,
                'usedGb' => $usedGb,
                'freeGb' => $freeGb,
                'updatedAt' => $this->pointTime($latest),
            ];
        }
        usort($charts, fn (array $a, array $b): int => strnatcasecmp((string) $a['title'], (string) $b['title']));
        return $charts;
    }

    /** @param GroupedMetrics $grouped @param list<string>|null $displayMetrics @return Chart */
    private function uptimeChart(array $grouped, ?array $displayMetrics): array
    {
        if (!$this->selected('uptime', $displayMetrics) || empty($grouped['uptime'])) {
            return [];
        }
        $points = $grouped['uptime'];
        $latest = $this->latest($points);
        return [
            'title' => $this->translator->trans('metric.uptime_os'),
            'unit' => 'uptime',
            'labels' => $this->labels($points),
            'timestamps' => $this->timestamps($points),
            'values' => $this->values($points),
            'lastText' => $this->formatUptime($latest['value'] ?? null),
            'lastTime' => $this->pointTime($latest),
        ];
    }

    /**
     * @param GroupedMetrics $grouped
     * @param list<string>|null $displayMetrics
     * @param Chart $temperature
     * @param list<Chart> $disks
     * @param list<Chart> $network
     * @param CurrentMetrics $current
     * @return list<Chart>
     */
    private function summaryCards(
        array $grouped,
        ?array $displayMetrics,
        array $temperature,
        array $disks,
        array $network,
        array $current
    ): array {
        $cards = [];
        $cpu = $current['cpu_load'] ?? $this->latest($grouped['cpu_load'] ?? []);
        if ($this->selected('cpu_load', $displayMetrics) && isset($cpu['value'])) {
            $cards[] = [
                'title' => $this->translator->trans('metric.cpu_now'),
                'value' => round((float) $cpu['value'], 2) . '%',
                'subtitle' => $this->pointTime($cpu),
            ];
        }
        $ram = $current['ram_used'] ?? $this->latest($grouped['ram_used'] ?? []);
        if ($this->selected('ram_used', $displayMetrics) && isset($ram['value'])) {
            $details = $this->ramDetails($grouped, $current);
            $cards[] = [
                'title' => $this->translator->trans('metric.ram_now'),
                'value' => round((float) $ram['value'], 2) . '%',
                'subtitle' => $details
                    ? $this->translator->trans('metric.total_used_free', [
                        'total' => $details['totalGb'],
                        'used' => $details['usedGb'],
                        'free' => $details['freeGb'],
                    ])
                    : $this->pointTime($ram),
            ];
        }
        if (!empty($temperature['datasets'])) {
            $hottest = null;
            foreach ($temperature['datasets'] as $dataset) {
                $values = is_array($dataset['values'] ?? null) ? $dataset['values'] : [];
                $last = $values === [] ? null : $values[array_key_last($values)];
                $value = $current[$dataset['metricName']]['value'] ?? $last;
                if ($value === null) {
                    continue;
                }
                if ($hottest === null || (float) $value > (float) $hottest['value']) {
                    $hottest = ['label' => $dataset['label'], 'value' => (float) $value];
                }
            }
            if ($hottest !== null) {
                $cards[] = [
                    'title' => $this->translator->trans('metric.hottest_sensor'),
                    'value' => $hottest['value'] . '°C',
                    'subtitle' => $hottest['label'],
                ];
            }
        }
        if ($disks !== []) {
            $top = $disks[0];
            foreach ($disks as $disk) {
                if ((float) $disk['percent'] > (float) $top['percent']) {
                    $top = $disk;
                }
            }
            $cards[] = [
                'title' => $this->translator->trans('metric.busiest_disk'),
                'value' => $top['percent'] . '%',
                'subtitle' => $top['title'],
            ];
        }
        if ($network !== []) {
            $top = null;
            foreach ($network as $chart) {
                $peak = 0.0;
                foreach ($chart['datasets'] as $dataset) {
                    foreach ($dataset['values'] as $value) {
                        $peak = max($peak, (float) $value);
                    }
                }
                if ($top === null || $peak > $top['value']) {
                    $top = [
                        'label' => $chart['title'],
                        'value' => $peak,
                        'unit' => $chart['unit'] ?? '',
                    ];
                }
            }
            if ($top !== null) {
                $cards[] = [
                    'title' => $this->translator->trans('metric.busiest_interface'),
                    'value' => (new MetricValueFormatter())->format($top['value'], $top['unit']),
                    'subtitle' => $top['label'],
                ];
            }
        }
        return $cards;
    }

    /** @param GroupedMetrics $grouped @param CurrentMetrics $current @return array{totalGb: float, usedGb: float, freeGb: float}|null */
    private function ramDetails(array $grouped, array $current): ?array
    {
        $ram = $current['ram_used'] ?? $this->latest($grouped['ram_used'] ?? []);
        $total = $current['ram_total_gb'] ?? $this->latest($grouped['ram_total_gb'] ?? []);
        if (!isset($ram['value'], $total['value']) || (float) $total['value'] <= 0) {
            return null;
        }
        $totalGb = (float) $total['value'];
        $usedGb = round(((float) $ram['value'] / 100) * $totalGb, 1);
        return [
            'totalGb' => round($totalGb, 1),
            'usedGb' => $usedGb,
            'freeGb' => round($totalGb - $usedGb, 1),
        ];
    }

    /** @param list<string>|null $displayMetrics */
    private function selected(string $name, ?array $displayMetrics): bool
    {
        return is_array($displayMetrics) && in_array($name, $displayMetrics, true);
    }

    /** @param list<MetricPoint> $points @return list<string> */
    private function labels(array $points): array
    {
        return array_map(fn (array $point): string => $this->pointTime($point, 'd.m H:i'), $points);
    }

    /** @param list<MetricPoint> $points @return list<float> */
    private function values(array $points): array
    {
        return array_map(static fn (array $point): float => round((float) ($point['value'] ?? 0), 2), $points);
    }

    /** @param list<MetricPoint> $points @return list<mixed> */
    private function timestamps(array $points): array
    {
        return array_map(
            static fn (array $point): mixed => $point['time_bucket'] ?? $point['sample_time'] ?? $point['created_at'] ?? null,
            $points
        );
    }

    /** @param MetricPoint $point */
    private function pointTime(array $point, string $format = 'd.m.Y H:i:s'): string
    {
        $value = $point['time_bucket'] ?? $point['sample_time'] ?? $point['created_at'] ?? null;
        return $value ? (new DateTimeImmutable((string) $value))->format($format) : '';
    }

    /** @param list<MetricPoint> $points @return MetricPoint */
    private function latest(array $points): array
    {
        return $points === [] ? [] : $points[array_key_last($points)];
    }

    private function metricLabel(string $name): string
    {
        if ($name === 'cpu_load') {
            return $this->translator->trans('metric.cpu_load');
        }
        if ($name === 'ram_used') {
            return $this->translator->trans('metric.ram_used');
        }
        if ($name === 'temp_system') {
            return $this->translator->trans('metric.temp_system');
        }
        if (str_starts_with($name, 'temp_')) {
            return $this->translator->trans('metric.temperature', [
                'name' => str_replace('_', ' ', substr($name, 5)),
            ]);
        }
        return str_replace('_', ' ', $name);
    }

    private function diskTitle(string $suffix): string
    {
        return match ($suffix) {
            'root' => $this->translator->trans('metric.root'),
            'home' => '/home',
            'boot' => '/boot',
            'mnt_data' => '/mnt/data',
            default => '/' . str_replace('_', '/', $suffix),
        };
    }
}
