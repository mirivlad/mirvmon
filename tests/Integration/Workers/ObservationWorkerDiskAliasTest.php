<?php

declare(strict_types=1);

namespace Tests\Integration\Workers;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\ObservationAnalysisRepository;
use App\Repositories\ObservationRepository;
use App\Services\ContextualLevelShiftAnalyzer;
use App\Services\DiskMetricAliasResolver;
use App\Services\ObservationAnalyzer;
use App\Workers\ObservationWorker;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class ObservationWorkerDiskAliasTest extends TestCase
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

    public function testWorkerCollapsesExistingDiskAliasPredictions(): void
    {
        $now = new DateTimeImmutable('2026-09-16T12:00:00Z');
        $serverName = 'observation-alias-worker';
        $metricNames = [
            'disk_used_root', 'disk_used_tmp', 'disk_used_var_tmp',
            'disk_total_gb_root', 'disk_total_gb_tmp', 'disk_total_gb_var_tmp',
        ];
        $this->cleanup($serverName, $metricNames);

        try {
            $serverId = $this->insertServer($serverName);
            $metricIds = [];
            foreach ($metricNames as $metricName) {
                $metricIds[$metricName] = $this->insertMetric($metricName);
            }
            $this->insertDiskHistory($serverId, $metricIds, $now);
            $this->refreshHourly($now);
            $this->insertCurrentValues($serverId, $metricIds, $now);

            $repository = new ObservationRepository(self::$pdo);
            foreach (['root', 'tmp', 'var_tmp'] as $suffix) {
                $metricName = 'disk_used_' . $suffix;
                $created = $repository->recordCandidate(
                    $serverId,
                    $metricIds[$metricName],
                    $this->prediction($metricName),
                    $now->modify('-5 minutes')
                );
                $repository->markNotified($created['id'], 1, $now->modify('-5 minutes'));
            }

            $worker = new ObservationWorker(
                new ObservationAnalysisRepository(self::$pdo),
                $repository,
                new ObservationAnalyzer(),
                new ContextualLevelShiftAnalyzer(),
                new DiskMetricAliasResolver(),
                new NotificationOutboxRepository(self::$pdo)
            );
            $result = $worker->runOnce($now);

            self::assertSame(1, $result['detected']);
            self::assertSame(0, $result['notified']);
            self::assertSame(2, $result['resolved']);
            $rows = $this->observationRows($serverId);
            self::assertSame('active', $rows['disk_used_root']['status']);
            self::assertSame('resolved', $rows['disk_used_tmp']['status']);
            self::assertSame('resolved', $rows['disk_used_var_tmp']['status']);
            self::assertSame(
                ['disk_used_tmp', 'disk_used_var_tmp'],
                $rows['disk_used_root']['aliases']
            );
        } finally {
            $this->cleanup($serverName, $metricNames);
        }
    }

    private function insertServer(string $name): int
    {
        $statement = self::$pdo?->prepare(
            "INSERT INTO servers (name, is_active) VALUES (:name, TRUE) RETURNING id"
        );
        $statement?->execute(['name' => $name]);
        return (int) $statement?->fetchColumn();
    }

    private function insertMetric(string $name): int
    {
        $statement = self::$pdo?->prepare(
            "INSERT INTO metric_names (name, unit, description)
             VALUES (:name, :unit, 'disk alias hotfix test')
             ON CONFLICT (name) DO UPDATE SET unit = EXCLUDED.unit
             RETURNING id"
        );
        $statement?->execute([
            'name' => $name,
            'unit' => str_starts_with($name, 'disk_total_gb_') ? 'GiB' : '%',
        ]);
        return (int) $statement?->fetchColumn();
    }

    /** @param array<string, int> $metricIds */
    private function insertDiskHistory(int $serverId, array $metricIds, DateTimeImmutable $now): void
    {
        $statement = self::$pdo?->prepare(
            'INSERT INTO metric_samples (sample_time, server_id, metric_id, sample_id, value)
             VALUES (:sample_time, :server_id, :metric_id, :sample_id, :value)'
        );
        $counter = 1;
        foreach (['root', 'tmp', 'var_tmp'] as $suffix) {
            for ($hour = 48; $hour >= 1; $hour--) {
                $sampleTime = $now->modify('-' . $hour . ' hours');
                $value = 62.0 - 1.2 * ($hour / 24.0);
                $statement?->execute([
                    'sample_time' => $sampleTime->format(DATE_ATOM),
                    'server_id' => $serverId,
                    'metric_id' => $metricIds['disk_used_' . $suffix],
                    'sample_id' => sprintf('00000000-0000-0000-0000-%012d', $counter++),
                    'value' => $value,
                ]);
            }
        }
    }

    private function refreshHourly(DateTimeImmutable $now): void
    {
        $start = $now->modify('-3 days')->format('Y-m-d H:i:sP');
        $end = $now->modify('+1 hour')->format('Y-m-d H:i:sP');
        self::$pdo?->exec(sprintf(
            "CALL refresh_continuous_aggregate('metric_samples_hourly', '%s', '%s')",
            $start,
            $end
        ));
    }

    /** @param array<string, int> $metricIds */
    private function insertCurrentValues(int $serverId, array $metricIds, DateTimeImmutable $now): void
    {
        $statement = self::$pdo?->prepare(
            'INSERT INTO current_metric_values (server_id, metric_id, sample_time, sample_id, value)
             VALUES (:server_id, :metric_id, :sample_time, :sample_id, :value)'
        );
        $counter = 900000;
        foreach (['root', 'tmp', 'var_tmp'] as $suffix) {
            foreach ([
                'disk_used_' . $suffix => 62.0,
                'disk_total_gb_' . $suffix => 80.0,
            ] as $metricName => $value) {
                $statement?->execute([
                    'server_id' => $serverId,
                    'metric_id' => $metricIds[$metricName],
                    'sample_time' => $now->format(DATE_ATOM),
                    'sample_id' => sprintf('00000000-0000-0000-0000-%012d', $counter++),
                    'value' => $value,
                ]);
            }
        }
    }

    /** @return array<string, array{status:string,aliases:list<string>}> */
    private function observationRows(int $serverId): array
    {
        $statement = self::$pdo?->prepare(
            'SELECT metric_names.name, observations.status, observations.details
             FROM observations
             INNER JOIN metric_names ON metric_names.id = observations.metric_id
             WHERE observations.server_id = :server_id'
        );
        $statement?->execute(['server_id' => $serverId]);
        $rows = [];
        foreach ($statement?->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $details = json_decode((string) $row['details'], true, flags: JSON_THROW_ON_ERROR);
            $rows[(string) $row['name']] = [
                'status' => (string) $row['status'],
                'aliases' => array_values(array_map(
                    'strval',
                    is_array($details['aliases'] ?? null) ? $details['aliases'] : []
                )),
            ];
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    private function prediction(string $metricName): array
    {
        return [
            'kind' => 'prediction',
            'detector' => 'disk_growth_v1',
            'fingerprint' => 'disk_growth_v1:' . $metricName,
            'current_value' => 62.0,
            'baseline_value' => 59.6,
            'confidence' => 0.94,
            'forecast_at' => '2026-09-23T12:00:00+00:00',
            'details' => [
                'metric' => $metricName,
                'slope_percent_per_day' => 1.2,
            ],
        ];
    }

    /** @param list<string> $metricNames */
    private function cleanup(string $serverName, array $metricNames): void
    {
        $deleteServer = self::$pdo?->prepare('DELETE FROM servers WHERE name = :name');
        $deleteServer?->execute(['name' => $serverName]);

        $placeholders = implode(', ', array_fill(0, count($metricNames), '?'));
        $deleteMetrics = self::$pdo?->prepare(
            'DELETE FROM metric_names WHERE name IN (' . $placeholders . ')'
        );
        $deleteMetrics?->execute($metricNames);
    }
}
