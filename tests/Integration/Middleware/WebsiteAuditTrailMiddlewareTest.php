<?php

declare(strict_types=1);

namespace Tests\Integration\Middleware;

use App\Controllers\WebsiteController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\I18n\Translator;
use App\Middlewares\AuditTrailMiddleware;
use App\Repositories\AppSettingsRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\ServerRepository;
use App\Repositories\WebsiteCheckQueueRepository;
use App\Repositories\WebsiteRepository;
use App\Security\SecretCipher;
use App\Services\AuditLogger;
use App\Services\WebsiteEndpointValidator;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class WebsiteAuditTrailMiddlewareTest extends TestCase
{
    private static ?PDO $pdo = null;
    private int $userId;

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
        $user = self::$pdo?->prepare(
            "INSERT INTO users (username, password_hash, role) VALUES ('website-audit-admin', :hash, 'admin') RETURNING id"
        );
        $user?->execute(['hash' => password_hash('test-password', PASSWORD_DEFAULT)]);
        $this->userId = (int) $user?->fetchColumn();
        $_SESSION = ['user_id' => $this->userId, 'username' => 'website-audit-admin', 'role' => 'admin'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testWebsiteAuditContainsOnlySafeChangeMetadata(): void
    {
        $translator = new Translator(new AppSettingsRepository(self::$pdo), dirname(__DIR__, 3) . '/translations');
        $translator->refreshLocale();
        $controller = new WebsiteController(
            Twig::create(dirname(__DIR__, 3) . '/templates', ['cache' => false]),
            new WebsiteRepository(self::$pdo, new SecretCipher(str_repeat('k', 32))),
            new WebsiteEndpointValidator(),
            new WebsiteCheckQueueRepository(self::$pdo),
            $translator,
        );
        $middleware = new AuditTrailMiddleware(
            self::$pdo,
            new AuditLogger(new AuditLogRepository(self::$pdo)),
            new ServerRepository(self::$pdo),
            new NotificationOutboxRepository(self::$pdo),
            $translator,
        );
        $secret = 'website-audit-secret-literal';
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/sites')->withParsedBody([
            'name' => 'Audited site',
            'endpoints' => [[
                'name' => 'Primary', 'url' => 'https://example.com/', 'is_primary' => '1',
                'auth_type' => 'bearer', 'auth_secret' => $secret,
            ]],
        ]);
        $response = $middleware->process($request, new class($controller) implements RequestHandlerInterface {
            public function __construct(private readonly WebsiteController $controller) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->controller->store($request, (new ResponseFactory())->createResponse(), []);
            }
        });

        self::assertSame(302, $response->getStatusCode());
        $row = self::$pdo?->query(
            "SELECT action, object_type, metadata::text AS metadata FROM audit_log WHERE action = 'website.create' ORDER BY id DESC LIMIT 1"
        )->fetch();
        self::assertIsArray($row);
        self::assertSame('website', $row['object_type']);
        self::assertStringNotContainsString($secret, (string) $row['metadata']);
        self::assertStringContainsString('endpoints', (string) $row['metadata']);
    }
}
