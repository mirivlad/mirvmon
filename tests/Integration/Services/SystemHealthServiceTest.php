<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\AppSettingsRepository;
use App\Repositories\MetricRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\ServerRepository;
use App\Repositories\WorkerHeartbeatRepository;
use App\Services\ServerPlatformService;
use App\Services\ServerStatusService;
use App\Services\SystemHealthService;
use PDO;
use PHPUnit\Framework\TestCase;

final class SystemHealthServiceTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            self::markTestSkipped('Set TEST_DB_* to run the TimescaleDB integration suite.');
        }

        self::$pdo = ConnectionFactory::connect([
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_PORT' => (string) (getenv('TEST_DB_PORT') ?: '5432'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'DB_SSLMODE' => (string) (getenv('TEST_DB_SSLMODE') ?: 'disable'),
        ]);
        (new Migrator(self::$pdo, dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        self::$pdo?->beginTransaction();
        self::$pdo?->exec('DELETE FROM notification_outbox');
        self::$pdo?->exec('DELETE FROM worker_heartbeats');
        self::$pdo?->prepare(
            "DELETE FROM app_settings WHERE setting_key = 'mirvmon_host_server_id'"
        )->execute();
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testHealthyInstallationSeparatesApplicationAndHostDiagnostics(): void
    {
        $serverId = $this->server();
        $heartbeats = new WorkerHeartbeatRepository(self::$pdo);
        $heartbeats->record(WorkerHeartbeatRepository::NOTIFICATION_WORKER);
        $heartbeats->record(WorkerHeartbeatRepository::OFFLINE_WORKER);
        (new AppSettingsRepository(self::$pdo))->set(SystemHealthService::HOST_SETTING, $serverId);

        foreach ([
            'cpu_load' => [23.5, '%'],
            'ram_used' => [61.25, '%'],
            'ram_total_gb' => [16.0, 'GB'],
            'uptime' => [172800.0, 's'],
            'load_1' => [0.42, null],
            'load_5' => [0.31, null],
            'load_15' => [0.27, null],
            'disk_used_root' => [55.5, '%'],
            'disk_total_gb_root' => [120.0, 'GB'],
        ] as $name => [$value, $unit]) {
            $this->currentMetric($serverId, $name, $value, $unit);
        }

        $details = $this->service()->details();

        self::assertSame('ok', $details['application']['status']);
        self::assertSame('v0.4.16', $details['application']['version']);
        self::assertSame('ok', $details['database']['status']);
        self::assertTrue($details['database']['available']);
        self::assertNotSame('', $details['database']['timescale_version']);
        self::assertSame('ok', $details['workers']['status']);
        self::assertCount(2, $details['workers']['items']);
        self::assertSame('ok', $details['queue']['status']);
        self::assertSame([
            'pending' => 0,
            'processing' => 0,
            'sent' => 0,
            'failed' => 0,
            'dead' => 0,
        ], $details['queue']['counts']);

        self::assertTrue($details['host']['configured']);
        self::assertSame('ok', $details['host']['status']);
        self::assertSame($serverId, $details['host']['server']['id']);
        self::assertSame(23.5, $details['host']['metrics']['cpu_load']['value']);
        self::assertSame(0.42, $details['host']['metrics']['load_1']['value']);
        self::assertSame('/', $details['host']['disks'][0]['name']);
        self::assertSame(55.5, $details['host']['disks'][0]['used_percent']);
    }

    public function testMissingWorkerHeartbeatMakesApplicationCriticalWithoutChangingHostState(): void
    {
        $serverId = $this->server();
        (new AppSettingsRepository(self::$pdo))->set(SystemHealthService::HOST_SETTING, $serverId);
        $heartbeats = new WorkerHeartbeatRepository(self::$pdo);
        $heartbeats->record(WorkerHeartbeatRepository::NOTIFICATION_WORKER);

        $summary = $this->service()->summary();

        self::assertSame('critical', $summary['application_status']);
        self::assertSame('ok', $summary['host_status']);
        self::assertTrue($summary['host_configured']);
    }

    public function testDeletedSelectedHostIsWarningInsteadOfFatal(): void
    {
        (new AppSettingsRepository(self::$pdo))->set(SystemHealthService::HOST_SETTING, 999999999);
        $heartbeats = new WorkerHeartbeatRepository(self::$pdo);
        $heartbeats->record(WorkerHeartbeatRepository::NOTIFICATION_WORKER);
        $heartbeats->record(WorkerHeartbeatRepository::OFFLINE_WORKER);

        $details = $this->service()->details();

        self::assertTrue($details['host']['configured']);
        self::assertSame('warning', $details['host']['status']);
        self::assertNull($details['host']['server']);
        self::assertSame('ok', $details['application']['status']);
    }

    private function service(): SystemHealthService
    {
        return new SystemHealthService(
            self::$pdo,
            new AppSettingsRepository(self::$pdo),
            new ServerRepository(self::$pdo),
            new MetricRepository(self::$pdo),
            new WorkerHeartbeatRepository(self::$pdo),
            new NotificationOutboxRepository(self::$pdo),
            new ServerStatusService(new ServerPlatformService()),
            'v0.4.16',
            'test'
        );
    }

    private function server(): int
    {
        $serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (
                name, address, last_metrics_at, offline_timeout_seconds, is_active
             ) VALUES (
                'mirvmon-host-test', '127.0.0.1', CURRENT_TIMESTAMP, 300, TRUE
             ) RETURNING id"
        )->fetchColumn();
        self::$pdo?->prepare(
            'INSERT INTO agent_tokens (server_id, token_hash, last_used_at)
             VALUES (:server_id, :token_hash, CURRENT_TIMESTAMP)'
        )->execute([
            'server_id' => $serverId,
            'token_hash' => hash('sha256', 'system-health-' . $serverId),
        ]);

        return $serverId;
    }

    private function currentMetric(int $serverId, string $name, float $value, ?string $unit): void
    {
        self::$pdo?->prepare(
            'INSERT INTO metric_names (name, unit, description)
             VALUES (:name, :unit, :description)
             ON CONFLICT (name) DO NOTHING'
        )->execute([
            'name' => $name,
            'unit' => $unit,
            'description' => $name,
        ]);
        $metric = self::$pdo?->prepare('SELECT id FROM metric_names WHERE name = :name');
        $metric?->execute(['name' => $name]);
        $metricId = (int) $metric?->fetchColumn();
        $sampleId = sprintf(
            '%08x-%04x-4%03x-8%03x-%012x',
            $serverId,
            $metricId & 0xffff,
            $metricId & 0x0fff,
            $serverId & 0x0fff,
            ($serverId * 1000 + $metricId) & 0xffffffffffff
        );
        self::$pdo?->prepare(
            'INSERT INTO current_metric_values (server_id, metric_id, sample_time, sample_id, value)
             VALUES (:server_id, :metric_id, CURRENT_TIMESTAMP, CAST(:sample_id AS uuid), :value)
             ON CONFLICT (server_id, metric_id) DO UPDATE
             SET sample_time = EXCLUDED.sample_time,
                 sample_id = EXCLUDED.sample_id,
                 value = EXCLUDED.value'
        )->execute([
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'sample_id' => $sampleId,
            'value' => $value,
        ]);
    }
}
