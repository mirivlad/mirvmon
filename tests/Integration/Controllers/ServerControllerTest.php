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
            '1',
            (string) self::$pdo?->query(
                'SELECT count(*) FROM installer_tokens'
            )->fetchColumn()
        );
        self::assertSame(
            '1',
            (string) self::$pdo?->query(
                'SELECT count(*) FROM windows_installer_download_tokens'
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
        self::assertStringContainsString('/agent/install.exe?token=', $html);
        self::assertStringNotContainsString('/agent/install.ps1?token=', $html);
        self::assertStringNotContainsString('/agent/install.bat?token=', $html);
        self::assertStringNotContainsString('/agent/install-legacy', $html);
        self::assertSame(2, substr_count($html, 'href="/agent/install'));
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
        self::assertSame(
            '0',
            (string) self::$pdo?->query('SELECT count(*) FROM windows_installer_download_tokens')->fetchColumn()
        );
        self::assertSame('Новый installer нельзя выдать с текущим APP_KEY. Уже установленный агент продолжит работать; для переустановки сначала явно регенерируйте токен.', $_SESSION['flash_message']);
        self::assertSame('warning', $_SESSION['flash_type']);
    }

    public function testRestoredAgentTokenRequiresExplicitRotationBeforeDownloadingInstallers(): void
    {
        $serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('restored-installer-server') RETURNING id"
        )->fetchColumn();
        $sourceIssuer = new AgentCredentialIssuer(self::$pdo, str_repeat('a', 32));
        $installer = $sourceIssuer->issueInstaller($serverId);
        $credential = $sourceIssuer->exchange($installer);
        $originalHash = hash('sha256', $credential->token);

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
            $originalHash,
            self::$pdo?->query(
                'SELECT token_hash FROM agent_tokens WHERE server_id = ' . $serverId
            )->fetchColumn()
        );
        self::assertSame(
            '1',
            (string) self::$pdo?->query(
                'SELECT count(*) FROM installer_tokens WHERE server_id = ' . $serverId
            )->fetchColumn()
        );
        self::assertSame(
            'Новый installer нельзя выдать с текущим APP_KEY. Уже установленный агент продолжит работать; для переустановки сначала явно регенерируйте токен.',
            $_SESSION['flash_message']
        );
        self::assertSame('warning', $_SESSION['flash_type']);
    }

    public function testIndexUsesPerServerGroupDataAndFiltersByAllowedSearchAndSort(): void
    {
        $redGroupId = (int) self::$pdo?->query(
            "INSERT INTO monitoring_groups (name, icon, color)
             VALUES ('Red group', 'fa-circle', '#dc3545') RETURNING id"
        )->fetchColumn();
        $blueGroupId = (int) self::$pdo?->query(
            "INSERT INTO monitoring_groups (name, icon, color)
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

    public function testDisablingWebsiteProbeDetachesAssignmentsAndKeepsSiteValid(): void
    {
        $serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name, agent_capabilities)
             VALUES ('remote-probe', jsonb_build_array('website_probe_v1'))
             RETURNING id"
        )->fetchColumn();
        self::$pdo?->exec(
            "INSERT INTO agent_configs (server_id, enabled, website_probe_enabled)
             VALUES ({$serverId}, TRUE, TRUE)"
        );
        $websiteId = (int) self::$pdo?->query(
            "INSERT INTO websites (name, central_probe_enabled, probe_quorum)
             VALUES ('probe-site', FALSE, 1) RETURNING id"
        )->fetchColumn();
        self::$pdo?->exec(
            "INSERT INTO website_probe_agents (website_id, server_id)
             VALUES ({$websiteId}, {$serverId})"
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://monitor.example/servers/' . $serverId)
            ->withParsedBody([
                'name' => 'remote-probe',
                'offline_timeout' => '300',
                'display_widgets' => [],
            ]);
        $response = $this->controller->update(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $serverId]
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            '0',
            (string) self::$pdo?->query(
                "SELECT count(*) FROM website_probe_agents
                 WHERE website_id = {$websiteId} AND server_id = {$serverId}"
            )->fetchColumn()
        );
        self::assertTrue(in_array(
            self::$pdo?->query(
                "SELECT central_probe_enabled FROM websites WHERE id = {$websiteId}"
            )->fetchColumn(),
            [true, 1, '1', 't'],
            true
        ));
        self::assertSame(
            '1',
            (string) self::$pdo?->query(
                "SELECT probe_quorum FROM websites WHERE id = {$websiteId}"
            )->fetchColumn()
        );
    }

    public function testQuickWebsiteProbeToggleEnablesAndDisablesCapableAgent(): void
    {
        $serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name, agent_capabilities)
             VALUES ('quick-probe', jsonb_build_array('website_probe_v1'))
             RETURNING id"
        )->fetchColumn();
        self::$pdo?->exec(
            "INSERT INTO agent_configs (server_id, enabled, website_probe_enabled)
             VALUES ({$serverId}, TRUE, FALSE)"
        );

        $enable = $this->controller->toggleWebsiteProbe(
            (new ServerRequestFactory())
                ->createServerRequest('POST', 'https://monitor.example/servers/' . $serverId . '/website-probe')
                ->withParsedBody(['enabled' => '1']),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $serverId]
        );
        self::assertSame(200, $enable->getStatusCode());
        self::assertTrue((bool) json_decode((string) $enable->getBody(), true)['enabled']);
        self::assertTrue(in_array(
            self::$pdo?->query(
                "SELECT website_probe_enabled FROM agent_configs WHERE server_id = {$serverId}"
            )->fetchColumn(),
            [true, 1, '1', 't'],
            true
        ));

        $websiteId = (int) self::$pdo?->query(
            "INSERT INTO websites (name, central_probe_enabled, probe_quorum)
             VALUES ('quick-probe-site', FALSE, 1) RETURNING id"
        )->fetchColumn();
        self::$pdo?->exec(
            "INSERT INTO website_probe_agents (website_id, server_id)
             VALUES ({$websiteId}, {$serverId})"
        );

        $disable = $this->controller->toggleWebsiteProbe(
            (new ServerRequestFactory())
                ->createServerRequest('POST', 'https://monitor.example/servers/' . $serverId . '/website-probe')
                ->withParsedBody(['enabled' => '0']),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $serverId]
        );
        $payload = json_decode((string) $disable->getBody(), true);
        self::assertSame(200, $disable->getStatusCode());
        self::assertFalse($payload['enabled']);
        self::assertSame(1, $payload['removed_assignments']);
        self::assertSame(
            '0',
            (string) self::$pdo?->query(
                "SELECT count(*) FROM website_probe_agents WHERE server_id = {$serverId}"
            )->fetchColumn()
        );
        self::assertTrue(in_array(
            self::$pdo?->query(
                "SELECT central_probe_enabled FROM websites WHERE id = {$websiteId}"
            )->fetchColumn(),
            [true, 1, '1', 't'],
            true
        ));
    }

    public function testQuickWebsiteProbeToggleRejectsAgentWithoutCapability(): void
    {
        $serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name, agent_capabilities)
             VALUES ('old-agent', '[]'::jsonb) RETURNING id"
        )->fetchColumn();
        self::$pdo?->exec(
            "INSERT INTO agent_configs (server_id, enabled, website_probe_enabled)
             VALUES ({$serverId}, TRUE, FALSE)"
        );

        $response = $this->controller->toggleWebsiteProbe(
            (new ServerRequestFactory())
                ->createServerRequest('POST', 'https://monitor.example/servers/' . $serverId . '/website-probe')
                ->withParsedBody(['enabled' => '1']),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $serverId]
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'probe_not_supported',
            json_decode((string) $response->getBody(), true)['error']
        );
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
