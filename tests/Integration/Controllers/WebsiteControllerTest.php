<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\WebsiteController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\AppSettingsRepository;
use App\Repositories\WebsiteCheckQueueRepository;
use App\Repositories\WebsiteRepository;
use App\Security\SecretCipher;
use App\Services\WebsiteEndpointValidator;
use App\I18n\Translator;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class WebsiteControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private WebsiteController $controller;
    private int $websiteId;

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
        $twig = Twig::create(dirname(__DIR__, 3) . '/templates', ['cache' => false]);
        $translator = new Translator(new AppSettingsRepository(self::$pdo), dirname(__DIR__, 3) . '/translations');
        $translator->refreshLocale();
        $this->controller = new WebsiteController(
            $twig,
            new WebsiteRepository(self::$pdo, new SecretCipher(str_repeat('k', 32))),
            new WebsiteEndpointValidator(),
            new WebsiteCheckQueueRepository(self::$pdo),
            $translator,
        );
        $this->websiteId = 0;
        $_SESSION = ['user_id' => 1, 'username' => 'admin', 'role' => 'admin'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testAdminCanCreateSiteWithMultipleEndpointsAndManualCheckOnlyQueues(): void
    {
        $body = [
            'name' => 'Public API',
            'description' => 'Production endpoint',
            'endpoints' => [
                ['name' => 'Home', 'url' => 'https://example.com/', 'is_primary' => '1', 'status_check_enabled' => '1'],
                ['name' => 'Health', 'url' => 'https://example.com/health', 'is_primary' => '0', 'status_check_enabled' => '1'],
            ],
        ];
        $response = $this->controller->store($this->request('POST', '/sites', $body), (new ResponseFactory())->createResponse(), []);
        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('/sites/', $response->getHeaderLine('Location'));
        $this->websiteId = (int) self::$pdo?->query("SELECT id FROM websites WHERE name = 'Public API'")->fetchColumn();
        self::assertSame(2, (int) self::$pdo?->query("SELECT count(*) FROM website_endpoints WHERE website_id = {$this->websiteId}")->fetchColumn());

        $check = $this->controller->check($this->request('POST', '/sites/' . $this->websiteId . '/check'), (new ResponseFactory())->createResponse(), ['id' => (string) $this->websiteId]);
        self::assertSame('/sites', $check->getHeaderLine('Location'));
        self::assertSame(2, (int) self::$pdo?->query("SELECT count(*) FROM website_check_jobs WHERE website_id = {$this->websiteId}")->fetchColumn());
    }

    public function testCreateFormRendersTheDefaultEndpoint(): void
    {
        $response = $this->controller->create(
            $this->request('GET', '/sites/create'),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="endpoints[0][name]"', $html);
        self::assertStringContainsString('data-add-endpoint', $html);
    }

    public function testValidationRendersNonSecretFieldsWithoutSubmittedSecret(): void
    {
        $secret = 'never-render-this-secret';
        $response = $this->controller->store(
            $this->request('POST', '/sites', [
                'name' => 'Preserved name',
                'endpoints' => [['name' => 'Broken', 'url' => 'not-a-url', 'auth_type' => 'bearer', 'auth_secret' => $secret]],
            ]),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $response->getBody();
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Preserved name', $html);
        self::assertStringNotContainsString($secret, $html);
    }

    private function request(string $method, string $path, ?array $body = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        return $body === null ? $request : $request->withParsedBody($body);
    }
}
