<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\Api\WebsiteMetricsApiController;
use App\Controllers\WebsiteDetailController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\I18n\Translator;
use App\Repositories\AppSettingsRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\MaintenanceWindowRepository;
use App\Repositories\WebsiteMetricsRepository;
use App\Repositories\WebsiteRepository;
use App\Security\SecretCipher;
use App\Services\WebsiteEndpointValidator;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class WebsiteDetailControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private int $websiteId;
    private int $endpointId;
    private WebsiteDetailController $controller;
    private WebsiteMetricsApiController $api;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) self::markTestSkipped('Set TEST_DB_* to run the TimescaleDB integration suite.');
        self::$pdo = ConnectionFactory::connect([
            'DB_HOST' => (string) getenv('TEST_DB_HOST'), 'DB_PORT' => (string) (getenv('TEST_DB_PORT') ?: '5432'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'), 'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'), 'DB_SSLMODE' => (string) (getenv('TEST_DB_SSLMODE') ?: 'disable'),
        ]);
        (new Migrator(self::$pdo, dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        self::$pdo?->beginTransaction();
        $group = (int) self::$pdo?->query("INSERT INTO monitoring_groups (name) VALUES ('Detail tests') RETURNING id")->fetchColumn();
        $this->websiteId = (int) self::$pdo?->query("INSERT INTO websites (group_id, name) VALUES ({$group}, 'Detail site') RETURNING id")->fetchColumn();
        $this->endpointId = (int) self::$pdo?->query("INSERT INTO website_endpoints (website_id, name, url, is_primary, status_check_enabled) VALUES ({$this->websiteId}, 'Home', 'https://example.com/', TRUE, TRUE) RETURNING id")->fetchColumn();
        self::$pdo?->prepare('INSERT INTO website_endpoint_state (endpoint_id, website_id) VALUES (:endpoint, :website)')->execute(['endpoint' => $this->endpointId, 'website' => $this->websiteId]);
        self::$pdo?->prepare('UPDATE website_state SET primary_endpoint_id = :endpoint WHERE website_id = :website')->execute(['endpoint' => $this->endpointId, 'website' => $this->websiteId]);
        $twig = Twig::create(dirname(__DIR__, 3) . '/templates', ['cache' => false]);
        $translator = new Translator(new AppSettingsRepository(self::$pdo), dirname(__DIR__, 3) . '/translations');
        $translator->refreshLocale();
        $websites = new WebsiteRepository(self::$pdo, new SecretCipher(str_repeat('k', 32)));
        $metrics = new WebsiteMetricsRepository(self::$pdo);
        $incidents = new IncidentRepository(self::$pdo);
        $this->controller = new WebsiteDetailController(self::$pdo, $twig, $websites, $metrics, new MaintenanceWindowRepository(self::$pdo), $translator, $incidents);
        $this->api = new WebsiteMetricsApiController(self::$pdo, $websites, $metrics, $incidents);
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) self::$pdo->rollBack();
    }

    public function testDetailTabsRenderSafeMarkers(): void
    {
        foreach ([
            'overview' => 'Что мониторится', 'metrics' => 'data-website-metrics-chart',
            'events' => 'incident-timeline', 'settings' => 'website-threshold-settings',
        ] as $tab => $marker) {
            $response = $this->controller->show(
                (new ServerRequestFactory())->createServerRequest('GET', '/sites/' . $this->websiteId . '?tab=' . $tab),
                (new ResponseFactory())->createResponse(), ['id' => (string) $this->websiteId]
            );
            self::assertSame(200, $response->getStatusCode(), $tab);
            self::assertStringContainsString($marker, (string) $response->getBody(), $tab);
        }
    }

    public function testMetricsApiValidatesEndpointOwnershipAndReturnsSafeShape(): void
    {
        $response = $this->api->metrics(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/sites/' . $this->websiteId . '/metrics?period=24h'),
            (new ResponseFactory())->createResponse(), ['id' => (string) $this->websiteId]
        );
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('raw', $payload['source']);
        self::assertArrayHasKey('transport_availability', $payload['series']);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('transport_availability', $payload['summary']);
        self::assertArrayHasKey('sample_count', $payload['summary']);
        self::assertArrayHasKey('availability_intervals', $payload);
        self::assertArrayNotHasKey('body', $payload);

        $invalid = $this->api->metrics(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/sites/' . $this->websiteId . '/metrics?endpoint_id=999999&period=24h'),
            (new ResponseFactory())->createResponse(), ['id' => (string) $this->websiteId]
        );
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringNotContainsString('SELECT', (string) $invalid->getBody());
    }
}
