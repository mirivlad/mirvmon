<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Domain\Metrics\MetricsEnvelope;
use App\Repositories\NotificationOutboxRepository;
use App\Services\MetricsIngestionService;
use App\Services\ThresholdEvaluator;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class MetricsIngestionServiceTest extends TestCase
{
    private static ?PDO $pdo = null;
    private int $serverId;
    private string $token;
    private MetricsIngestionService $ingestion;

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
        $server = self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('ingestion-server') RETURNING id"
        );
        $this->serverId = (int) $server?->fetchColumn();
        $this->token = str_repeat('b', 64);
        self::$pdo?->prepare(
            'INSERT INTO agent_tokens (server_id, token_hash)
             VALUES (:server_id, :token_hash)'
        )->execute([
            'server_id' => $this->serverId,
            'token_hash' => hash('sha256', $this->token),
        ]);
        self::$pdo?->exec(
            "UPDATE notification_settings
             SET telegram_enabled = TRUE, telegram_chat_id = '123'"
        );

        $this->ingestion = new MetricsIngestionService(
            self::$pdo,
            new ThresholdEvaluator(),
            new NotificationOutboxRepository(self::$pdo)
        );
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testDuplicateSampleCreatesOneHistoryRowAlertAndOutboxEvent(): void
    {
        $metricId = $this->metricId('cpu_load');
        self::$pdo?->prepare(
            'INSERT INTO metric_thresholds
                (server_id, metric_id, warning_threshold, critical_threshold)
             VALUES (:server_id, :metric_id, 70, 90)'
        )->execute(['server_id' => $this->serverId, 'metric_id' => $metricId]);
        $envelope = $this->envelope(
            '20000000-0000-4000-8000-000000000001',
            '2026-07-30T11:59:00Z',
            ['cpu_load' => 95]
        );

        $startedAt = hrtime(true);
        $accepted = $this->ingestion->ingest($envelope);
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
        $duplicate = $this->ingestion->ingest($envelope);

        self::assertFalse($accepted->duplicate);
        self::assertTrue($duplicate->duplicate);
        self::assertLessThan(500, $elapsedMilliseconds);
        self::assertSame(1, $this->tableCount('ingested_samples'));
        self::assertSame(1, $this->tableCount('metric_samples'));
        self::assertSame(1, $this->tableCount('current_metric_values'));
        self::assertSame(1, $this->tableCount('alerts'));
        self::assertSame(1, $this->tableCount('notification_outbox'));
        self::assertSame(
            ['critical', '95'],
            self::$pdo?->query(
                'SELECT severity, value::text FROM alerts'
            )->fetch(PDO::FETCH_NUM)
        );
        self::assertSame(
            '95',
            (string) self::$pdo?->query(
                'SELECT value FROM current_metric_values'
            )->fetchColumn()
        );
    }

    public function testRecoveryResolvesAlertAndQueuesOneRecovery(): void
    {
        $metricId = $this->metricId('cpu_load');
        self::$pdo?->prepare(
            'INSERT INTO metric_thresholds (
                server_id,
                metric_id,
                warning_threshold,
                critical_threshold,
                recovery_duration_seconds
             ) VALUES (:server_id, :metric_id, 70, 90, 0)'
        )->execute(['server_id' => $this->serverId, 'metric_id' => $metricId]);

        $this->ingestion->ingest($this->envelope(
            '20000000-0000-4000-8000-000000000002',
            '2026-07-30T11:58:00Z',
            ['cpu_load' => 95]
        ));
        $this->ingestion->ingest($this->envelope(
            '20000000-0000-4000-8000-000000000003',
            '2026-07-30T11:59:00Z',
            ['cpu_load' => 20]
        ));

        self::assertSame(
            ['true', 2],
            self::$pdo?->query(
                'SELECT resolved::text, (SELECT count(*) FROM notification_outbox)
                 FROM alerts'
            )->fetch(PDO::FETCH_NUM)
        );
    }

    public function testRecoveryWaitsForACompleteWindowBelowTheThreshold(): void
    {
        $metricId = $this->metricId('cpu_load');
        self::$pdo?->prepare(
            'INSERT INTO metric_thresholds (
                server_id,
                metric_id,
                warning_threshold,
                critical_threshold,
                recovery_duration_seconds
             ) VALUES (:server_id, :metric_id, 70, 90, 300)'
        )->execute(['server_id' => $this->serverId, 'metric_id' => $metricId]);

        $samples = [
            ['2026-07-30T12:00:00Z', 95],
            ['2026-07-30T12:01:00Z', 20],
            ['2026-07-30T12:03:00Z', 20],
        ];
        foreach ($samples as $index => [$time, $value]) {
            $this->ingestion->ingest($this->envelope(
                sprintf('30000000-0000-4000-8000-00000000000%d', $index),
                $time,
                ['cpu_load' => $value]
            ));
        }

        // Four minutes below the threshold is short of the five-minute window.
        self::assertSame('false', (string) self::$pdo?->query(
            'SELECT resolved::text FROM alerts'
        )->fetchColumn());

        $this->ingestion->ingest($this->envelope(
            '30000000-0000-4000-8000-000000000009',
            '2026-07-30T12:07:00Z',
            ['cpu_load' => 20]
        ));

        self::assertSame('true', (string) self::$pdo?->query(
            'SELECT resolved::text FROM alerts'
        )->fetchColumn());
        self::assertSame(1, (int) self::$pdo?->query(
            "SELECT count(*) FROM notification_outbox
             WHERE event_type = 'metric_recovered'"
        )->fetchColumn());
    }

    public function testAFlappingMetricDoesNotCloseTheAlert(): void
    {
        $metricId = $this->metricId('cpu_load');
        self::$pdo?->prepare(
            'INSERT INTO metric_thresholds (
                server_id,
                metric_id,
                warning_threshold,
                critical_threshold,
                recovery_duration_seconds
             ) VALUES (:server_id, :metric_id, 70, 90, 300)'
        )->execute(['server_id' => $this->serverId, 'metric_id' => $metricId]);

        $samples = [
            ['2026-07-30T12:00:00Z', 95],
            ['2026-07-30T12:02:00Z', 20],
            ['2026-07-30T12:04:00Z', 95],
            ['2026-07-30T12:06:00Z', 20],
            ['2026-07-30T12:08:00Z', 20],
        ];
        foreach ($samples as $index => [$time, $value]) {
            $this->ingestion->ingest($this->envelope(
                sprintf('40000000-0000-4000-8000-00000000000%d', $index),
                $time,
                ['cpu_load' => $value]
            ));
        }

        self::assertSame('false', (string) self::$pdo?->query(
            'SELECT resolved::text FROM alerts'
        )->fetchColumn());
        self::assertSame(0, (int) self::$pdo?->query(
            "SELECT count(*) FROM notification_outbox
             WHERE event_type = 'metric_recovered'"
        )->fetchColumn());
    }

    public function testOutOfOrderSampleAddsHistoryButDoesNotRegressCurrentState(): void
    {
        $this->ingestion->ingest($this->envelope(
            '20000000-0000-4000-8000-000000000004',
            '2026-07-30T11:59:00Z',
            ['cpu_load' => 50]
        ));
        $this->ingestion->ingest($this->envelope(
            '20000000-0000-4000-8000-000000000005',
            '2026-07-30T11:50:00Z',
            ['cpu_load' => 5]
        ));

        self::assertSame(2, $this->tableCount('metric_samples'));
        self::assertSame(
            '50',
            (string) self::$pdo?->query(
                'SELECT value FROM current_metric_values'
            )->fetchColumn()
        );
        self::assertStringStartsWith(
            '2026-07-30 11:59:00',
            (string) self::$pdo?->query(
                'SELECT last_metrics_at FROM servers WHERE id = ' . $this->serverId
            )->fetchColumn()
        );
    }

    public function testServiceAlertsOnlyOnMonitoredStateTransitions(): void
    {
        self::$pdo?->prepare(
            'INSERT INTO agent_configs (server_id, monitor_services)
             VALUES (:server_id, CAST(:services AS jsonb))'
        )->execute([
            'server_id' => $this->serverId,
            'services' => '["postgresql.service"]',
        ]);

        $this->ingestion->ingest($this->envelope(
            '20000000-0000-4000-8000-000000000006',
            '2026-07-30T11:57:00Z',
            ['cpu_load' => 10],
            [$this->service('running')]
        ));
        self::assertSame(0, $this->tableCount('alerts'));

        $stopped = $this->envelope(
            '20000000-0000-4000-8000-000000000007',
            '2026-07-30T11:58:00Z',
            ['cpu_load' => 11],
            [$this->service('stopped')]
        );
        $this->ingestion->ingest($stopped);
        $this->ingestion->ingest($stopped);
        self::assertSame(1, $this->tableCount('alerts'));
        self::assertSame(1, $this->tableCount('notification_outbox'));

        $this->ingestion->ingest($this->envelope(
            '20000000-0000-4000-8000-000000000008',
            '2026-07-30T11:59:00Z',
            ['cpu_load' => 12],
            [$this->service('running')]
        ));

        self::assertSame('true', self::$pdo?->query(
            "SELECT resolved::text FROM alerts WHERE kind = 'service'"
        )->fetchColumn());
        self::assertSame(2, $this->tableCount('notification_outbox'));
    }

    public function testProcessSnapshotIsStoredSeparatelyFromNumericMetrics(): void
    {
        $envelope = new MetricsEnvelope(
            2,
            '20000000-0000-4000-8000-000000000009',
            new DateTimeImmutable('2026-07-30T11:59:00Z'),
            $this->token,
            ['cpu_load' => 10],
            [],
            [
                'top_cpu' => [[
                    'pid' => 1,
                    'name' => 'init',
                    'command' => '/sbin/init',
                    'value' => 1.0,
                ]],
                'top_memory' => [],
            ]
        );

        $this->ingestion->ingest($envelope);

        self::assertSame(1, $this->tableCount('process_snapshots'));
        self::assertSame(1, $this->tableCount('metric_samples'));
        self::assertStringContainsString(
            '"top_cpu"',
            (string) self::$pdo?->query(
                'SELECT processes::text FROM process_snapshots'
            )->fetchColumn()
        );
    }

    public function testDurationThresholdRequiresACompleteHealthyWindow(): void
    {
        $metricId = $this->metricId('cpu_load');
        self::$pdo?->prepare(
            'INSERT INTO metric_thresholds (
                server_id,
                metric_id,
                warning_threshold,
                critical_threshold,
                duration_seconds
             ) VALUES (
                :server_id,
                :metric_id,
                70,
                90,
                60
             )'
        )->execute(['server_id' => $this->serverId, 'metric_id' => $metricId]);

        $this->ingestion->ingest($this->envelope(
            '20000000-0000-4000-8000-000000000010',
            '2026-07-30T11:57:50Z',
            ['cpu_load' => 95]
        ));
        $this->ingestion->ingest($this->envelope(
            '20000000-0000-4000-8000-000000000011',
            '2026-07-30T11:58:30Z',
            ['cpu_load' => 95]
        ));
        self::assertSame(0, $this->tableCount('alerts'));

        $this->ingestion->ingest($this->envelope(
            '20000000-0000-4000-8000-000000000012',
            '2026-07-30T11:59:00Z',
            ['cpu_load' => 95]
        ));

        self::assertSame(1, $this->tableCount('alerts'));
    }

    /** @param array<string, float> $metrics
     *  @param list<array<string, string>> $services
     */
    private function envelope(
        string $sampleId,
        string $sampleTime,
        array $metrics,
        array $services = []
    ): MetricsEnvelope {
        return new MetricsEnvelope(
            2,
            $sampleId,
            new DateTimeImmutable($sampleTime),
            $this->token,
            $metrics,
            $services,
            null
        );
    }

    /** @return array<string, string> */
    private function service(string $status): array
    {
        return [
            'name' => 'postgresql.service',
            'status' => $status,
            'load_state' => 'loaded',
            'active_state' => $status === 'running' ? 'active' : 'inactive',
            'sub_state' => $status === 'running' ? 'running' : 'dead',
        ];
    }

    private function metricId(string $name): int
    {
        $statement = self::$pdo?->prepare('SELECT id FROM metric_names WHERE name = :name');
        $statement?->execute(['name' => $name]);

        return (int) $statement?->fetchColumn();
    }

    private function tableCount(string $table): int
    {
        return (int) self::$pdo?->query('SELECT count(*) FROM ' . $table)->fetchColumn();
    }
}
