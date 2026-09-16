<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\ObservationRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class ObservationRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private ObservationRepository $repository;
    private int $serverId;
    private int $metricId;

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
        $statement = self::$pdo?->prepare(
            "INSERT INTO servers (name, is_active) VALUES (:name, TRUE) RETURNING id"
        );
        $statement?->execute(['name' => 'observation-test']);
        $this->serverId = (int) $statement?->fetchColumn();
        $this->metricId = (int) self::$pdo?->query(
            "SELECT id FROM metric_names WHERE name = 'cpu_load'"
        )->fetchColumn();
        $this->repository = new ObservationRepository(self::$pdo);
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testAcceptedAnomalyStaysQuietUntilOperatorResetsIt(): void
    {
        $now = new DateTimeImmutable('2026-09-16T00:00:00Z');
        $candidate = $this->anomaly('level_shift_v1:cpu_load:p0:b20');

        $created = $this->repository->recordCandidate(
            $this->serverId,
            $this->metricId,
            $candidate,
            $now
        );
        self::assertTrue($created['should_notify']);
        $this->repository->markNotified($created['id'], 1, $now);
        self::assertTrue($this->repository->acceptNormal($created['id'], null, 'operator'));
        $accepted = $this->repository->recordCandidate(
            $this->serverId,
            $this->metricId,
            $candidate,
            $now->modify('+5 minutes')
        );
        self::assertSame('accepted_normal', $accepted['status']);
        self::assertFalse($accepted['should_notify']);

        self::assertTrue($this->repository->resetAcceptedNormal($created['id']));
        $rearmed = $this->repository->recordCandidate(
            $this->serverId,
            $this->metricId,
            $candidate,
            $now->modify('+10 minutes')
        );
        self::assertSame('active', $rearmed['status']);
        self::assertTrue($rearmed['should_notify']);
    }

    public function testHandledPredictionRearmsOnlyAfterConditionDisappears(): void
    {
        $now = new DateTimeImmutable('2026-09-16T00:00:00Z');
        $candidate = $this->prediction('disk_growth_v1:disk_used_root');
        $created = $this->repository->recordCandidate(
            $this->serverId,
            $this->metricId,
            $candidate,
            $now
        );
        $this->repository->markNotified($created['id'], 1, $now);
        self::assertTrue($this->repository->handlePrediction($created['id'], null, 'operator'));

        $stillPresent = $this->repository->recordCandidate(
            $this->serverId,
            $this->metricId,
            $candidate,
            $now->modify('+5 minutes')
        );
        self::assertSame('handled', $stillPresent['status']);
        self::assertFalse($stillPresent['should_notify']);
        $resolved = $this->repository->resolveMissing(
            [],
            [$this->serverId . ':' . $this->metricId],
            $now->modify('+30 minutes')
        );
        self::assertSame(1, $resolved);

        $reopened = $this->repository->recordCandidate(
            $this->serverId,
            $this->metricId,
            $candidate,
            $now->modify('+35 minutes')
        );
        self::assertSame('active', $reopened['status']);
        self::assertSame(2, $reopened['notification_cycle']);
        self::assertTrue($reopened['should_notify']);
    }

    public function testSeenKeyIsScopedByServer(): void
    {
        $second = self::$pdo?->prepare(
            "INSERT INTO servers (name, is_active) VALUES ('observation-test-2', TRUE) RETURNING id"
        );
        $second?->execute();
        $secondId = (int) $second?->fetchColumn();
        $now = new DateTimeImmutable('2026-09-16T00:00:00Z');
        $candidate = $this->anomaly('level_shift_v1:cpu_load:p0:b20');
        $this->repository->recordCandidate($this->serverId, $this->metricId, $candidate, $now);
        $this->repository->recordCandidate($secondId, $this->metricId, $candidate, $now);

        $resolved = $this->repository->resolveMissing(
            [$this->serverId . ':' . $candidate['fingerprint']],
            [
                $this->serverId . ':' . $this->metricId,
                $secondId . ':' . $this->metricId,
            ],
            $now->modify('+30 minutes')
        );
        self::assertSame(1, $resolved);
        self::assertCount(1, $this->repository->active());
    }


    public function testObservationDoesNotResolveAcrossAnAnalysisGap(): void
    {
        $now = new DateTimeImmutable('2026-09-16T00:00:00Z');
        $candidate = $this->prediction('disk_growth_v1:disk_used_root');
        $created = $this->repository->recordCandidate(
            $this->serverId,
            $this->metricId,
            $candidate,
            $now
        );
        $this->repository->markNotified($created['id'], 1, $now);
        $this->repository->handlePrediction($created['id'], null, 'operator');

        self::assertSame(0, $this->repository->resolveMissing(
            [],
            [],
            $now->modify('+2 hours')
        ));
        self::assertSame('handled', (string) self::$pdo?->query(
            'SELECT status FROM observations WHERE id = ' . $created['id']
        )->fetchColumn());
    }

    public function testMaintenanceDoesNotResolveHandledPrediction(): void
    {
        $now = new DateTimeImmutable('2026-09-16T00:00:00Z');
        $candidate = $this->prediction('disk_growth_v1:disk_used_root');
        $created = $this->repository->recordCandidate(
            $this->serverId,
            $this->metricId,
            $candidate,
            $now
        );
        $this->repository->handlePrediction($created['id'], null, 'operator');
        self::$pdo?->prepare(
            "INSERT INTO maintenance_windows (server_id, starts_at, ends_at)
             VALUES (:server_id, :starts_at, :ends_at)"
        )->execute([
            'server_id' => $this->serverId,
            'starts_at' => $now->modify('+10 minutes')->format(DATE_ATOM),
            'ends_at' => $now->modify('+2 hours')->format(DATE_ATOM),
        ]);

        self::assertSame(0, $this->repository->resolveMissing(
            [],
            [$this->serverId . ':' . $this->metricId],
            $now->modify('+30 minutes')
        ));
        self::assertSame('handled', (string) self::$pdo?->query(
            'SELECT status FROM observations WHERE id = ' . $created['id']
        )->fetchColumn());
    }

    public function testDiskAliasObservationResolvesImmediatelyWithoutTouchingCanonical(): void
    {
        $rootMetricId = $this->insertMetric('disk_used_root_hotfix');
        $aliasMetricId = $this->insertMetric('disk_used_tmp_hotfix');
        $now = new DateTimeImmutable('2026-09-16T00:00:00Z');
        $root = $this->repository->recordCandidate(
            $this->serverId,
            $rootMetricId,
            $this->prediction('disk_growth_v1:disk_used_root_hotfix'),
            $now
        );
        $alias = $this->repository->recordCandidate(
            $this->serverId,
            $aliasMetricId,
            $this->prediction('disk_growth_v1:disk_used_tmp_hotfix'),
            $now
        );

        self::assertSame(1, $this->repository->resolveDiskAliases(
            [$this->serverId . ':' . $aliasMetricId],
            $now->modify('+5 minutes')
        ));
        self::assertSame('active', $this->observationStatus($root['id']));
        self::assertSame('resolved', $this->observationStatus($alias['id']));
    }

    private function insertMetric(string $name): int
    {
        $statement = self::$pdo?->prepare(
            "INSERT INTO metric_names (name, unit, description) VALUES (:name, '%', 'test') RETURNING id"
        );
        $statement?->execute(['name' => $name]);
        return (int) $statement?->fetchColumn();
    }

    private function observationStatus(int $id): string
    {
        return (string) self::$pdo?->query(
            'SELECT status FROM observations WHERE id = ' . $id
        )->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function anomaly(string $fingerprint): array
    {
        return [
            'kind' => 'anomaly',
            'detector' => 'level_shift_v1',
            'fingerprint' => $fingerprint,
            'current_value' => 20.0,
            'baseline_value' => 3.0,
            'confidence' => 0.9,
            'forecast_at' => null,
            'details' => ['metric' => 'cpu_load', 'reason' => 'sustained'],
        ];
    }

    /** @return array<string, mixed> */
    private function prediction(string $fingerprint): array
    {
        return [
            'kind' => 'prediction',
            'detector' => 'disk_growth_v1',
            'fingerprint' => $fingerprint,
            'current_value' => 82.0,
            'baseline_value' => 70.0,
            'confidence' => 0.85,
            'forecast_at' => '2026-09-20T00:00:00+00:00',
            'details' => ['metric' => 'disk_used_root', 'slope_percent_per_day' => 1.5],
        ];
    }
}
