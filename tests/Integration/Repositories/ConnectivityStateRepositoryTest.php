<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\AppSettingsRepository;
use App\Repositories\ConnectivityStateRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConnectivityStateRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private ConnectivityStateRepository $repository;

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
        self::$pdo?->prepare('DELETE FROM app_settings WHERE setting_key = :key')
            ->execute(['key' => ConnectivityStateRepository::SETTING_KEY]);
        $this->repository = new ConnectivityStateRepository(new AppSettingsRepository(self::$pdo));
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testStateTransitionKeepsChangedAtUntilStateActuallyChanges(): void
    {
        $online = [
            'available' => true,
            'successes' => 2,
            'failures' => 0,
            'successful_targets' => ['one:443', 'two:443'],
            'failed_targets' => [],
        ];
        $offline = [
            'available' => false,
            'successes' => 0,
            'failures' => 2,
            'successful_targets' => [],
            'failed_targets' => ['one:443', 'two:443'],
        ];

        $this->repository->record($online, new DateTimeImmutable('2026-09-05T01:00:00Z'));
        $first = $this->repository->current();
        self::assertNotNull($first);
        self::assertSame('2026-09-05T01:00:00+00:00', $first['changed_at']);

        $this->repository->record($online, new DateTimeImmutable('2026-09-05T01:00:15Z'));
        $second = $this->repository->current();
        self::assertNotNull($second);
        self::assertSame($first['changed_at'], $second['changed_at']);
        self::assertSame('2026-09-05T01:00:15+00:00', $second['checked_at']);

        $this->repository->record($offline, new DateTimeImmutable('2026-09-05T01:00:30Z'));
        $third = $this->repository->current();
        self::assertNotNull($third);
        self::assertSame('offline', $third['state']);
        self::assertSame('2026-09-05T01:00:30+00:00', $third['changed_at']);
        self::assertTrue($this->repository->offlineAndFresh(
            new DateTimeImmutable('2026-09-05T01:00:45Z'),
            60,
        ));
        self::assertFalse($this->repository->offlineAndFresh(
            new DateTimeImmutable('2026-09-05T01:02:00Z'),
            60,
        ));
    }
}
