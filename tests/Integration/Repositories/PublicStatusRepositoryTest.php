<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\PublicStatusRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PublicStatusRepositoryTest extends TestCase
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

    public function testOnlyExplicitlyPublishedNamesAndStatusesAreReturned(): void
    {
        $pdo = self::$pdo;
        self::assertNotNull($pdo);
        $visible = (int) $pdo->query(
            "INSERT INTO servers(name,address,last_metrics_at) VALUES
                ('Private host name','192.0.2.12','2026-09-24T00:59:00Z') RETURNING id"
        )->fetchColumn();
        $pdo->exec("INSERT INTO servers(name,address) VALUES ('Unpublished host','192.0.2.13')");
        $pdo->exec("INSERT INTO server_availability_state(server_id,state) VALUES ($visible,'online')");
        $website = (int) $pdo->query(
            "INSERT INTO websites(name) VALUES ('Private site name') RETURNING id"
        )->fetchColumn();
        $endpoint = (int) $pdo->query(
            "INSERT INTO website_endpoints(website_id,name,url,is_primary)
             VALUES ($website,'Main','https://private.example.test/',TRUE) RETURNING id"
        )->fetchColumn();
        $pdo->exec(
            "INSERT INTO website_state(website_id,primary_endpoint_id,status)
             VALUES ($website,$endpoint,'healthy');
             INSERT INTO website_endpoint_state(endpoint_id,website_id,last_sample_at)
             VALUES ($endpoint,$website,'2026-09-24T00:58:00Z')"
        );

        $repository = new PublicStatusRepository($pdo);
        self::assertSame([], $repository->published(new DateTimeImmutable('2026-09-24T01:00:00Z')));
        $repository->replace([
            'server' => [$visible => ['enabled' => '1', 'display_name' => 'Public API']],
            'website' => [$website => ['enabled' => '1', 'display_name' => 'Website']],
        ]);

        self::assertSame([
            ['display_name' => 'Public API', 'type' => 'server', 'status' => 'operational'],
            ['display_name' => 'Website', 'type' => 'website', 'status' => 'operational'],
        ], $repository->published(new DateTimeImmutable('2026-09-24T01:00:00Z')));
        self::assertSame('unknown', $repository->published(new DateTimeImmutable('2026-09-24T02:00:00Z'))[0]['status']);
    }

    public function testInvalidReplacementKeepsPreviousSelection(): void
    {
        $pdo = self::$pdo;
        self::assertNotNull($pdo);
        $id = (int) $pdo->query("INSERT INTO servers(name) VALUES ('Host') RETURNING id")->fetchColumn();
        $repository = new PublicStatusRepository($pdo);
        $repository->replace(['server' => [$id => ['enabled' => '1', 'display_name' => 'Host']]]);

        try {
            $repository->replace(['server' => [999999 => ['enabled' => '1', 'display_name' => 'Invalid']]]);
            self::fail('Expected invalid selection.');
        } catch (InvalidArgumentException) {
            self::assertSame(1, (int) $pdo->query('SELECT count(*) FROM public_status_items')->fetchColumn());
        }
    }
}
