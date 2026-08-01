<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\AgentController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Services\AgentCredentialIssuer;
use App\Services\AgentInstallerService;
use App\Services\PublicUrlResolver;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AgentControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AgentController $controller;
    private AgentCredentialIssuer $issuer;
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
        $this->serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('agent-controller-server') RETURNING id"
        )->fetchColumn();
        $this->issuer = new AgentCredentialIssuer(self::$pdo, str_repeat('k', 32));
        $this->controller = new AgentController(
            self::$pdo,
            new PublicUrlResolver(''),
            $this->issuer,
            new AgentInstallerService(),
            dirname(__DIR__, 3)
        );
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testInstallerUsesRequestOriginAndConsumesCredential(): void
    {
        $installerToken = $this->issuer->issueInstaller($this->serverId);
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://download.example/agent/install.sh?token=' . $installerToken
        );

        $response = $this->controller->generateInstallScript(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'https://download.example/api/v1/metrics',
            (string) $response->getBody()
        );
        self::assertStringNotContainsString('?token=', (string) $response->getBody());

        $reused = $this->controller->generateInstallScript(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );
        self::assertSame(403, $reused->getStatusCode());
    }

    public function testAgentSourceIsPublicButRestrictedToAllowlistedFiles(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://download.example/get-agent'
        );
        $agent = $this->controller->downloadAgent(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );
        self::assertSame(200, $agent->getStatusCode());
        self::assertStringContainsString('mirvmon_agent.client', (string) $agent->getBody());

        $forbidden = $this->controller->downloadAgentFile(
            $request,
            (new ResponseFactory())->createResponse(),
            ['file' => '../../.env']
        );
        self::assertSame(404, $forbidden->getStatusCode());
    }

    public function testAgentCanPullOnlyItsOwnConfigurationWithBearerToken(): void
    {
        $credential = $this->issuer->exchange(
            $this->issuer->issueInstaller($this->serverId)
        );
        self::$pdo?->prepare(
            'UPDATE agent_configs
             SET interval_seconds = 30,
                 monitor_services = CAST(:services AS jsonb),
                 enabled = TRUE
             WHERE server_id = :server_id'
        )->execute([
            'server_id' => $this->serverId,
            'services' => '["postgresql.service"]',
        ]);
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                'https://download.example/api/v1/agent/config'
            )
            ->withHeader('Authorization', 'Bearer ' . $credential->token);

        $response = $this->controller->getAgentConfig(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'enabled' => true,
                'interval_seconds' => 30,
                'monitor_services' => ['postgresql.service'],
            ],
            json_decode((string) $response->getBody(), true)
        );
        self::assertStringNotContainsString($credential->token, (string) $response->getBody());
    }
}
