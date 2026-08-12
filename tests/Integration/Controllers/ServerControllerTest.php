<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\ServerController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Services\AgentCredentialIssuer;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class ServerControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private ServerController $controller;

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
        $this->controller = new ServerController(
            self::$pdo,
            Twig::create(dirname(__DIR__, 3) . '/templates', ['cache' => false]),
            new AgentCredentialIssuer(self::$pdo, str_repeat('k', 32))
        );
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testCreationProducesOneTimeInstallersWithoutAgentSecret(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://monitor.example/servers')
            ->withParsedBody([
                'name' => 'new-server',
                'address' => '10.0.0.1',
                'description' => 'test',
            ]);

        $response = $this->controller->store(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            '5',
            (string) self::$pdo?->query(
                'SELECT count(*) FROM installer_tokens'
            )->fetchColumn()
        );
        self::assertSame(
            '1',
            (string) self::$pdo?->query(
                'SELECT count(*) FROM agent_tokens'
            )->fetchColumn()
        );
        $html = (string) $response->getBody();
        self::assertStringContainsString('/agent/install.sh?token=', $html);
        self::assertStringContainsString('/agent/install.ps1?token=', $html);
        self::assertStringContainsString('/agent/install.bat?token=', $html);
        self::assertStringContainsString('/agent/install-legacy.ps1?token=', $html);
        self::assertStringContainsString('/agent/install-legacy.bat?token=', $html);
    }

    public function testLegacyAgentTokenRequiresExplicitRotationBeforeDownloadingInstallers(): void
    {
        $serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('legacy-installer-server') RETURNING id"
        )->fetchColumn();
        $token = self::$pdo?->prepare(
            'INSERT INTO agent_tokens (server_id, token_hash) VALUES (:server_id, :token_hash)'
        );
        $token?->execute([
            'server_id' => $serverId,
            'token_hash' => hash('sha256', 'legacy-agent-token'),
        ]);

        $response = $this->controller->installers(
            (new ServerRequestFactory())->createServerRequest(
                'POST',
                'https://monitor.example/servers/' . $serverId . '/installers'
            ),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $serverId]
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/servers/' . $serverId, $response->getHeaderLine('Location'));
        self::assertSame(
            '0',
            (string) self::$pdo?->query('SELECT count(*) FROM installer_tokens')->fetchColumn()
        );
        self::assertSame('Для этого агента требуется явный отзыв ключа.', $_SESSION['flash_message']);
        self::assertSame('warning', $_SESSION['flash_type']);
    }
}
