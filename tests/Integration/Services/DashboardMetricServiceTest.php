<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Services\DashboardMetricService;
use PDO;
use PHPUnit\Framework\TestCase;

final class DashboardMetricServiceTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testDisplayOptionsUseRecentSamplesAndHideSupportingMetrics(): void
    {
        $serverId = $this->server();
        $sampleId = '11111111-1111-4111-8111-111111111111';
        self::$pdo?->prepare(
            "INSERT INTO ingested_samples (server_id, sample_id, sample_time, received_at)
             VALUES (:server_id, :sample_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute(['server_id' => $serverId, 'sample_id' => $sampleId]);

        foreach ([
            'cpu_load' => '%',
            'ram_used' => '%',
            'ram_total_gb' => 'GB',
            'uptime' => 's',
            'disk_used_root' => '%',
            'disk_total_gb_root' => 'GB',
            'disk_read_sda' => 'B/s',
            'disk_write_sda' => 'B/s',
            'net_in_eth0' => 'B/s',
            'net_out_eth0' => 'B/s',
            'temp_system' => '°C',
        ] as $name => $unit) {
            $this->metricSample($serverId, $sampleId, $name, $unit);
        }

        // A legacy current value that was not present in recent samples must not
        // leak back into the dashboard selector.
        $legacyMetricId = $this->metricId('temp_cpu', '°C');
        self::$pdo?->prepare(
            "INSERT INTO current_metric_values (server_id, metric_id, sample_time, sample_id, value)
             VALUES (:server_id, :metric_id, CURRENT_TIMESTAMP, :sample_id, 50)"
        )->execute([
            'server_id' => $serverId,
            'metric_id' => $legacyMetricId,
            'sample_id' => '22222222-2222-4222-8222-222222222222',
        ]);

        $service = new DashboardMetricService(self::$pdo);
        $options = $service->displayOptions($serverId, [
            'cpu_load',
            'ram_used',
            'disk_used_root',
            'disk_read_sda',
            'disk_write_sda',
            'net_in_eth0',
            'net_out_eth0',
            'temp_cpu',
        ]);

        $ids = [];
        foreach ($options['groups'] as $group) {
            foreach ($group['options'] as $option) {
                $ids[] = $option['id'];
            }
        }

        self::assertContains('cpu_load', $ids);
        self::assertContains('ram_used', $ids);
        self::assertContains('uptime', $ids);
        self::assertContains('availability', $ids);
        self::assertContains('disk_used_root', $ids);
        self::assertContains('disk_io_sda', $ids);
        self::assertContains('net_eth0', $ids);
        self::assertContains('temperatures', $ids);
        self::assertNotContains('ram_total_gb', $ids);
        self::assertNotContains('disk_total_gb_root', $ids);
        self::assertNotContains('temp_cpu', $ids);

        self::assertContains('disk_io_sda', $options['selected']);
        self::assertContains('net_eth0', $options['selected']);
        self::assertContains('temperatures', $options['selected']);
    }

    public function testLogicalWidgetsExpandToCurrentRawMetrics(): void
    {
        $serverId = $this->server();
        $sampleId = '33333333-3333-4333-8333-333333333333';
        self::$pdo?->prepare(
            "INSERT INTO ingested_samples (server_id, sample_id, sample_time, received_at)
             VALUES (:server_id, :sample_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute(['server_id' => $serverId, 'sample_id' => $sampleId]);

        foreach ([
            'disk_read_sda' => 'B/s',
            'disk_write_sda' => 'B/s',
            'net_in_eth0' => 'B/s',
            'net_out_eth0' => 'B/s',
            'temp_system' => '°C',
            'ram_used' => '%',
        ] as $name => $unit) {
            $this->metricSample($serverId, $sampleId, $name, $unit);
        }

        $expanded = (new DashboardMetricService(self::$pdo))->expandWidgets($serverId, [
            'disk_io_sda',
            'net_eth0',
            'temperatures',
            'ram_used',
            'availability',
            'disk_io_missing',
        ]);

        sort($expanded);
        self::assertSame([
            'availability',
            'disk_read_sda',
            'disk_write_sda',
            'net_in_eth0',
            'net_out_eth0',
            'ram_used',
            'temp_system',
        ], $expanded);
    }

    private function server(): int
    {
        return (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('dashboard-metric-test') RETURNING id"
        )->fetchColumn();
    }

    private function metricSample(int $serverId, string $sampleId, string $name, string $unit): void
    {
        $metricId = $this->metricId($name, $unit);
        self::$pdo?->prepare(
            "INSERT INTO metric_samples (sample_time, server_id, metric_id, sample_id, value)
             VALUES (CURRENT_TIMESTAMP, :server_id, :metric_id, :sample_id, 1)"
        )->execute([
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'sample_id' => $sampleId,
        ]);
    }

    private function metricId(string $name, string $unit): int
    {
        self::$pdo?->prepare(
            "INSERT INTO metric_names (name, unit, description)
             VALUES (:name, :unit, :name)
             ON CONFLICT (name) DO NOTHING"
        )->execute(['name' => $name, 'unit' => $unit]);
        $statement = self::$pdo?->prepare('SELECT id FROM metric_names WHERE name = :name');
        $statement?->execute(['name' => $name]);

        return (int) $statement?->fetchColumn();
    }
}
