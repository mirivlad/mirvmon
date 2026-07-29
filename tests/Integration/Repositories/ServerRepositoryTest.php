<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\ServerRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class ServerRepositoryTest extends TestCase
{
    private static ?CountingPdo $pdo = null;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            self::markTestSkipped('Set TEST_DB_* to run the TimescaleDB integration suite.');
        }

        $environment = [
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_PORT' => (string) (getenv('TEST_DB_PORT') ?: '5432'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'DB_SSLMODE' => (string) (getenv('TEST_DB_SSLMODE') ?: 'disable'),
        ];

        self::$pdo = new CountingPdo(
            ConnectionFactory::dsn($environment),
            $environment['DB_USERNAME'],
            $environment['DB_PASSWORD'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );
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

    public function testDashboardReturnsNewestMetricsAlertsAndThresholdsInOneQuery(): void
    {
        $serverId = $this->createServer('dashboard-server');
        $metricId = $this->metricId('cpu_load');

        $sample = self::$pdo?->prepare(
            'INSERT INTO metric_samples
                (sample_time, server_id, metric_id, sample_id, value)
             VALUES (:sample_time, :server_id, :metric_id, :sample_id, :value)'
        );
        $sample?->execute([
            'sample_time' => '2026-07-30T10:00:00+00:00',
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'sample_id' => '00000000-0000-4000-8000-000000000001',
            'value' => 10,
        ]);
        $sample?->execute([
            'sample_time' => '2026-07-30T10:01:00+00:00',
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'sample_id' => '00000000-0000-4000-8000-000000000002',
            'value' => 42.5,
        ]);
        self::$pdo?->prepare(
            'INSERT INTO current_metric_values
                (server_id, metric_id, sample_time, sample_id, value)
             VALUES (
                :server_id,
                :metric_id,
                :sample_time,
                :sample_id,
                :value
             )'
        )->execute([
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'sample_time' => '2026-07-30T10:01:00+00:00',
            'sample_id' => '00000000-0000-4000-8000-000000000002',
            'value' => 42.5,
        ]);

        self::$pdo?->prepare(
            'INSERT INTO metric_thresholds
                (server_id, metric_id, warning_threshold, critical_threshold)
             VALUES (:server_id, :metric_id, 70, 90)'
        )->execute(['server_id' => $serverId, 'metric_id' => $metricId]);
        self::$pdo?->prepare(
            "INSERT INTO alerts (server_id, metric_id, severity, subject)
             VALUES (:server_id, :metric_id, 'warning', 'cpu_load')"
        )->execute(['server_id' => $serverId, 'metric_id' => $metricId]);

        self::$pdo->queryCount = 0;
        $servers = (new ServerRepository(self::$pdo))->dashboardServers();

        self::assertSame(1, self::$pdo->queryCount);
        self::assertCount(1, $servers);
        self::assertSame(42.5, $servers[0]['latest_metrics']['cpu_load']['value']);
        self::assertSame(1, $servers[0]['active_alerts']);
        self::assertSame(1, $servers[0]['warning_alerts']);
        self::assertSame([
            'warning' => 70.0,
            'critical' => 90.0,
        ], $servers[0]['thresholds']['cpu_load']);
    }

    public function testDashboardQueryCountDoesNotGrowWithServerCount(): void
    {
        $repository = new ServerRepository(self::$pdo);

        $this->createServer('one');
        self::$pdo->queryCount = 0;
        self::assertCount(1, $repository->dashboardServers());
        $oneServerQueryCount = self::$pdo->queryCount;

        for ($index = 2; $index <= 50; $index++) {
            $this->createServer('server-' . $index);
        }
        self::$pdo->queryCount = 0;
        self::assertCount(50, $repository->dashboardServers());

        self::assertSame(1, $oneServerQueryCount);
        self::assertSame($oneServerQueryCount, self::$pdo->queryCount);
    }

    public function testDetailSettingsUsePostgreSqlSchemaNamesAndUpserts(): void
    {
        $serverId = $this->createServer('detail-server');
        $metricId = $this->metricId('cpu_load');
        $repository = new ServerRepository(self::$pdo);

        $repository->replaceThresholds($serverId, [[
            'metric_id' => $metricId,
            'warning' => 65,
            'critical' => 85,
            'duration' => 120,
        ]]);
        $repository->saveMonitoredServices($serverId, ['postgresql.service']);
        $repository->saveMonitoredServices($serverId, ['caddy.service']);

        self::assertSame(65.0, $repository->thresholds($serverId)['cpu_load']['warning']);
        self::assertSame(120, $repository->thresholds($serverId)['cpu_load']['duration']);
        self::assertSame(['caddy.service'], $repository->monitoredServices($serverId));
        self::assertSame('detail-server', $repository->find($serverId)['name'] ?? null);
    }

    private function createServer(string $name): int
    {
        $statement = self::$pdo?->prepare(
            'INSERT INTO servers (name, offline_timeout_seconds)
             VALUES (:name, 300)
             RETURNING id'
        );
        $statement?->execute(['name' => $name]);

        return (int) $statement?->fetchColumn();
    }

    private function metricId(string $name): int
    {
        $statement = self::$pdo?->prepare('SELECT id FROM metric_names WHERE name = :name');
        $statement?->execute(['name' => $name]);

        return (int) $statement?->fetchColumn();
    }
}

final class CountingPdo extends PDO
{
    public int $queryCount = 0;

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        $this->queryCount++;

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queryCount++;

        return parent::prepare($query, $options);
    }
}
