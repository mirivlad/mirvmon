<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class DashboardMetricService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<string> $savedMetrics
     * @return array{groups: list<array{name: string, options: list<array{id: string, label: string, unit: string}>}>, selected: list<string>}
     */
    public function displayOptions(int $serverId, array $savedMetrics): array
    {
        $metrics = $this->recentMetrics($serverId);
        $names = array_fill_keys(array_column($metrics, 'name'), true);
        $units = [];
        foreach ($metrics as $metric) {
            $units[$metric['name']] = $metric['unit'] ?? '';
        }

        $system = [];
        foreach ([
            'cpu_load' => 'Загрузка CPU',
            'ram_used' => 'Использование RAM',
            'uptime' => 'Аптайм ОС',
        ] as $name => $label) {
            if (isset($names[$name])) {
                $system[] = $this->option($name, $label, (string) ($units[$name] ?? ''));
            }
        }
        // Availability is a MirvMon-side state rather than an agent metric.
        $system[] = $this->option('availability', 'Доступность (онлайн/оффлайн)', '');

        $disks = [];
        $ioDevices = [];
        $networks = [];
        $hasTemperature = false;
        foreach (array_keys($names) as $name) {
            if (str_starts_with($name, 'disk_used_') && $name !== 'disk_used') {
                $suffix = substr($name, strlen('disk_used_'));
                $disks[$name] = $this->option(
                    $name,
                    'Использование: ' . $this->diskLabel($suffix),
                    '%'
                );
                continue;
            }
            if (str_starts_with($name, 'disk_read_')) {
                $ioDevices[substr($name, strlen('disk_read_'))] = true;
                continue;
            }
            if (str_starts_with($name, 'disk_write_')) {
                $ioDevices[substr($name, strlen('disk_write_'))] = true;
                continue;
            }
            if (str_starts_with($name, 'net_in_')) {
                $networks[substr($name, strlen('net_in_'))] = true;
                continue;
            }
            if (str_starts_with($name, 'net_out_')) {
                $networks[substr($name, strlen('net_out_'))] = true;
                continue;
            }
            if (str_starts_with($name, 'temp_')) {
                $hasTemperature = true;
            }
        }

        ksort($disks, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($ioDevices, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($networks, SORT_NATURAL | SORT_FLAG_CASE);

        $io = [];
        foreach (array_keys($ioDevices) as $device) {
            $io[] = $this->option('disk_io_' . $device, 'I/O: ' . $device, 'B/s');
        }
        $network = [];
        foreach (array_keys($networks) as $iface) {
            $network[] = $this->option('net_' . $iface, 'Сеть: ' . $iface, 'B/s');
        }
        $temperatures = $hasTemperature
            ? [$this->option('temperatures', 'Температуры', '°C')]
            : [];

        $groups = [];
        foreach ([
            'Система' => $system,
            'Файловые системы' => array_values($disks),
            'Дисковый I/O' => $io,
            'Сеть' => $network,
            'Температуры' => $temperatures,
        ] as $name => $options) {
            if ($options !== []) {
                $groups[] = ['name' => $name, 'options' => $options];
            }
        }

        $selected = $this->widgetsFromMetrics($savedMetrics, array_keys($names));

        return ['groups' => $groups, 'selected' => $selected];
    }

    /**
     * @param list<string> $widgets
     * @return list<string>
     */
    public function expandWidgets(int $serverId, array $widgets): array
    {
        $metrics = $this->recentMetrics($serverId);
        $available = array_fill_keys(array_column($metrics, 'name'), true);
        $options = $this->displayOptions($serverId, []);
        $allowed = [];
        foreach ($options['groups'] as $group) {
            foreach ($group['options'] as $option) {
                $allowed[$option['id']] = true;
            }
        }

        $expanded = [];
        foreach (array_values(array_unique($widgets)) as $widget) {
            if (!is_string($widget) || !isset($allowed[$widget])) {
                continue;
            }
            if (in_array($widget, ['cpu_load', 'ram_used', 'uptime', 'availability'], true)) {
                $expanded[$widget] = true;
                continue;
            }
            if ($widget === 'temperatures') {
                foreach (array_keys($available) as $name) {
                    if (str_starts_with($name, 'temp_')) {
                        $expanded[$name] = true;
                    }
                }
                continue;
            }
            if (str_starts_with($widget, 'net_')) {
                $suffix = substr($widget, strlen('net_'));
                foreach (['net_in_' . $suffix, 'net_out_' . $suffix] as $name) {
                    if (isset($available[$name])) {
                        $expanded[$name] = true;
                    }
                }
                continue;
            }
            if (str_starts_with($widget, 'disk_io_')) {
                $suffix = substr($widget, strlen('disk_io_'));
                foreach (['disk_read_' . $suffix, 'disk_write_' . $suffix] as $name) {
                    if (isset($available[$name])) {
                        $expanded[$name] = true;
                    }
                }
                continue;
            }
            if (str_starts_with($widget, 'disk_used_') && isset($available[$widget])) {
                $expanded[$widget] = true;
            }
        }

        return array_keys($expanded);
    }

    /**
     * @param list<string> $savedMetrics
     * @param list<string> $recentNames
     * @return list<string>
     */
    private function widgetsFromMetrics(array $savedMetrics, array $recentNames): array
    {
        $recent = array_fill_keys($recentNames, true);
        $selected = [];
        foreach ($savedMetrics as $metric) {
            if (!is_string($metric)) {
                continue;
            }
            if ($metric === 'availability') {
                $selected['availability'] = true;
            } elseif (in_array($metric, ['cpu_load', 'ram_used', 'uptime'], true) && isset($recent[$metric])) {
                $selected[$metric] = true;
            } elseif ($metric === 'temperature' || str_starts_with($metric, 'temp_')) {
                $selected['temperatures'] = true;
            } elseif (str_starts_with($metric, 'disk_used_') && isset($recent[$metric])) {
                $selected[$metric] = true;
            } elseif (str_starts_with($metric, 'net_in_')) {
                $selected['net_' . substr($metric, strlen('net_in_'))] = true;
            } elseif (str_starts_with($metric, 'net_out_')) {
                $selected['net_' . substr($metric, strlen('net_out_'))] = true;
            } elseif (str_starts_with($metric, 'disk_read_')) {
                $selected['disk_io_' . substr($metric, strlen('disk_read_'))] = true;
            } elseif (str_starts_with($metric, 'disk_write_')) {
                $selected['disk_io_' . substr($metric, strlen('disk_write_'))] = true;
            }
        }

        return array_keys($selected);
    }

    /** @return list<array{name: string, unit: ?string}> */
    private function recentMetrics(int $serverId): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            WITH recent_samples AS (
                SELECT sample_id
                FROM ingested_samples
                WHERE server_id = :server_id
                ORDER BY received_at DESC
                LIMIT 3
            )
            SELECT DISTINCT names.name, names.unit
            FROM metric_samples AS samples
            INNER JOIN recent_samples ON recent_samples.sample_id = samples.sample_id
            INNER JOIN metric_names AS names ON names.id = samples.metric_id
            WHERE samples.server_id = :server_id_metrics
            ORDER BY names.name
            SQL
        );
        $statement->execute([
            'server_id' => $serverId,
            'server_id_metrics' => $serverId,
        ]);

        return array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'unit' => $row['unit'] === null ? null : (string) $row['unit'],
            ],
            $statement->fetchAll()
        );
    }

    /** @return array{id: string, label: string, unit: string} */
    private function option(string $id, string $label, string $unit): array
    {
        return ['id' => $id, 'label' => $label, 'unit' => $unit];
    }

    private function diskLabel(string $suffix): string
    {
        return match ($suffix) {
            'root' => '/',
            'home' => '/home',
            'boot' => '/boot',
            'mnt_data' => '/mnt/data',
            default => '/' . str_replace('_', '/', $suffix),
        };
    }
}
