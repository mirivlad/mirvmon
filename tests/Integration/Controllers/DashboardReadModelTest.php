<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\DashboardController;
use App\Controllers\ServerDetailController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\MaintenanceWindowRepository;
use App\Repositories\MetricRepository;
use App\Repositories\ServerRepository;
use App\Services\ServerStatusService;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class DashboardReadModelTest extends TestCase
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
        $server = self::$pdo?->query(
            <<<'SQL'
            INSERT INTO servers (
                name,
                display_metrics,
                last_metrics_at,
                offline_timeout_seconds
            )
            VALUES (
                'read-model-server',
                '["cpu_load"]'::jsonb,
                CURRENT_TIMESTAMP,
                300
            )
            RETURNING id
            SQL
        );
        $this->serverId = (int) $server?->fetchColumn();

        $metricId = (int) self::$pdo?->query(
            "SELECT id FROM metric_names WHERE name = 'cpu_load'"
        )->fetchColumn();
        $sample = self::$pdo?->prepare(
            <<<'SQL'
            INSERT INTO metric_samples
                (sample_time, server_id, metric_id, sample_id, value)
            VALUES
                (CURRENT_TIMESTAMP - INTERVAL '2 minutes', :server_id, :metric_id,
                 '10000000-0000-4000-8000-000000000001', 10),
                (CURRENT_TIMESTAMP - INTERVAL '1 minute', :server_id, :metric_id,
                 '10000000-0000-4000-8000-000000000002', 40)
            SQL
        );
        $sample?->execute(['server_id' => $this->serverId, 'metric_id' => $metricId]);
        self::$pdo?->prepare(
            <<<'SQL'
            INSERT INTO current_metric_values
                (server_id, metric_id, sample_time, sample_id, value)
            VALUES (
                :server_id,
                :metric_id,
                CURRENT_TIMESTAMP - INTERVAL '1 minute',
                '10000000-0000-4000-8000-000000000002',
                40
            )
            SQL
        )->execute(['server_id' => $this->serverId, 'metric_id' => $metricId]);
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testDashboardAndDetailRenderTheNewestValue(): void
    {
        $twig = Twig::create(dirname(__DIR__, 3) . '/templates');
        $servers = new ServerRepository(self::$pdo);
        $metrics = new MetricRepository(self::$pdo);
        $responseFactory = new ResponseFactory();
        $requestFactory = new ServerRequestFactory();

        $dashboard = (new DashboardController(
            $twig,
            $servers,
            new ServerStatusService()
        ))->index(
            $requestFactory->createServerRequest('GET', 'http://localhost/'),
            $responseFactory->createResponse(),
            []
        );
        $dashboardHtml = (string) $dashboard->getBody();

        self::assertStringContainsString('read-model-server', $dashboardHtml);
        self::assertMatchesRegularExpression(
            '/id="cpu-val-\d+">\s*40%\s*<\/dd>/',
            $dashboardHtml
        );

        $detail = (new ServerDetailController(
            $twig,
            $servers,
            $metrics,
            new MaintenanceWindowRepository(self::$pdo)
        ))->show(
            $requestFactory->createServerRequest(
                'GET',
                'http://localhost/servers/' . $this->serverId . '?period=24h'
            ),
            $responseFactory->createResponse(),
            ['id' => (string) $this->serverId]
        );
        $detailHtml = (string) $detail->getBody();

        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString('CPU сейчас', $detailHtml);
        self::assertStringContainsString('40%', $detailHtml);
    }
}
