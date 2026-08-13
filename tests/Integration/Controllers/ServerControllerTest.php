<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\ServerController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Services\AgentCredentialIssuer;
use App\Repositories\AgentUpdateRepository;
use App\Services\AgentArtifactCatalog;
use App\Services\AgentUpdateService;
use App\Services\AgentVersionService;
use App\Services\ServerPlatformService;
use App\Services\ServerStatusService;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class ServerControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private ServerController $controller;
    private string $artifactDirectory;

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
        $this->artifactDirectory = $this->createArtifactDirectory();
        $this->controller = new ServerController(
            self::$pdo,
            Twig::create(dirname(__DIR__, 3) . '/templates', ['cache' => false]),
            new AgentCredentialIssuer(self::$pdo, str_repeat('k', 32)),
            new AgentUpdateService(
                self::$pdo,
                new AgentUpdateRepository(self::$pdo),
                new AgentVersionService(),
                AgentArtifactCatalog::load($this->artifactDirectory)
            ),
            new ServerStatusService(new ServerPlatformService())
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
            '4',
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
        self::assertStringContainsString('/agent/install-legacy.zip?token=', $html);
        self::assertStringNotContainsString('/agent/install-legacy.ps1?token=', $html);
        self::assertStringNotContainsString('/agent/install-legacy.bat?token=', $html);
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

    public function testIndexUsesPerServerGroupDataAndFiltersByAllowedSearchAndSort(): void
    {
        $redGroupId = (int) self::$pdo?->query(
            "INSERT INTO server_groups (name, icon, color)
             VALUES ('Red group', 'fa-circle', '#dc3545') RETURNING id"
        )->fetchColumn();
        $blueGroupId = (int) self::$pdo?->query(
            "INSERT INTO server_groups (name, icon, color)
             VALUES ('Blue group', 'fa-square', '#0d6efd') RETURNING id"
        )->fetchColumn();
        $insert = self::$pdo?->prepare(
            'INSERT INTO servers (
                name, address, group_id, os_version,
                agent_version, agent_artifact, agent_capabilities
             ) VALUES (
                :name, :address, :group_id, :os_version,
                :agent_version, :agent_artifact, CAST(:agent_capabilities AS jsonb)
             )'
        );
        $insert?->execute([
            'name' => 'Zulu',
            'address' => '10.0.0.2',
            'group_id' => $blueGroupId,
            'os_version' => 'Windows Server 2022',
            'agent_version' => null,
            'agent_artifact' => null,
            'agent_capabilities' => '[]',
        ]);
        $insert?->execute([
            'name' => 'Alpha',
            'address' => '10.0.0.1',
            'group_id' => $redGroupId,
            'os_version' => 'Linux 6.8',
            'agent_version' => 'v0.4.2',
            'agent_artifact' => 'linux-amd64',
            'agent_capabilities' => '["self_update_v1"]',
        ]);

        $response = $this->controller->index(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'https://monitor.example/servers?sort=address&direction=asc&name=Alpha'
            ),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $response->getBody();

        self::assertStringContainsString('fa-linux', $html);
        self::assertStringContainsString('title="Linux 6.8"', $html);
        self::assertStringContainsString('server-status-offline', $html);
        self::assertStringContainsString('server-platform-icon', $html);
        self::assertStringContainsString('fa-circle', $html);
        self::assertStringContainsString('#dc3545', $html);
        self::assertStringContainsString('Доступно обновление агента', $html);
        self::assertStringContainsString('/servers/', $html);
        self::assertStringContainsString('?tab=agent', $html);
        self::assertStringNotContainsString('>Zulu<', $html);
    }

    private function createArtifactDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/mirvmon-server-list-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $manifest = ['version' => 'v0.4.3', 'artifacts' => []];
        foreach ([
            'linux-amd64' => 'mirvmon-agent-linux-amd64',
            'windows-amd64' => 'mirvmon-agent-windows-amd64.exe',
            'windows-legacy-amd64' => 'mirvmon-agent-windows-legacy-amd64.exe',
        ] as $key => $filename) {
            file_put_contents($directory . '/' . $filename, $key);
            $manifest['artifacts'][$key] = [
                'filename' => $filename,
                'sha256' => hash('sha256', $key),
                'size' => strlen($key),
                'content_type' => 'application/octet-stream',
            ];
        }
        file_put_contents(
            $directory . '/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR)
        );

        return $directory;
    }
}
