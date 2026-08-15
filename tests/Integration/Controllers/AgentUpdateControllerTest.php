<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\AgentUpdateController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\AgentUpdateRepository;
use App\Services\AgentArtifactCatalog;
use App\Services\AgentUpdateService;
use App\Services\AgentVersionService;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AgentUpdateControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AgentUpdateController $controller;
    private AgentUpdateService $service;
    private int $serverId;
    private int $userId;
    private string $token;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            self::markTestSkipped('Set TEST_DB_* to run the TimescaleDB integration suite.');
        }
        self::$pdo = ConnectionFactory::connect([
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_PORT' => (string) getenv('TEST_DB_PORT'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'DB_SSLMODE' => (string) getenv('TEST_DB_SSLMODE'),
        ]);
        (new Migrator(self::$pdo, dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        self::$pdo?->beginTransaction();
        $this->userId = (int) self::$pdo?->query(
            "INSERT INTO users (username, password_hash, role)
             VALUES ('update-admin', 'hash', 'admin') RETURNING id"
        )->fetchColumn();
        $this->token = str_repeat('z', 64);
        $this->serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (
                name, agent_version, agent_artifact, agent_capabilities
             ) VALUES (
                'controller-update', 'v0.4.2', 'linux-amd64', '[\"self_update_v1\"]'
             ) RETURNING id"
        )->fetchColumn();
        self::$pdo?->prepare(
            'INSERT INTO agent_tokens (server_id, token_hash)
             VALUES (:server_id, :token_hash)'
        )->execute([
            'server_id' => $this->serverId,
            'token_hash' => hash('sha256', $this->token),
        ]);
        $this->service = new AgentUpdateService(
            self::$pdo,
            new AgentUpdateRepository(self::$pdo),
            new AgentVersionService(),
            $this->catalog()
        );
        $this->controller = new AgentUpdateController($this->service);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id']);
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testAdminRequestCreatesCommandAndRedirectsToAgentTab(): void
    {
        $_SESSION['user_id'] = $this->userId;
        $response = $this->controller->requestUpdate(
            (new ServerRequestFactory())->createServerRequest('POST', '/'),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $this->serverId]
        );
        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            '/servers/' . $this->serverId . '?tab=agent',
            $response->getHeaderLine('Location')
        );
        self::assertSame('pending', $this->service->statusForServer(
            $this->serverId
        )['state']);
    }

    public function testJsonUpdateRequestReturnsPendingStatusForInlineUi(): void
    {
        $_SESSION['user_id'] = $this->userId;
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/')
            ->withHeader('Accept', 'application/json');
        $response = $this->controller->requestUpdate(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $this->serverId]
        );

        self::assertSame(202, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('pending', $payload['status']['state']);
        self::assertTrue($payload['status']['is_outdated']);
        self::assertSame('v0.4.2', $payload['status']['installed_version']);
        self::assertSame('v0.4.3', $payload['status']['available_version']);
    }

    public function testBatchStatusEndpointSupportsServerListPolling(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/?ids=' . $this->serverId
        );
        $response = $this->controller->statuses(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertCount(1, $payload['statuses']);
        $status = array_values($payload['statuses'])[0];
        self::assertSame('update_available', $status['state']);
        self::assertTrue($status['is_outdated']);
        self::assertTrue($status['can_update']);
    }

    public function testBatchStatusEndpointRejectsInvalidIds(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/?ids=1,nope'
        );
        $response = $this->controller->statuses(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testAgentReportsProgressWithBearerOwnership(): void
    {
        $command = $this->service->request($this->serverId, null);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/')
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withParsedBody(['state' => 'accepted']);
        $response = $this->controller->reportStatus(
            $request,
            (new ResponseFactory())->createResponse(),
            ['command' => (string) $command['id']]
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['saved' => true],
            json_decode((string) $response->getBody(), true)
        );

        $unauthorized = $this->controller->reportStatus(
            $request->withHeader('Authorization', 'Bearer ' . str_repeat('x', 64)),
            (new ResponseFactory())->createResponse(),
            ['command' => (string) $command['id']]
        );
        self::assertSame(401, $unauthorized->getStatusCode());
    }

    private function catalog(): AgentArtifactCatalog
    {
        $directory = sys_get_temp_dir() . '/mirvmon-update-controller-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $manifest = ['version' => 'v0.4.3', 'artifacts' => []];
        foreach ([
            'linux-amd64' => 'mirvmon-agent-linux-amd64',
            'windows-amd64' => 'mirvmon-agent-windows-amd64.exe',
            'windows-legacy-amd64' => 'mirvmon-agent-windows-legacy-amd64.exe',
        ] as $key => $filename) {
            $content = $key;
            file_put_contents($directory . '/' . $filename, $content);
            $manifest['artifacts'][$key] = [
                'filename' => $filename,
                'sha256' => hash('sha256', $content),
                'size' => strlen($content),
                'content_type' => 'application/octet-stream',
            ];
        }
        file_put_contents(
            $directory . '/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR)
        );
        return AgentArtifactCatalog::load($directory);
    }
}
