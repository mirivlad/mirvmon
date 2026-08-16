<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\AppSettingsRepository;
use App\Services\AuditRetentionService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuditRetentionServiceTest extends TestCase
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
        self::$pdo?->prepare('DELETE FROM app_settings WHERE setting_key = :key')->execute([
            'key' => AuditRetentionService::SETTING_KEY,
        ]);
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testDefaultPolicyKeepsAuditHistoryForever(): void
    {
        $service = $this->service();
        $this->insertAudit('retention.keep', '-400 days');

        self::assertSame(0, $service->retentionDays());
        self::assertSame(0, $service->pruneExpired(new DateTimeImmutable('2026-08-16T00:00:00+00:00')));
        self::assertSame('1', (string) self::$pdo?->query(
            "SELECT count(*) FROM audit_log WHERE action = 'retention.keep'"
        )->fetchColumn());
    }

    public function testFinitePolicyPrunesOnlyRowsOlderThanCutoff(): void
    {
        $service = $this->service();
        $service->saveRetentionDays(30);
        $this->insertAuditAt('retention.old', '2026-07-01T00:00:00+00:00');
        $this->insertAuditAt('retention.recent', '2026-08-10T00:00:00+00:00');

        $deleted = $service->pruneExpired(new DateTimeImmutable('2026-08-16T00:00:00+00:00'));

        self::assertSame(1, $deleted);
        self::assertSame('0', (string) self::$pdo?->query(
            "SELECT count(*) FROM audit_log WHERE action = 'retention.old'"
        )->fetchColumn());
        self::assertSame('1', (string) self::$pdo?->query(
            "SELECT count(*) FROM audit_log WHERE action = 'retention.recent'"
        )->fetchColumn());
    }

    public function testOrdinaryDeleteRemainsRejectedAfterRetentionMigration(): void
    {
        $this->insertAudit('retention.direct-delete', '-40 days');
        $this->expectException(\PDOException::class);
        self::$pdo?->exec("DELETE FROM audit_log WHERE action = 'retention.direct-delete'");
    }

    private function service(): AuditRetentionService
    {
        return new AuditRetentionService(self::$pdo, new AppSettingsRepository(self::$pdo));
    }

    private function insertAudit(string $action, string $relative): void
    {
        $date = (new DateTimeImmutable('2026-08-16T00:00:00+00:00'))->modify($relative);
        $this->insertAuditAt($action, $date->format(DATE_ATOM));
    }

    private function insertAuditAt(string $action, string $occurredAt): void
    {
        $statement = self::$pdo?->prepare(
            'INSERT INTO audit_log (occurred_at, actor_username, actor_role, action, object_type, description)
             VALUES (CAST(:occurred_at AS timestamptz), :actor, :role, :action, :object_type, :description)'
        );
        $statement?->execute([
            'occurred_at' => $occurredAt,
            'actor' => 'retention-test',
            'role' => 'admin',
            'action' => $action,
            'object_type' => 'test',
            'description' => 'retention test',
        ]);
    }
}
