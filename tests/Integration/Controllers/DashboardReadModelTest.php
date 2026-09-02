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
use App\Repositories\WebsiteRepository;
use App\Security\SecretCipher;
use App\Services\ServerStatusService;
use App\Services\ServerPlatformService;
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
                offline_timeout_seconds,
                os_version
            )
            VALUES (
                'read-model-server',
                '["cpu_load"]'::jsonb,
                CURRENT_TIMESTAMP,
                300,
                'Debian GNU/Linux 12'
            )
            RETURNING id
            SQL
        );
        $this->serverId = (int) $server?->fetchColumn();
        self::$pdo?->prepare(
            'INSERT INTO agent_tokens (server_id, token_hash, last_used_at)
             VALUES (:server_id, :token_hash, CURRENT_TIMESTAMP)'
        )->execute([
            'server_id' => $this->serverId,
            'token_hash' => hash('sha256', str_repeat('d', 64)),
        ]);

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
        $websiteId = (int) self::$pdo?->query(
            "INSERT INTO websites (name) VALUES ('read-model-site') RETURNING id"
        )->fetchColumn();
        self::$pdo?->prepare(
            "INSERT INTO website_endpoints (website_id, name, url, is_primary)
             VALUES (:website_id, 'homepage', 'https://example.test', TRUE)"
        )->execute(['website_id' => $websiteId]);
        $endpointId = (int) self::$pdo?->query(
            'SELECT id FROM website_endpoints WHERE website_id = ' . $websiteId
        )->fetchColumn();
        self::$pdo?->prepare(
            "INSERT INTO website_state (website_id, primary_endpoint_id, status)
             VALUES (:website_id, :endpoint_id, 'healthy')"
        )->execute(['endpoint_id' => $endpointId, 'website_id' => $websiteId]);

        $dashboard = (new DashboardController(
            $twig,
            $servers,
            new ServerStatusService(new ServerPlatformService()),
            new \App\I18n\Translator(),
            null,
            null,
            new WebsiteRepository(self::$pdo, new SecretCipher(str_repeat('k', 32)))
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
        self::assertStringContainsString('fab fa-linux', $dashboardHtml);
        self::assertStringContainsString('title="Debian GNU/Linux 12"', $dashboardHtml);
        self::assertStringContainsString('server-status-online', $dashboardHtml);
        self::assertStringContainsString('data-summary-section="websites"', $dashboardHtml);
        self::assertStringContainsString('data-website-summary="healthy">', $dashboardHtml);

        $detailController = new ServerDetailController(
            self::$pdo,
            $twig,
            $servers,
            $metrics,
            new MaintenanceWindowRepository(self::$pdo),
            null,
            new ServerStatusService(new ServerPlatformService())
        );
        $detail = $detailController->show(
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
        self::assertStringNotContainsString('Регенерировать токен', $detailHtml);
        self::assertStringContainsString('fab fa-linux', $detailHtml);
        self::assertStringContainsString('title="Debian GNU/Linux 12"', $detailHtml);
        self::assertStringContainsString('server-status-online', $detailHtml);

        $metricsDetail = $detailController->show(
            $requestFactory->createServerRequest(
                'GET',
                'http://localhost/servers/' . $this->serverId . '?tab=metrics&period=24h'
            ),
            $responseFactory->createResponse(),
            ['id' => (string) $this->serverId]
        );
        $metricsHtml = (string) $metricsDetail->getBody();

        self::assertSame(200, $metricsDetail->getStatusCode());
        self::assertStringContainsString('id="chart-cpu_load"', $metricsHtml);
        self::assertStringContainsString('40%', $metricsHtml);
        self::assertStringNotContainsString('Регенерировать токен', $metricsHtml);

        $servicesDetail = $detailController->show(
            $requestFactory->createServerRequest(
                'GET',
                'http://localhost/servers/' . $this->serverId . '?tab=services'
            ),
            $responseFactory->createResponse(),
            ['id' => (string) $this->serverId]
        );
        $servicesHtml = (string) $servicesDetail->getBody();
        self::assertSame(200, $servicesDetail->getStatusCode());
        self::assertStringContainsString('Состояние сервисов', $servicesHtml);
        self::assertStringContainsString('/edit#services-monitoring', $servicesHtml);

        $eventsDetail = $detailController->show(
            $requestFactory->createServerRequest(
                'GET',
                'http://localhost/servers/' . $this->serverId . '?tab=events'
            ),
            $responseFactory->createResponse(),
            ['id' => (string) $this->serverId]
        );
        $eventsHtml = (string) $eventsDetail->getBody();
        self::assertSame(200, $eventsDetail->getStatusCode());
        self::assertStringContainsString('События сервера', $eventsHtml);
        self::assertStringContainsString('Активных проблем нет', $eventsHtml);

        $agentDetail = $detailController->show(
            $requestFactory->createServerRequest(
                'GET',
                'http://localhost/servers/' . $this->serverId . '?tab=agent'
            ),
            $responseFactory->createResponse(),
            ['id' => (string) $this->serverId]
        );
        $agentHtml = (string) $agentDetail->getBody();

        self::assertSame(200, $agentDetail->getStatusCode());
        self::assertStringContainsString('Регенерировать токен', $agentHtml);
        self::assertStringContainsString('?tab=agent', $agentHtml);
        self::assertStringNotContainsString('CPU сейчас', $agentHtml);
    }
}
