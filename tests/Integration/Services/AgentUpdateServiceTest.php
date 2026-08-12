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

final class AgentUpdateServiceTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AgentUpdateService $service;
    private int $serverId;
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
        $this->token = str_repeat('u', 64);
        $this->serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (
                name, agent_version, agent_artifact, agent_capabilities
             ) VALUES (
                'update-service', 'v0.4.2', 'linux-amd64', '[\"self_update_v1\"]'
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
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testEligibleAgentReceivesOnlyItsAllowlistedCommand(): void
    {
        $status = $this->service->statusForServer($this->serverId);
        self::assertTrue($status['can_update']);
        self::assertSame('v0.4.3', $status['available_version']);

        $command = $this->service->request($this->serverId, null);
        $delivery = $this->service->commandForServer($this->serverId);
        self::assertSame($command['id'], $delivery['id'] ?? null);
        self::assertSame([
            'id', 'target_version', 'artifact', 'sha256', 'size',
        ], array_keys($delivery ?? []));
        self::assertSame('linux-amd64', $delivery['artifact'] ?? null);
    }

    public function testBatchStatusUsesTheSameEligibilityModel(): void
    {
        $statuses = $this->service->statusesForServers([$this->serverId]);
        self::assertArrayHasKey($this->serverId, $statuses);
        self::assertSame('update_available', $statuses[$this->serverId]['state']);
        self::assertTrue($statuses[$this->serverId]['can_update']);
    }

    public function testOldAgentRequiresManualUpdateAndCannotCreateCommand(): void
    {
        self::$pdo?->prepare(
            "UPDATE servers SET agent_capabilities = '[]'::jsonb WHERE id = :id"
        )->execute(['id' => $this->serverId]);
        self::assertSame(
            'manual_required',
            $this->service->statusForServer($this->serverId)['state']
        );

        $this->expectException(InvalidArgumentException::class);
        $this->service->request($this->serverId, null);
    }

    public function testBearerTokenCanAdvanceOnlyItsOwnCommand(): void
    {
        $command = $this->service->request($this->serverId, null);
        self::assertTrue($this->service->report(
            $this->token,
            $command['id'],
            'accepted',
            null
        ));
        self::assertFalse($this->service->report(
            str_repeat('x', 64),
            $command['id'],
            'downloading',
            null
        ));
    }

    private function catalog(): AgentArtifactCatalog
    {
        $directory = sys_get_temp_dir() . '/mirvmon-update-service-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $manifest = ['version' => 'v0.4.3', 'artifacts' => []];
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
