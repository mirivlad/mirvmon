<?php

declare(strict_types=1);

namespace Tests\Integration\Workers;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Notifications\NotificationTransportException;
use App\Repositories\NotificationOutboxRepository;
use App\Workers\NotificationWorker;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NotificationWorkerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private int $serverId;

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
        $this->serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('worker-server') RETURNING id"
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testWorkersClaimEachJobOnlyOnceAndMarkItSent(): void
    {
        $firstId = $this->insertJob('first');
        $secondId = $this->insertJob('second');
        $delivered = [];

        $deliver = static function (array $job) use (&$delivered): bool {
            $delivered[] = $job['id'];

            return true;
        };
        $firstWorker = new NotificationWorker(
            new NotificationOutboxRepository(self::$pdo),
            $deliver
        );
        $secondWorker = new NotificationWorker(
            new NotificationOutboxRepository(self::$pdo),
            $deliver
        );

        self::assertSame(1, $firstWorker->runOnce(1));
        self::assertSame(1, $secondWorker->runOnce(1));
        sort($delivered);
        self::assertSame([$firstId, $secondId], $delivered);
        self::assertSame(
            ['sent', 'sent'],
            self::$pdo?->query(
                'SELECT status FROM notification_outbox ORDER BY id'
            )->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function testFailureIsRetriedWithoutPersistingSecretExceptionMessage(): void
    {
        $jobId = $this->insertJob('failure');
        $worker = new NotificationWorker(
            new NotificationOutboxRepository(self::$pdo),
            static function (): never {
                throw new RuntimeException('telegram token SUPER-SECRET');
            }
        );

        self::assertSame(1, $worker->runOnce());
        $statement = self::$pdo?->prepare(
            'SELECT status, attempts, last_error, available_at > created_at AS delayed
             FROM notification_outbox
             WHERE id = :id'
        );
        $statement?->execute(['id' => $jobId]);
        $job = $statement?->fetch();

        self::assertSame('failed', $job['status']);
        self::assertSame(1, (int) $job['attempts']);
        self::assertSame('delivery_failed', $job['last_error']);
        self::assertTrue($this->toBool($job['delayed']));
        self::assertStringNotContainsString('SUPER-SECRET', (string) $job['last_error']);
    }

    public function testTransportErrorIsVisibleToTheAdministrator(): void
    {
        $jobId = $this->insertJob('transport-error');
        $worker = new NotificationWorker(
            new NotificationOutboxRepository(self::$pdo),
            static function (): never {
                throw new NotificationTransportException(
                    "telegram_http_400: chat not found\n<script>"
                );
            }
        );

        self::assertSame(1, $worker->runOnce());
        $statement = self::$pdo?->prepare(
            'SELECT status, last_error FROM notification_outbox WHERE id = :id'
        );
        $statement?->execute(['id' => $jobId]);
        $job = $statement?->fetch();

        self::assertSame('failed', $job['status']);
        self::assertSame('telegram_http_400: chat not found  script', $job['last_error']);
    }

    public function testDisabledChannelIsNeverReportedAsSent(): void
    {
        $jobId = $this->insertJob('disabled-channel');
        $worker = new NotificationWorker(
            new NotificationOutboxRepository(self::$pdo),
            static fn (): bool => false
        );

        self::assertSame(1, $worker->runOnce());
        $statement = self::$pdo?->prepare(
            'SELECT status, last_error, sent_at FROM notification_outbox WHERE id = :id'
        );
        $statement?->execute(['id' => $jobId]);
        $job = $statement?->fetch();

        self::assertSame('dead', $job['status']);
        self::assertSame('channel_disabled', $job['last_error']);
        self::assertNull($job['sent_at']);
    }

    public function testRetryBudgetSurvivesTheSecondAttempt(): void
    {
        $jobId = $this->insertJob('second-attempt');
        $worker = new NotificationWorker(
            new NotificationOutboxRepository(self::$pdo),
            static function (): never {
                throw new NotificationTransportException('telegram_network_failed');
            }
        );

        $worker->runOnce();
        self::$pdo?->exec(
            'UPDATE notification_outbox SET available_at = CURRENT_TIMESTAMP'
        );
        $worker->runOnce();

        $statement = self::$pdo?->prepare(
            'SELECT status, attempts FROM notification_outbox WHERE id = :id'
        );
        $statement?->execute(['id' => $jobId]);
        $job = $statement?->fetch();

        self::assertSame('failed', $job['status']);
        self::assertSame(2, (int) $job['attempts']);
    }

    public function testRetryBudgetMovesAJobToDeadLetterState(): void
    {
        $jobId = $this->insertJob('dead-letter');
        $statement = self::$pdo?->prepare(
            'UPDATE notification_outbox SET attempts = 9 WHERE id = :id'
        );
        $statement?->execute(['id' => $jobId]);
        $worker = new NotificationWorker(
            new NotificationOutboxRepository(self::$pdo),
            static function (): never {
                throw new RuntimeException('still unavailable');
            }
        );

        self::assertSame(1, $worker->runOnce());
        $statement = self::$pdo?->prepare(
            'SELECT status, attempts FROM notification_outbox WHERE id = :id'
        );
        $statement?->execute(['id' => $jobId]);
        self::assertSame(
            ['dead', 10],
            array_map(
                static fn (mixed $value): mixed => is_numeric($value)
                    ? (int) $value
                    : $value,
                $statement?->fetch(PDO::FETCH_NUM)
            )
        );
        self::assertSame(0, $worker->runOnce());
    }

    private function insertJob(string $key): int
    {
        $statement = self::$pdo?->prepare(
            "INSERT INTO notification_outbox (
                server_id,
                channel,
                event_type,
                payload,
                deduplication_key
             ) VALUES (
                :server_id,
                'telegram',
                'test',
                '{}'::jsonb,
                :deduplication_key
             )
             RETURNING id"
        );
        $statement?->execute([
            'server_id' => $this->serverId,
            'deduplication_key' => $key . '-' . $this->serverId,
        ]);

        return (int) $statement?->fetchColumn();
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
