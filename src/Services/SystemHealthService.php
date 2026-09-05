<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppSettingsRepository;
use App\Repositories\ConnectivityStateRepository;
use App\Repositories\MetricRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\ServerRepository;
use App\Repositories\WorkerHeartbeatRepository;
use PDO;
use RuntimeException;
use Throwable;

final class SystemHealthService
{
    public const HOST_SETTING = 'mirvmon_host_server_id';

    public function __construct(
        private readonly PDO $pdo,
        private readonly AppSettingsRepository $settings,
        private readonly ServerRepository $servers,
        private readonly MetricRepository $metrics,
        private readonly WorkerHeartbeatRepository $heartbeats,
        private readonly NotificationOutboxRepository $outbox,
        private readonly ServerStatusService $serverStatus,
        private readonly string $appVersion = 'development',
        private readonly string $appEnvironment = 'production'
    ) {
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        $database = $this->databaseDiagnostics();
        $workers = $this->workerDiagnostics();
        $queue = $this->queueDiagnostics();
        $connectivity = $this->connectivityDiagnostics();
        $host = $this->hostDiagnostics();

        return [
            'application' => [
                'status' => $this->worst([
                    (string) $database['status'],
                    (string) $workers['status'],
                    (string) $queue['status'],
                    (string) $connectivity['status'],
                ]),
                'version' => $this->appVersion,
                'environment' => $this->appEnvironment,
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'runtime_hostname' => gethostname() ?: null,
                'timezone' => date_default_timezone_get(),
            ],
            'database' => $database,
            'workers' => $workers,
            'queue' => $queue,
            'connectivity' => $connectivity,
            'host' => $host,
        ];
    }

    /** @return array{application_status:string,host_status:string,host_configured:bool} */
    public function summary(): array
    {
        $database = $this->databaseDiagnostics();
        $workers = $this->workerDiagnostics();
        $queue = $this->queueDiagnostics();
        $connectivity = $this->connectivityDiagnostics();
        $host = $this->hostDiagnostics(false);

        return [
            'application_status' => $this->worst([
                (string) $database['status'],
                (string) $workers['status'],
                (string) $queue['status'],
                (string) $connectivity['status'],
            ]),
            'host_status' => (string) $host['status'],
            'host_configured' => $host['configured'] === true,
        ];
    }

