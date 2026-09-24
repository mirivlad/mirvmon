<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Application\AppFactory;
use App\Application\Bootstrap;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PublicStatusHttpTest extends TestCase
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
        $directory = sys_get_temp_dir() . '/mirvmon-public-status-session-' . getmypid();
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory));
        }
        ini_set('session.save_path', $directory);
        self::$pdo?->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testPublicPageIsStatelessAndRevealsOnlySelectedDisplayNames(): void
    {
        $pdo = self::$pdo;
        self::assertNotNull($pdo);
        $id = (int) $pdo->query(
            "INSERT INTO servers(name,address) VALUES ('Private server name','192.0.2.15') RETURNING id"
        )->fetchColumn();
        $pdo->exec("INSERT INTO public_status_items(server_id,display_name) VALUES ($id,'Public service')");
        $app = $this->app($pdo);

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/status'));
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Public service', $html);
        self::assertStringNotContainsString('Private server name', $html);
        self::assertStringNotContainsString('192.0.2.15', $html);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $admin = $this->app($pdo)->handle((new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/admin/public-status'));
        self::assertSame(302, $admin->getStatusCode());
        self::assertSame('/login', $admin->getHeaderLine('Location'));
    }

    private function app(PDO $pdo): \Slim\App
    {
        $container = Bootstrap::createContainer([
            'app_env' => 'test', 'app_debug' => false,
            'app_key' => base64_encode(str_repeat('k', 32)),
            'setup_token' => str_repeat('s', 32), 'timezone' => 'UTC',
            'trusted_proxies' => ['10.0.0.0/8'],
            'session_name' => 'mirvmon_test', 'session_secure' => false,
            'max_request_bytes' => 1048576,
            'templates_path' => dirname(__DIR__, 3) . '/templates',
            'twig_cache' => false,
        ], $pdo);
        return AppFactory::create($container);
    }
}
