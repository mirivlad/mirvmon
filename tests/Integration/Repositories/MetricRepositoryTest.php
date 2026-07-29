<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\MetricRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class MetricRepositoryTest extends TestCase
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

    public function testLatestValuesSelectTheNewestSample(): void
    {
        [$serverId, $metricId] = $this->fixtureIds();
        $this->insertSample($serverId, $metricId, '2026-07-30T09:00:00+00:00', 11, 1);
        $this->insertSample($serverId, $metricId, '2026-07-30T09:05:00+00:00', 88, 2);

        $latest = (new MetricRepository(self::$pdo))->latestValues($serverId);

        self::assertSame(88.0, $latest['cpu_load']['value']);
        self::assertSame('2026-07-30 09:05:00+00', $latest['cpu_load']['sample_time']);
    }

    public function testRawSeriesIsChronologicalAndUsesTheRequestedMetricFilter(): void
    {
        [$serverId, $metricId] = $this->fixtureIds();
        $this->insertSample($serverId, $metricId, '2026-07-30T09:02:00+00:00', 20, 3);
        $this->insertSample($serverId, $metricId, '2026-07-30T09:01:00+00:00', 10, 4);

        $series = (new MetricRepository(self::$pdo))->series(
            $serverId,
            new DateTimeImmutable('2026-07-30T09:00:00+00:00'),
            new DateTimeImmutable('2026-07-30T10:00:00+00:00'),
            ['cpu_load']
        );

        self::assertSame('raw', $series['source']);
        self::assertSame(
            ['2026-07-30 09:01:00+00', '2026-07-30 09:02:00+00'],
            array_column($series['points'], 'time_bucket')
        );
        self::assertSame([10.0, 20.0], array_column($series['points'], 'value'));
    }

    public function testSourceSelectionUsesContinuousAggregatesForLongerRanges(): void
    {
        $repository = new MetricRepository(self::$pdo);
        $end = new DateTimeImmutable('2026-07-30T12:00:00+00:00');

        self::assertSame(
            'raw',
            $repository->sourceForRange($end->modify('-48 hours'), $end)
        );
        self::assertSame(
            'hourly',
            $repository->sourceForRange($end->modify('-30 days'), $end)
        );
        self::assertSame(
            'daily',
            $repository->sourceForRange($end->modify('-180 days'), $end)
        );

        self::assertSame([], $repository->series(
            999999,
            $end->modify('-30 days'),
            $end
        )['points']);
        self::assertSame([], $repository->series(
            999999,
            $end->modify('-180 days'),
            $end
        )['points']);
    }

    public function testMetricTypesUseTheTimescaleSampleTable(): void
    {
        [$serverId, $metricId] = $this->fixtureIds();
        $this->insertSample($serverId, $metricId, '2026-07-30T09:00:00+00:00', 11, 5);

        self::assertSame([
            ['id' => $metricId, 'name' => 'cpu_load', 'unit' => '%'],
        ], (new MetricRepository(self::$pdo))->metricTypes($serverId));
    }

    /** @return array{int, int} */
    private function fixtureIds(): array
    {
        $server = self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('metric-server') RETURNING id"
        );
        $metric = self::$pdo?->query(
            "SELECT id FROM metric_names WHERE name = 'cpu_load'"
        );

        return [(int) $server?->fetchColumn(), (int) $metric?->fetchColumn()];
    }

    private function insertSample(
        int $serverId,
        int $metricId,
        string $sampleTime,
        float $value,
        int $sequence
    ): void {
        $statement = self::$pdo?->prepare(
            'INSERT INTO metric_samples
                (sample_time, server_id, metric_id, sample_id, value)
             VALUES (:sample_time, :server_id, :metric_id, :sample_id, :value)'
        );
        $statement?->execute([
            'sample_time' => $sampleTime,
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'sample_id' => sprintf('00000000-0000-4000-8000-%012d', $sequence),
            'value' => $value,
        ]);
        $current = self::$pdo?->prepare(
            <<<'SQL'
            INSERT INTO current_metric_values
                (server_id, metric_id, sample_time, sample_id, value)
            VALUES (:server_id, :metric_id, :sample_time, :sample_id, :value)
            ON CONFLICT (server_id, metric_id) DO UPDATE
            SET
                sample_time = EXCLUDED.sample_time,
                sample_id = EXCLUDED.sample_id,
                value = EXCLUDED.value
            WHERE EXCLUDED.sample_time > current_metric_values.sample_time
               OR (
                   EXCLUDED.sample_time = current_metric_values.sample_time
                   AND EXCLUDED.sample_id > current_metric_values.sample_id
               )
            SQL
        );
        $current?->execute([
            'sample_time' => $sampleTime,
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'sample_id' => sprintf('00000000-0000-4000-8000-%012d', $sequence),
            'value' => $value,
        ]);
    }
}