    public function selectedHostId(): ?int
    {
        try {
            $value = $this->settings->get(self::HOST_SETTING);
        } catch (Throwable) {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array{
     *     status:string,
     *     available:bool,
     *     database_name:?string,
     *     postgres_version:?string,
     *     timescale_version:?string,
     *     size_bytes:?int,
     *     size_pretty:?string,
     *     latency_ms:?float
     * }
     */
    private function databaseDiagnostics(): array
    {
        $started = hrtime(true);
        try {
            $row = $this->pdo->query(
                <<<'SQL'
                SELECT
                    current_database() AS database_name,
                    current_setting('server_version') AS postgres_version,
                    pg_database_size(current_database()) AS size_bytes,
                    pg_size_pretty(pg_database_size(current_database())) AS size_pretty
                SQL
            )?->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('Database diagnostics query returned no row.');
            }

            $timescale = $this->pdo->query(
                "SELECT extversion FROM pg_extension WHERE extname = 'timescaledb'"
            )?->fetchColumn();
            $timescaleVersion = is_string($timescale) && $timescale !== ''
                ? $timescale
                : null;

            return [
                'status' => $timescaleVersion === null ? 'critical' : 'ok',
                'available' => true,
                'database_name' => (string) $row['database_name'],
                'postgres_version' => (string) $row['postgres_version'],
                'timescale_version' => $timescaleVersion,
                'size_bytes' => (int) $row['size_bytes'],
                'size_pretty' => (string) $row['size_pretty'],
                'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
            ];
        } catch (Throwable) {
            return [
                'status' => 'critical',
                'available' => false,
                'database_name' => null,
                'postgres_version' => null,
                'timescale_version' => null,
                'size_bytes' => null,
                'size_pretty' => null,
                'latency_ms' => null,
            ];
        }
    }

    /** @return array{status:string,items:list<array<string,mixed>>} */
    private function workerDiagnostics(): array
    {
        try {
            $byName = [];
            foreach ($this->heartbeats->all() as $heartbeat) {
                $byName[$heartbeat['worker']] = $heartbeat;
            }
        } catch (Throwable) {
            return [
                'status' => 'critical',
                'items' => [],
            ];
        }

        $items = [];
        foreach (
            [
                WorkerHeartbeatRepository::NOTIFICATION_WORKER,
                WorkerHeartbeatRepository::OFFLINE_WORKER,
                WorkerHeartbeatRepository::CONNECTIVITY_WORKER,
                WorkerHeartbeatRepository::WEBSITE_CHECK_WORKER,
            ] as $worker
        ) {
            $heartbeat = $byName[$worker] ?? null;
            if ($heartbeat === null) {
                $items[] = [
                    'worker' => $worker,
                    'status' => 'critical',
                    'last_tick_at' => null,
                    'started_at' => null,
                    'seconds_since_tick' => null,
                ];
                continue;
            }
            $items[] = [
                'worker' => $worker,
                'status' => $heartbeat['stale'] ? 'critical' : 'ok',
                'last_tick_at' => $heartbeat['last_tick_at'],
                'started_at' => $heartbeat['started_at'],
                'seconds_since_tick' => $heartbeat['seconds_since_tick'],
            ];
        }

        return [
            'status' => $this->worst(array_map(
                static fn (array $item): string => (string) $item['status'],
                $items
            )),
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function connectivityDiagnostics(): array
    {
        try {
            $current = (new ConnectivityStateRepository($this->settings))->current();
        } catch (Throwable) {
            $current = null;
        }
        if ($current === null) {
            return [
                'status' => 'unknown',
                'state' => 'unknown',
                'checked_at' => null,
                'changed_at' => null,
                'successes' => 0,
                'failures' => 0,
                'successful_targets' => [],
                'failed_targets' => [],
            ];
        }

        try {
            $checkedAt = new \DateTimeImmutable($current['checked_at']);
            $age = max(0, time() - $checkedAt->getTimestamp());
        } catch (Throwable) {
            $age = PHP_INT_MAX;
        }
        $status = $current['state'] === 'offline' ? 'critical' : 'ok';
        if ($age > WorkerHeartbeatRepository::STALE_AFTER_SECONDS) {
            $status = 'warning';
        }

        return [
            'status' => $status,
            'state' => $age > WorkerHeartbeatRepository::STALE_AFTER_SECONDS
                ? 'unknown'
                : $current['state'],
            'checked_at' => $current['checked_at'],
            'changed_at' => $current['changed_at'],
            'successes' => $current['successes'],
            'failures' => $current['failures'],
            'successful_targets' => $current['successful_targets'],
            'failed_targets' => $current['failed_targets'],
        ];
    }

    /** @return array<string, mixed> */
    private function queueDiagnostics(): array
    {
        try {
            $rawCounts = $this->outbox->statusCounts();
            $counts = [
                'pending' => (int) ($rawCounts['pending'] ?? 0),
                'processing' => (int) ($rawCounts['processing'] ?? 0),
                'sent' => (int) ($rawCounts['sent'] ?? 0),
                'failed' => (int) ($rawCounts['failed'] ?? 0),
                'dead' => (int) ($rawCounts['dead'] ?? 0),
            ];
            $row = $this->pdo->query(
                <<<'SQL'
                SELECT
                    COUNT(*) FILTER (
                        WHERE status = 'processing'
                          AND locked_at IS NOT NULL
                          AND locked_at < CURRENT_TIMESTAMP - INTERVAL '5 minutes'
                    ) AS stale_processing,
                    COUNT(*) FILTER (
                        WHERE status IN ('pending', 'failed')
                          AND available_at <= CURRENT_TIMESTAMP - INTERVAL '2 minutes'
                    ) AS overdue_ready,
                    CAST(EXTRACT(EPOCH FROM (
                        CURRENT_TIMESTAMP - MIN(created_at) FILTER (
                            WHERE status IN ('pending', 'failed')
                              AND available_at <= CURRENT_TIMESTAMP
                        )
                    )) AS integer) AS oldest_ready_seconds
                FROM notification_outbox
                SQL
            )?->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('Queue diagnostics query returned no row.');
            }

            $staleProcessing = (int) $row['stale_processing'];
            $overdueReady = (int) $row['overdue_ready'];
            $oldestReady = $row['oldest_ready_seconds'] === null
                ? null
                : (int) $row['oldest_ready_seconds'];
            $status = 'ok';
            if ($staleProcessing > 0 || $overdueReady > 0) {
                $status = 'critical';
            } elseif ($counts['failed'] > 0 || $counts['dead'] > 0) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'counts' => $counts,
                'stale_processing' => $staleProcessing,
                'overdue_ready' => $overdueReady,
                'oldest_ready_seconds' => $oldestReady,
            ];
        } catch (Throwable) {
            return [
                'status' => 'critical',
                'counts' => [
                    'pending' => 0,
                    'processing' => 0,
                    'sent' => 0,
                    'failed' => 0,
                    'dead' => 0,
                ],
                'stale_processing' => 0,
                'overdue_ready' => 0,
                'oldest_ready_seconds' => null,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function hostDiagnostics(bool $includeMetrics = true): array
    {
        $serverId = $this->selectedHostId();
        if ($serverId === null) {
            return [
                'configured' => false,
                'status' => 'unknown',
                'server_id' => null,
                'server' => null,
                'metrics' => [],
                'disks' => [],
            ];
        }

        try {
            $server = $this->servers->find($serverId);
        } catch (Throwable) {
            return [
                'configured' => true,
                'status' => 'unknown',
                'server_id' => $serverId,
                'server' => null,
                'metrics' => [],
                'disks' => [],
            ];
        }
        if ($server === null) {
            return [
                'configured' => true,
                'status' => 'warning',
                'server_id' => $serverId,
                'server' => null,
                'metrics' => [],
                'disks' => [],
            ];
        }

        $enriched = $this->serverStatus->enrich([$server]);
        $server = $enriched[0];
        $monitoringStatus = (string) ($server['status'] ?? 'offline');
        $status = match ($monitoringStatus) {
            'online' => 'ok',
            'warning' => 'warning',
            'critical', 'offline' => 'critical',
            default => 'unknown',
        };

        if (!$includeMetrics) {
            return [
                'configured' => true,
                'status' => $status,
                'server_id' => $serverId,
                'server' => [
                    'id' => $serverId,
                    'name' => (string) $server['name'],
                    'monitoring_status' => $monitoringStatus,
                ],
                'metrics' => [],
                'disks' => [],
            ];
        }

        try {
            $latest = $this->metrics->latestValues($serverId);
        } catch (Throwable) {
            $latest = [];
            $status = $this->worst([$status, 'warning']);
        }
        $disks = [];
        foreach ($latest as $name => $metric) {
            if ($name === 'disk_used' || !str_starts_with($name, 'disk_used_')) {
                continue;
            }
            $suffix = substr($name, strlen('disk_used_'));
            $total = $latest['disk_total_gb_' . $suffix] ?? null;
            $disks[] = [
                'name' => $suffix === 'root' ? '/' : $suffix,
                'used_percent' => $metric['value'],
                'total_gb' => $total === null ? null : $total['value'],
            ];
        }
        usort($disks, static function (array $left, array $right): int {
            if ($left['name'] === '/') {
                return -1;
            }
            if ($right['name'] === '/') {
                return 1;
            }
            return strcmp((string) $left['name'], (string) $right['name']);
        });

        return [
            'configured' => true,
            'status' => $status,
            'server_id' => $serverId,
            'server' => [
                'id' => $serverId,
                'name' => (string) $server['name'],
                'address' => (string) ($server['address'] ?? ''),
                'monitoring_status' => $monitoringStatus,
                'last_contact_at' => $server['last_contact_at'] ?? null,
                'last_metrics_at' => $server['last_metrics_at'] ?? null,
            ],
            'metrics' => [
                'cpu_load' => $latest['cpu_load'] ?? null,
                'ram_used' => $latest['ram_used'] ?? null,
                'ram_total_gb' => $latest['ram_total_gb'] ?? null,
                'load_1' => $latest['load_1'] ?? null,
                'load_5' => $latest['load_5'] ?? null,
                'load_15' => $latest['load_15'] ?? null,
                'uptime' => $latest['uptime'] ?? null,
            ],
            'disks' => $disks,
        ];
    }

    /** @param list<string> $statuses */
    private function worst(array $statuses): string
    {
        $rank = [
            'ok' => 0,
            'unknown' => 1,
            'warning' => 2,
            'critical' => 3,
        ];
        $worst = 'ok';
        foreach ($statuses as $status) {
            if (($rank[$status] ?? 1) > $rank[$worst]) {
                $worst = isset($rank[$status]) ? $status : 'unknown';
            }
        }

        return $worst;
    }
}
