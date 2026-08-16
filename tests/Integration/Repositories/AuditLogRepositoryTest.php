<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\AuditLogRepository;
use App\Services\AuditLogger;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class AuditLogRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private array $session = [];

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
        $this->session = $_SESSION ?? [];
        $_SESSION = [
            'user_id' => 42,
            'username' => 'audit-admin',
            'role' => 'admin',
        ];
        self::$pdo?->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
        $_SESSION = $this->session;
    }

    public function testAppendAndFilteringKeepActorSnapshotAndRedactSecrets(): void
    {
        $repository = new AuditLogRepository(self::$pdo);
        $logger = new AuditLogger($repository);
        $id = $logger->record(
            'server.update',
            'server',
            17,
            'test-server',
            'Server settings changed.',
            [
                'changed_fields' => ['name', 'notification_telegram_chat_id'],
                'token' => 'must-not-be-stored',
            ]
        );

        $page = $repository->page($repository->filters([
            'actor' => 'audit-admin',
            'action' => 'server.update',
            'object_type' => 'server',
            'object_id' => '17',
        ]), 1);

        self::assertSame(1, $page['total']);
        self::assertSame($id, (int) $page['items'][0]['id']);
        self::assertSame('audit-admin', $page['items'][0]['actor_username']);
        self::assertSame('admin', $page['items'][0]['actor_role']);
        self::assertSame('test-server', $page['items'][0]['object_label']);
        self::assertStringNotContainsString(
            'must-not-be-stored',
            (string) $page['items'][0]['metadata']
        );
        self::assertStringContainsString('[redacted]', (string) $page['items'][0]['metadata']);
    }

    public function testAuditRowsCannotBeUpdated(): void
    {
        $repository = new AuditLogRepository(self::$pdo);
        $id = $repository->append(
            42,
            'audit-admin',
            'admin',
            'group.create',
            'group',
            '7',
            'group',
            'Group created.'
        );

        $this->expectException(PDOException::class);
        self::$pdo?->exec("UPDATE audit_log SET description = 'changed' WHERE id = {$id}");
    }

    public function testAuditRowsCannotBeDeleted(): void
    {
        $repository = new AuditLogRepository(self::$pdo);
        $id = $repository->append(
            42,
            'audit-admin',
            'admin',
            'group.delete',
            'group',
            '7',
            'group',
            'Group deleted.'
        );

        $this->expectException(PDOException::class);
        self::$pdo?->exec("DELETE FROM audit_log WHERE id = {$id}");
    }
}
