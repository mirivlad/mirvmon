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
                '2026-07-30 11:59:50+00',
                60,
                TRUE
             )
             RETURNING id"
        );
        $this->serverId = (int) $statement?->fetchColumn();
        self::$pdo?->prepare(
            "INSERT INTO agent_tokens (server_id, token_hash, last_used_at)
             VALUES (:server_id, :token_hash, '2026-07-30 11:50:00+00')"
        )->execute([
            'server_id' => $this->serverId,
            'token_hash' => hash('sha256', str_repeat('c', 64)),
        ]);
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
        self::assertSame(1, $this->availabilityEventCount());
        self::assertSame('offline', $this->availabilityState());

        $statement = self::$pdo?->prepare(
            'UPDATE agent_tokens SET last_used_at = :last_used_at WHERE server_id = :id'
        );
        $statement?->execute([
            'id' => $this->serverId,
            'last_used_at' => '2026-07-30 11:59:30+00',
        ]);

        self::assertSame(1, $this->worker->runOnce($now));
        self::assertSame(
            'true',
            self::$pdo?->query(
                "SELECT resolved::text FROM alerts WHERE kind = 'offline'"
            )->fetchColumn()
        );
        self::assertSame(2, $this->tableCount('notification_outbox'));
        self::assertSame(2, $this->availabilityEventCount());
        self::assertSame('online', $this->availabilityState());
    }

    public function testBlindMonitoringWindowDoesNotCreateFalseOfflineIncident(): void
    {
        $resumedAt = new DateTimeImmutable('2026-07-30T12:00:00Z');
        $this->worker->beginObservationRecovery($resumedAt);

        self::assertSame(0, $this->worker->runOnce($resumedAt));
        self::assertSame(0, $this->tableCount('alerts'));
        self::assertSame(0, $this->tableCount('notification_outbox'));
        self::assertSame(0, $this->availabilityEventCount());

        self::$pdo?->prepare(
            'UPDATE agent_tokens SET last_used_at = :last_used_at WHERE server_id = :id'
        )->execute([
            'id' => $this->serverId,
            'last_used_at' => '2026-07-30 12:00:20+00',
        ]);

        self::assertSame(0, $this->worker->runOnce(
            new DateTimeImmutable('2026-07-30T12:00:20Z')
        ));
        self::assertSame(0, $this->tableCount('alerts'));
        self::assertSame(0, $this->tableCount('notification_outbox'));
        self::assertSame('online', $this->availabilityState());
    }

    public function testServerStillSilentAfterRecoveryGraceBecomesOffline(): void
    {
        $resumedAt = new DateTimeImmutable('2026-07-30T12:00:00Z');
        $this->worker->beginObservationRecovery($resumedAt);

        self::assertSame(0, $this->worker->runOnce($resumedAt));
        self::assertSame(1, $this->worker->runOnce(
            new DateTimeImmutable('2026-07-30T12:01:01Z')
        ));
        self::assertSame(1, $this->tableCount('alerts'));
        self::assertSame(1, $this->tableCount('notification_outbox'));
        self::assertSame('offline', $this->availabilityState());
    }

    public function testUntrustedExternalConnectivitySuppressesNewOfflineAssertion(): void
    {
        $now = new DateTimeImmutable('2026-07-30T12:00:00Z');

        self::assertSame(0, $this->worker->runOnce($now, false));
        self::assertSame(0, $this->tableCount('alerts'));
        self::assertSame(0, $this->tableCount('notification_outbox'));
        self::assertSame(0, $this->availabilityEventCount());

        self::$pdo?->prepare(
            'UPDATE agent_tokens SET last_used_at = :last_used_at WHERE server_id = :id'
        )->execute([
            'id' => $this->serverId,
            'last_used_at' => '2026-07-30 11:59:45+00',
        ]);

        self::assertSame(0, $this->worker->runOnce($now, false));
        self::assertSame('online', $this->availabilityState());
        self::assertSame(1, $this->availabilityEventCount());
    }

    public function testFreshMetricTimestampCannotMaskStaleAgentContact(): void
    {
        $now = new DateTimeImmutable('2026-07-30T12:00:00Z');

        self::$pdo?->prepare(
            'UPDATE servers SET last_metrics_at = :last_metrics_at WHERE id = :id'
        )->execute([
            'id' => $this->serverId,
            'last_metrics_at' => '2026-07-30 12:10:00+00',
        ]);

        self::assertSame(1, $this->worker->runOnce($now));
        self::assertSame(1, $this->tableCount('alerts'));
        self::assertSame('offline', $this->availabilityState());
    }

    public function testAvailabilityIsRecordedWhenOfflineNotificationsAreDisabled(): void
    {
        self::$pdo?->prepare(
            'UPDATE servers SET notify_on_offline = FALSE WHERE id = :id'
        )->execute(['id' => $this->serverId]);

        self::assertSame(0, $this->worker->runOnce(new DateTimeImmutable('2026-07-30T12:00:00Z')));
        self::assertSame(0, $this->tableCount('alerts'));
        self::assertSame(0, $this->tableCount('notification_outbox'));
        self::assertSame('offline', $this->availabilityState());
        self::assertSame(1, $this->availabilityEventCount());
    }

    public function testZeroOfflineTimeoutKeepsContactOnline(): void
    {
        self::$pdo?->prepare(
            'UPDATE servers SET offline_timeout_seconds = 0 WHERE id = :id'
        )->execute(['id' => $this->serverId]);

        self::assertSame(0, $this->worker->runOnce(new DateTimeImmutable('2026-07-30T12:00:00Z')));
        self::assertSame(0, $this->tableCount('alerts'));
        self::assertSame('online', $this->availabilityState());
    }

    private function availabilityState(): string
    {
        $statement = self::$pdo?->prepare(
            'SELECT state FROM server_availability_state WHERE server_id = :server_id'
        );
        $statement?->execute(['server_id' => $this->serverId]);

        return (string) $statement?->fetchColumn();
    }

    private function availabilityEventCount(): int
    {
        $statement = self::$pdo?->prepare(
            'SELECT count(*) FROM server_availability_events WHERE server_id = :server_id'
        );
        $statement?->execute(['server_id' => $this->serverId]);

        return (int) $statement?->fetchColumn();
    }

    private function tableCount(string $table): int
    {
        return (int) self::$pdo?->query('SELECT count(*) FROM ' . $table)->fetchColumn();
    }
}
