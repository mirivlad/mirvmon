<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\AgentUpdateRepository;
use App\Services\AgentArtifactCatalog;
use App\Services\AgentUpdateService;
use App\Services\AgentVersionService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class HotfixAgentUpdateCompatibilityTest extends TestCase
{
    private static ?PDO $pdo = null;
    private int $serverId;

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
        $this->serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (
                name, agent_version, agent_artifact, agent_capabilities
             ) VALUES (
                'hotfix-compatibility', 'v0.4.12', 'linux-amd64', '[\"self_update_v1\"]'
             ) RETURNING id"
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testOldUpdaterIsNotOfferedFourPartTarget(): void
    {
        $service = $this->service('v0.4.17.1');
        $status = $service->statusForServer($this->serverId);

        self::assertTrue($status['is_outdated']);
        self::assertFalse($status['can_update']);
        self::assertTrue($status['requires_compatible_updater']);
        self::assertSame('manual_required', $status['state']);

        $this->expectException(InvalidArgumentException::class);
        $service->request($this->serverId, null);
    }

    public function testModernUpdaterMayReceiveFourPartTarget(): void
    {
        self::$pdo?->prepare(
            'UPDATE servers SET agent_version = :version WHERE id = :id'
        )->execute(['version' => 'v0.4.17', 'id' => $this->serverId]);

        $status = $this->service('v0.4.17.1')->statusForServer($this->serverId);

        self::assertTrue($status['is_outdated']);
        self::assertTrue($status['can_update']);
        self::assertFalse($status['requires_compatible_updater']);
        self::assertSame('update_available', $status['state']);
    }

    public function testOldUpdaterStillMayReceiveThreePartBridgeTarget(): void
    {
        $status = $this->service('v0.4.17')->statusForServer($this->serverId);

        self::assertTrue($status['can_update']);
        self::assertFalse($status['requires_compatible_updater']);
        self::assertSame('update_available', $status['state']);
    }

    private function service(string $version): AgentUpdateService
    {
        return new AgentUpdateService(
            self::$pdo,
            new AgentUpdateRepository(self::$pdo),
            new AgentVersionService(),
            $this->catalog($version)
        );
    }

    private function catalog(string $version): AgentArtifactCatalog
    {
        $directory = sys_get_temp_dir() . '/mirvmon-hotfix-compat-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $manifest = ['version' => $version, 'artifacts' => []];
        foreach ([
            'linux-amd64' => 'mirvmon-agent-linux-amd64',
            'windows-amd64' => 'mirvmon-agent-windows-amd64.exe',
            'windows-legacy-amd64' => 'mirvmon-agent-windows-legacy-amd64.exe',
        ] as $key => $filename) {
            $content = 'artifact-' . $key;
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
