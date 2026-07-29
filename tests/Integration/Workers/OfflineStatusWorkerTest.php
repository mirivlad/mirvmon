<?php

declare(strict_types=1);

namespace Tests\Integration\Workers;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\NotificationOutboxRepository;
use App\Workers\OfflineStatusWorker;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class OfflineStatusWorkerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private int $serverId;
    private OfflineStatusWorker $worker;

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
        $statement = self::$pdo?->query(
            "INSERT INTO servers (
                name,
                last_metrics_at,
                offline_timeout_seconds,
                notify_on_offline
             ) VALUES (
                'offline-server',
                '2026-07-30 11:50:00+00',
                60,
                TRUE
             )
             RETURNING id"
        );
        $this->serverId = (int) $statement?->fetchColumn();
        self::$pdo?->exec(
            "UPDATE notification_settings
             SET telegram_enabled = TRUE, telegram_chat_id = '123'"
        );
        $this->worker = new OfflineStatusWorker(
            self::$pdo,
            new NotificationOutboxRepository(self::$pdo)
        );
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testOfflineAndRecoveryTransitionsAreIdempotent(): void
    {
        $now = new DateTimeImmutable('2026-07-30T12:00:00Z');

        self::assertSame(1, $this->worker->runOnce($now));
        self::assertSame(0, $this->worker->runOnce($now));
        self::assertSame(1, $this->tableCount('alerts'));
        self::assertSame(1, $this->tableCount('notification_outbox'));

        $statement = self::$pdo?->prepare(
            'UPDATE servers SET last_metrics_at = :last_metrics_at WHERE id = :id'
        );
        $statement?->execute([
            'id' => $this->serverId,
            'last_metrics_at' => '2026-07-30 11:59:30+00',
        ]);

        self::assertSame(1, $this->worker->runOnce($now));
        self::assertSame(
            'true',
            self::$pdo?->query(
                "SELECT resolved::text FROM alerts WHERE kind = 'offline'"
            )->fetchColumn()
        );
        self::assertSame(2, $this->tableCount('notification_outbox'));
    }

    private function tableCount(string $table): int
    {
        return (int) self::$pdo?->query('SELECT count(*) FROM ' . $table)->fetchColumn();
    }
}
