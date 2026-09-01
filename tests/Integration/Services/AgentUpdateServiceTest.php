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

    public function testPollTerminalizesObsoletePendingCommandWithoutReplacingIt(): void
    {
        $repository = new AgentUpdateRepository(self::$pdo);
        $obsolete = $repository->create(
            $this->serverId,
            'v0.4.2',
            'linux-amd64',
            null
        );

        $delivery = $this->service->commandForServer($this->serverId);

        self::assertNull($delivery);

        $old = self::$pdo?->prepare(
            'SELECT state, error_code
             FROM agent_update_commands
             WHERE id = CAST(:id AS uuid)'
        );
        $old?->execute(['id' => $obsolete['id']]);
        self::assertSame(
            ['state' => 'failed', 'error_code' => 'target_superseded'],
            $old?->fetch()
        );
        self::assertNull($repository->activeForServer($this->serverId));
        self::assertSame(
            'failed',
            $this->service->statusForServer($this->serverId)['state']
        );

        $retry = $this->service->request($this->serverId, null);
        $retryDelivery = $this->service->commandForServer($this->serverId);
        self::assertSame($retry['id'], $retryDelivery['id'] ?? null);
        self::assertSame('v0.4.3', $retryDelivery['target_version'] ?? null);
    }

    public function testV0412CanRecoverFromObsoleteFourPartTargetThroughV0416(): void
    {
        self::$pdo?->prepare(
            'UPDATE servers SET agent_version = :version WHERE id = :id'
        )->execute(['version' => 'v0.4.12', 'id' => $this->serverId]);

        $repository = new AgentUpdateRepository(self::$pdo);
        $obsolete = $repository->create(
            $this->serverId,
            'v0.4.15.3',
            'linux-amd64',
            null
        );
        $bridge = new AgentUpdateService(
            self::$pdo,
            $repository,
            new AgentVersionService(),
            $this->catalog('v0.4.16')
        );

        self::assertNull($bridge->commandForServer($this->serverId));
        $old = self::$pdo?->prepare(
            'SELECT state, error_code FROM agent_update_commands WHERE id = CAST(:id AS uuid)'
        );
        $old?->execute(['id' => $obsolete['id']]);
        self::assertSame(
            ['state' => 'failed', 'error_code' => 'target_superseded'],
            $old?->fetch()
        );

        $status = $bridge->statusForServer($this->serverId);
        self::assertTrue($status['can_update']);
        self::assertSame('v0.4.16', $status['available_version']);

        $replacement = $bridge->request($this->serverId, null);
        $delivery = $bridge->commandForServer($this->serverId);
        self::assertSame($replacement['id'], $delivery['id'] ?? null);
        self::assertSame('v0.4.16', $delivery['target_version'] ?? null);
    }

    public function testPollDoesNotReplaceAcknowledgedObsoleteCommand(): void
    {
        $repository = new AgentUpdateRepository(self::$pdo);
        $obsolete = $repository->create(
            $this->serverId,
            'v0.4.2',
            'linux-amd64',
            null
        );
        $repository->advance($obsolete['id'], $this->serverId, 'accepted');

        self::assertNull($this->service->commandForServer($this->serverId));
        self::assertSame(
            $obsolete['id'],
            $repository->activeForServer($this->serverId)['id'] ?? null
        );
    }

    public function testBatchStatusUsesTheSameEligibilityModel(): void
    {
        $statuses = $this->service->statusesForServers([$this->serverId]);
        self::assertArrayHasKey($this->serverId, $statuses);
        self::assertSame('update_available', $statuses[$this->serverId]['state']);
        self::assertTrue($statuses[$this->serverId]['can_update']);
    }

    public function testBulkRequestSchedulesOnlyActionableOutdatedAgents(): void
    {
        $activeId = (int) self::$pdo?->query(
            "INSERT INTO servers (name, agent_version, agent_artifact, agent_capabilities)
             VALUES ('bulk-active', 'v0.4.2', 'linux-amd64', '[\"self_update_v1\"]')
             RETURNING id"
        )->fetchColumn();
        $manualId = (int) self::$pdo?->query(
            "INSERT INTO servers (name, agent_version, agent_artifact, agent_capabilities)
             VALUES ('bulk-manual', 'v0.4.2', 'linux-amd64', '[]')
             RETURNING id"
        )->fetchColumn();
        self::$pdo?->query(
            "INSERT INTO servers (name, agent_version, agent_artifact, agent_capabilities)
             VALUES ('bulk-current', 'v0.4.3', 'linux-amd64', '[\"self_update_v1\"]')"
        );
        (new AgentUpdateRepository(self::$pdo))->create(
            $activeId,
            'v0.4.3',
            'linux-amd64',
            null
        );

        $result = $this->service->requestAllOutdated(null);

        self::assertSame('v0.4.3', $result['target_version']);
        self::assertSame(1, $result['scheduled']);
        self::assertSame(1, $result['already_running']);
        self::assertSame(1, $result['manual_required']);
        self::assertSame([$this->serverId], $result['scheduled_server_ids']);
        self::assertSame('pending', $this->service->statusForServer($this->serverId)['state']);
        self::assertSame('pending', $this->service->statusForServer($activeId)['state']);
        self::assertSame('manual_required', $this->service->statusForServer($manualId)['state']);
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

    private function catalog(string $version = 'v0.4.3'): AgentArtifactCatalog
    {
        $directory = sys_get_temp_dir() . '/mirvmon-update-service-' . bin2hex(random_bytes(8));
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
