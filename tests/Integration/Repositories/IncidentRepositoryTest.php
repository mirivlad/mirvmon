<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\IncidentRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class IncidentRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private IncidentRepository $repository;
    private int $groupId;
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
        $this->repository = new IncidentRepository(self::$pdo);
        $this->groupId = (int) self::$pdo?->query(
            "INSERT INTO server_groups (name) VALUES ('Production') RETURNING id"
        )->fetchColumn();
        $this->serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name, group_id) VALUES ('edge-01', {$this->groupId}) RETURNING id"
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testActiveReturnsUnresolvedIncidentWithGroupAndDuration(): void
    {
        self::$pdo?->exec(
            "INSERT INTO alerts (server_id, metric_id, kind, value, severity, created_at)
             SELECT {$this->serverId}, id, 'metric', 92.5, 'critical', CURRENT_TIMESTAMP - INTERVAL '10 minutes'
             FROM metric_names WHERE name = 'cpu_load'"
        );

        $rows = $this->repository->active([
            'server_id' => $this->serverId,
            'group_id' => $this->groupId,
            'kind' => 'metric',
            'severity' => 'critical',
        ]);

        self::assertCount(1, $rows);
        self::assertSame('edge-01', $rows[0]['server_name']);
        self::assertSame('Production', $rows[0]['group_name']);
        self::assertSame('cpu_load', $rows[0]['subject_name']);
        self::assertGreaterThanOrEqual(590, (int) $rows[0]['duration_seconds']);
    }

    public function testHistoryCombinesResolvedAlertsAndCompletedOutagesWithoutOfflineAlertDuplication(): void
    {
        self::$pdo?->exec(
            "INSERT INTO alerts (
                server_id, kind, subject, severity, resolved,
                created_at, resolved_at, resolved_by_username
             ) VALUES (
                {$this->serverId}, 'service', 'nginx', 'warning', TRUE,
                CURRENT_TIMESTAMP - INTERVAL '30 minutes',
                CURRENT_TIMESTAMP - INTERVAL '20 minutes',
                'operator'
             )"
        );
        self::$pdo?->exec(
            "INSERT INTO alerts (
                server_id, kind, subject, severity, resolved, created_at, resolved_at
             ) VALUES (
                {$this->serverId}, 'offline', 'agent', 'critical', TRUE,
                CURRENT_TIMESTAMP - INTERVAL '15 minutes',
                CURRENT_TIMESTAMP - INTERVAL '10 minutes'
             )"
        );
        self::$pdo?->exec(
            "INSERT INTO server_availability_events (server_id, state, occurred_at) VALUES
                ({$this->serverId}, 'offline', CURRENT_TIMESTAMP - INTERVAL '15 minutes'),
                ({$this->serverId}, 'online', CURRENT_TIMESTAMP - INTERVAL '10 minutes')"
        );

        $rows = $this->repository->history(['server_id' => $this->serverId]);

        self::assertCount(2, $rows);
        $byKind = [];
        foreach ($rows as $row) {
            $byKind[(string) $row['kind']] = $row;
        }
        self::assertArrayHasKey('service', $byKind);
        self::assertArrayHasKey('offline', $byKind);
        self::assertSame('operator', $byKind['service']['resolved_by_username']);
        self::assertSame('availability', $byKind['offline']['source']);
        self::assertSame(300, (int) $byKind['offline']['duration_seconds']);
    }

    public function testAttentionIncludesOfflineServerEvenWithoutOfflineNotifications(): void
    {
        self::$pdo?->exec(
            "INSERT INTO server_availability_state (server_id, state, changed_at)
             VALUES ({$this->serverId}, 'offline', CURRENT_TIMESTAMP - INTERVAL '5 minutes')"
        );

        $rows = $this->repository->attention();

        self::assertCount(1, $rows);
        self::assertSame('offline', $rows[0]['kind']);
        self::assertSame('critical', $rows[0]['severity']);
        self::assertSame('edge-01', $rows[0]['server_name']);
    }
}
