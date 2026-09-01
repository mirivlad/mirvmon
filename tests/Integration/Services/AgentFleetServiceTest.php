<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\AgentUpdateRepository;
use App\Services\AgentArtifactCatalog;
use App\Services\AgentFleetService;
use App\Services\AgentUpdateService;
use App\Services\AgentVersionService;
use App\Services\ServerPlatformService;
use App\Services\ServerStatusService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AgentFleetServiceTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AgentFleetService $fleet;
    private AgentUpdateRepository $commands;
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
        $this->commands = new AgentUpdateRepository(self::$pdo);
        $updates = new AgentUpdateService(
            self::$pdo,
            $this->commands,
            new AgentVersionService(),
            AgentArtifactCatalog::load($this->artifactDirectory)
        );
        $this->fleet = new AgentFleetService(
            self::$pdo,
            $updates,
            new ServerStatusService(new ServerPlatformService())
        );
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
        if (isset($this->artifactDirectory)) {
            foreach (glob($this->artifactDirectory . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->artifactDirectory);
        }
    }

    public function testOverviewSummarizesExistingFleetTelemetryWithoutNewProtocolFields(): void
    {
        $current = $this->server('current', 'v0.5.4', true, true);
        $outdated = $this->server('outdated', 'v0.5.3', true, true);
        $updating = $this->server('updating', 'v0.5.3', true, true);
        $manual = $this->server('manual', 'v0.5.3', true, false);
        $failed = $this->server('failed', 'v0.5.3', true, true);
        $offline = $this->server('offline', 'v0.5.4', false, true);
        $this->server('unknown', null, null, false);

        $this->commands->create($updating, 'v0.5.4', 'linux-amd64', null);
        $failedCommand = $this->commands->create($failed, 'v0.5.4', 'linux-amd64', null);
        $this->commands->fail((string) $failedCommand['id'], $failed, 'download_failed');

        $overview = $this->fleet->overview();

        self::assertSame(7, $overview['summary']['total']);
        self::assertSame(6, $overview['summary']['reported']);
        self::assertSame(2, $overview['summary']['current']);
        self::assertSame(4, $overview['summary']['outdated']);
        self::assertSame(1, $overview['summary']['updating']);
        self::assertSame(2, $overview['summary']['attention']);
        self::assertSame(2, $overview['summary']['offline']);
        self::assertSame(1, $overview['summary']['unknown']);
        self::assertSame('v0.5.4', $overview['target_version']);
        self::assertSame(2, $overview['versions']['v0.5.4']);
        self::assertSame(4, $overview['versions']['v0.5.3']);

        $attention = $this->fleet->overview('attention');
        self::assertSame(['failed', 'manual'], array_column($attention['servers'], 'name'));
        self::assertSame('failed', $attention['servers'][0]['agent_update']['state']);
        self::assertSame('download_failed', $attention['servers'][0]['agent_update']['command']['error_code']);

        $offlineView = $this->fleet->overview('offline');
        self::assertSame(['unknown', 'offline'], array_column($offlineView['servers'], 'name'));
        self::assertSame('never', $offlineView['servers'][0]['connection_state']);
        self::assertSame('offline', $offlineView['servers'][1]['connection_state']);

        $searched = $this->fleet->overview('all', 'outdated');
        self::assertSame([$outdated], array_map('intval', array_column($searched['servers'], 'id')));
        self::assertSame($current, (int) $overview['servers'][array_search('current', array_column($overview['servers'], 'name'), true)]['id']);
        self::assertSame($offline, (int) $overview['servers'][array_search('offline', array_column($overview['servers'], 'name'), true)]['id']);
    }

    private function server(string $name, ?string $version, ?bool $recentContact, bool $selfUpdate): int
    {
        $statement = self::$pdo?->prepare(
            "INSERT INTO servers (name, os_version, agent_version, agent_artifact, agent_capabilities)
             VALUES (:name, 'Debian 13', :version, :artifact, CAST(:capabilities AS jsonb)) RETURNING id"
        );
        $statement?->execute([
            'name' => $name,
            'version' => $version,
            'artifact' => $version === null ? null : 'linux-amd64',
            'capabilities' => $selfUpdate ? '["self_update_v1"]' : '[]',
        ]);
        $id = (int) $statement?->fetchColumn();
        if ($recentContact !== null) {
            $lastUsed = $recentContact
                ? "CURRENT_TIMESTAMP - INTERVAL '10 seconds'"
                : "CURRENT_TIMESTAMP - INTERVAL '1 hour'";
            $token = self::$pdo?->prepare(
                'INSERT INTO agent_tokens (server_id, token_hash, last_used_at) '
                . 'VALUES (:server_id, :token_hash, ' . $lastUsed . ')'
            );
            $token?->execute([
                'server_id' => $id,
                'token_hash' => hash('sha256', $name . random_bytes(8)),
            ]);
        }
        return $id;
    }

    private function createArtifactDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/mirvmon-fleet-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $manifest = ['version' => 'v0.5.4', 'artifacts' => []];
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
