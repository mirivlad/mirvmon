<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\ReliabilityReportRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReliabilityReportRepositoryTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testReportDoesNotPresentSparseChecksAsFullAvailability(): void
    {
        $pdo = self::$pdo;
        self::assertNotNull($pdo);
        $serverId = (int) $pdo->query(
            "INSERT INTO servers(name, created_at) VALUES ('Report server', '2026-09-24T00:00:00Z') RETURNING id"
        )->fetchColumn();
        $siteId = (int) $pdo->query(
            "INSERT INTO websites(name, created_at) VALUES ('Report website', '2026-09-24T00:00:00Z') RETURNING id"
        )->fetchColumn();
        $endpointId = (int) $pdo->query(
            "INSERT INTO website_endpoints(website_id, name, url, is_primary, created_at)
             VALUES ($siteId, 'Main', 'https://example.test/', TRUE, '2026-09-24T00:00:00Z') RETURNING id"
        )->fetchColumn();
        $pdo->exec(
            "INSERT INTO server_availability_events(server_id,state,occurred_at) VALUES
                ($serverId,'online','2026-09-24T00:00:00Z'),
                ($serverId,'offline','2026-09-24T00:20:00Z'),
                ($serverId,'online','2026-09-24T00:30:00Z');
             INSERT INTO ingested_samples(server_id,sample_id,sample_time) VALUES
                ($serverId,'00000000-0000-4000-8000-000000000001','2026-09-24T00:01:00Z');
             INSERT INTO website_check_samples(
                sample_time,website_id,endpoint_id,sample_id,manual,transport_available,assertions_passed,configured_url
             ) VALUES (
                '2026-09-24T00:01:00Z',$siteId,$endpointId,
                '00000000-0000-4000-8000-000000000002',FALSE,TRUE,TRUE,'https://example.test/'
             );
             INSERT INTO alerts(server_id,kind,severity,resolved,created_at,resolved_at)
             VALUES ($serverId,'offline','critical',TRUE,'2026-09-24T00:20:00Z','2026-09-24T00:30:00Z')"
        );

        $report = (new ReliabilityReportRepository($pdo))->report(
            new DateTimeImmutable('2026-09-24T00:00:00Z'),
            new DateTimeImmutable('2026-09-24T01:00:00Z')
        );
        $server = array_values(array_filter($report['servers'], static fn (array $row): bool => $row['id'] === $serverId))[0];
        $website = array_values(array_filter($report['websites'], static fn (array $row): bool => $row['id'] === $siteId))[0];

        self::assertSame(83.33, $server['availability']);
        self::assertSame(1, $server['incidents']['count']);
        self::assertSame(600, $server['incidents']['mean_recovery_seconds']);
        self::assertFalse($server['reportable']);
        self::assertSame(100.0, $website['availability']);
        self::assertFalse($website['reportable']);
        self::assertSame(1.67, $website['coverage']['coverage_percent']);
    }
}
