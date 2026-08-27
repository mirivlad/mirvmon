<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\MaintenanceWindowRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\NotificationSettingsRepository;
use App\Security\SecretCipher;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class MaintenanceWindowRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private MaintenanceWindowRepository $repository;
    private int $serverId;
    private int $alertId;

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
        $this->repository = new MaintenanceWindowRepository(self::$pdo);
        (new NotificationSettingsRepository(
            self::$pdo,
            new SecretCipher(str_repeat('m', 32))
        ))->save([
            'telegram_enabled' => 'on',
            'telegram_bot_token' => '123:token',
            'telegram_chat_id' => '-100',
            'notify_on_critical' => 'on',
        ]);
        $this->serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('maintained') RETURNING id"
        )->fetchColumn();
        $this->alertId = (int) self::$pdo?->query(
            "INSERT INTO alerts (server_id, kind, subject, severity)
             VALUES ({$this->serverId}, 'metric', 'cpu_load', 'critical')
             RETURNING id"
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testAnOpenWindowSuppressesDeliveryButKeepsTheAlert(): void
    {
        $outbox = new NotificationOutboxRepository(self::$pdo);
        $this->repository->start($this->serverId, 3600, 'Плановый перезапуск', 'operator');

        self::assertSame(0, $this->enqueue($outbox, 'during'));
        self::assertSame(0, (int) self::$pdo?->query(
            'SELECT count(*) FROM notification_outbox'
        )->fetchColumn());
        self::assertSame(1, (int) self::$pdo?->query(
            'SELECT count(*) FROM alerts'
        )->fetchColumn());

        $active = $this->repository->active($this->serverId);
        self::assertIsArray($active);
        self::assertSame('Плановый перезапуск', $active['reason']);
        self::assertSame('operator', $active['created_by']);
    }

    public function testDeliveryResumesAfterTheWindowIsCancelled(): void
    {
        $outbox = new NotificationOutboxRepository(self::$pdo);
        $this->repository->start($this->serverId, 3600, null, null);
        self::assertSame(0, $this->enqueue($outbox, 'during'));

        self::assertSame(1, $this->repository->cancel($this->serverId));
        self::assertNull($this->repository->active($this->serverId));
        self::assertSame(1, $this->enqueue($outbox, 'after'));
    }

    public function testAnotherServerKeepsItsNotifications(): void
    {
        $outbox = new NotificationOutboxRepository(self::$pdo);
        $other = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('untouched') RETURNING id"
        )->fetchColumn();
        $this->repository->start($this->serverId, 3600, null, null);

        self::assertSame(1, $outbox->enqueueConfigured(
            $other,
            $this->alertId,
            'metric_triggered',
            ['severity' => 'critical', 'server_id' => $other],
            'other-server'
        ));
        self::assertSame([$this->serverId], $this->repository->activeServerIds());
    }

    public function testAnAbsurdDurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository->start($this->serverId, 5, null, null);
    }

    public function testWebsiteMaintenanceUsesTheSameWindowRules(): void
    {
        $websiteId = (int) self::$pdo?->query(
            "INSERT INTO websites (name) VALUES ('Portal') RETURNING id"
        )->fetchColumn();

        $this->repository->startWebsite($websiteId, 3600, 'Deploy', 'operator');

        self::assertSame('Deploy', $this->repository->activeWebsite($websiteId)['reason']);
        self::assertSame(1, $this->repository->cancelWebsite($websiteId));
        self::assertNull($this->repository->activeWebsite($websiteId));
    }

    private function enqueue(NotificationOutboxRepository $outbox, string $key): int
    {
        return $outbox->enqueueConfigured(
            $this->serverId,
            $this->alertId,
            'metric_triggered',
            ['severity' => 'critical', 'server_id' => $this->serverId],
            $key
        );
    }
}
