<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\WorkerHeartbeatRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class WorkerHeartbeatRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private WorkerHeartbeatRepository $repository;

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
        $this->repository = new WorkerHeartbeatRepository(self::$pdo);
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testRepeatedTicksUpdateTheSameRowAndKeepTheStartTime(): void
    {
        $this->repository->record(WorkerHeartbeatRepository::NOTIFICATION_WORKER);
        $started = (string) self::$pdo?->query(
            'SELECT started_at FROM worker_heartbeats'
        )->fetchColumn();

        $this->repository->record(WorkerHeartbeatRepository::NOTIFICATION_WORKER);

        self::assertSame(1, (int) self::$pdo?->query(
            'SELECT count(*) FROM worker_heartbeats'
        )->fetchColumn());
        self::assertSame($started, (string) self::$pdo?->query(
            'SELECT started_at FROM worker_heartbeats'
        )->fetchColumn());
    }

    public function testLastTickCanBeReadBeforeAWorkerResumes(): void
    {
        self::assertNull($this->repository->lastTickAt(
            WorkerHeartbeatRepository::OFFLINE_WORKER
        ));

        $this->repository->record(WorkerHeartbeatRepository::OFFLINE_WORKER);

        self::assertNotNull($this->repository->lastTickAt(
            WorkerHeartbeatRepository::OFFLINE_WORKER
        ));
    }

    public function testASilentWorkerIsReportedAsStale(): void
    {
        $this->repository->record(WorkerHeartbeatRepository::NOTIFICATION_WORKER);
        $this->repository->record(WorkerHeartbeatRepository::OFFLINE_WORKER);
        self::$pdo?->prepare(
            "UPDATE worker_heartbeats
             SET last_tick_at = CURRENT_TIMESTAMP - INTERVAL '10 minutes'
             WHERE worker = :worker"
        )->execute(['worker' => WorkerHeartbeatRepository::OFFLINE_WORKER]);

        $heartbeats = $this->repository->all();

        self::assertCount(2, $heartbeats);
        // Ordered by name: notification-worker precedes offline-worker.
        self::assertSame(WorkerHeartbeatRepository::NOTIFICATION_WORKER, $heartbeats[0]['worker']);
        self::assertFalse($heartbeats[0]['stale']);
        self::assertSame(WorkerHeartbeatRepository::OFFLINE_WORKER, $heartbeats[1]['worker']);
        self::assertTrue($heartbeats[1]['stale']);
        self::assertGreaterThanOrEqual(600, $heartbeats[1]['seconds_since_tick']);
    }
}
