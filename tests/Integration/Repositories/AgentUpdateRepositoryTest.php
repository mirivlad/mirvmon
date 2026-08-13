<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\AgentUpdateRepository;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class AgentUpdateRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AgentUpdateRepository $repository;
    private int $serverId;
    private int $userId;

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
        $this->repository = new AgentUpdateRepository(self::$pdo);
        $this->userId = (int) self::$pdo?->query(
            "INSERT INTO users (username, password_hash, role)
             VALUES ('update-repository-admin', 'hash', 'admin') RETURNING id"
        )->fetchColumn();
        $this->serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('updatable') RETURNING id"
        )->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testOnlyOneActiveCommandCanExistAndTerminalCommandAllowsRetry(): void
    {
        $command = $this->repository->create(
            $this->serverId,
            'v0.4.3',
            'linux-amd64',
            null
        );
        self::assertSame('pending', $command['state']);
        self::assertSame($command['id'], $this->repository->activeForServer(
            $this->serverId
        )['id'] ?? null);

        try {
            $this->repository->create(
                $this->serverId,
                'v0.4.4',
                'linux-amd64',
                null
            );
            self::fail('A second active command must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->repository->fail($command['id'], $this->serverId, 'checksum_mismatch');
        $retry = $this->repository->create(
            $this->serverId,
            'v0.4.3',
            'linux-amd64',
            null
        );
        self::assertNotSame($command['id'], $retry['id']);
    }

    public function testObsoletePendingCommandIsReplacedExactlyOnce(): void
    {
        $command = $this->repository->create(
            $this->serverId,
            'v0.4.6',
            'linux-amd64',
            $this->userId
        );

        $replacement = $this->repository->replacePendingTarget(
            $this->serverId,
            'v0.4.8',
            'linux-amd64'
        );

        self::assertNotNull($replacement);
        self::assertNotSame($command['id'], $replacement['id']);
        self::assertSame('v0.4.8', $replacement['target_version']);
        self::assertSame('linux-amd64', $replacement['target_artifact']);
        self::assertSame($this->userId, $replacement['requested_by']);
        self::assertSame('pending', $replacement['state']);

        $old = self::$pdo?->prepare(
            'SELECT state, error_code, completed_at
             FROM agent_update_commands
             WHERE id = CAST(:id AS uuid)'
        );
        $old?->execute(['id' => $command['id']]);
        $old = $old?->fetch();
        self::assertIsArray($old);
        self::assertSame('failed', $old['state']);
        self::assertSame('target_superseded', $old['error_code']);
        self::assertNotNull($old['completed_at']);

        $repeated = $this->repository->replacePendingTarget(
            $this->serverId,
            'v0.4.8',
            'linux-amd64'
        );
        self::assertSame($replacement['id'], $repeated['id'] ?? null);
    }

    public function testAcknowledgedCommandIsNotReplaced(): void
    {
        $command = $this->repository->create(
            $this->serverId,
            'v0.4.6',
            'linux-amd64',
            null
        );
        $this->repository->advance($command['id'], $this->serverId, 'accepted');

        $result = $this->repository->replacePendingTarget(
            $this->serverId,
            'v0.4.8',
            'linux-amd64'
        );

        self::assertSame($command['id'], $result['id'] ?? null);
        self::assertSame('v0.4.6', $result['target_version'] ?? null);
        self::assertSame('accepted', $result['state'] ?? null);
    }

    public function testTransitionsAreMonotonicAndMetricsCompleteExactVersion(): void
    {
        $command = $this->repository->create(
            $this->serverId,
            'v0.4.3',
            'windows-amd64',
            null
        );
        foreach (['accepted', 'downloading', 'installing', 'awaiting_restart'] as $state) {
            self::assertTrue($this->repository->advance(
                $command['id'],
                $this->serverId,
                $state
            ));
            self::assertTrue($this->repository->advance(
                $command['id'],
                $this->serverId,
                $state
            ));
        }

        $this->expectException(InvalidArgumentException::class);
        $this->repository->advance($command['id'], $this->serverId, 'downloading');
    }

    public function testCompleteTerminalizesAnActiveCommand(): void
    {
        $command = $this->repository->create(
            $this->serverId,
            'v0.4.3',
            'linux-amd64',
            null
        );
        $this->repository->advance($command['id'], $this->serverId, 'accepted');
        $this->repository->advance($command['id'], $this->serverId, 'downloading');
        self::assertTrue($this->repository->complete(
            $command['id'],
            $this->serverId
        ));
        self::assertSame('succeeded', $this->repository->latestForServer(
            $this->serverId
        )['state'] ?? null);
    }

    public function testLateProgressAfterCompletionIsIdempotent(): void
    {
        $command = $this->repository->create(
            $this->serverId,
            'v0.4.3',
            'linux-amd64',
            null
        );

        self::assertTrue($this->repository->complete(
            $command['id'],
            $this->serverId
        ));
        self::assertSame('succeeded', $this->repository->latestForServer(
            $this->serverId
        )['state'] ?? null);
        self::assertTrue($this->repository->advance(
            $command['id'],
            $this->serverId,
            'accepted'
        ));
        self::assertTrue($this->repository->fail(
            $command['id'],
            $this->serverId,
            'apply_failed'
        ));
    }

    public function testCompleteChangesOnlyTheSelectedAttempt(): void
    {
        $failed = $this->repository->create(
            $this->serverId,
            'v0.4.3',
            'linux-amd64',
            null
        );
        $this->repository->fail(
            $failed['id'],
            $this->serverId,
            'download_failed'
        );
        $active = $this->repository->create(
            $this->serverId,
            'v0.4.3',
            'linux-amd64',
            null
        );

        self::assertTrue($this->repository->complete(
            $active['id'],
            $this->serverId
        ));
        $failedState = self::$pdo?->prepare(
            'SELECT state FROM agent_update_commands WHERE id = CAST(:id AS uuid)'
        );
        $failedState?->execute(['id' => $failed['id']]);
        self::assertSame('failed', $failedState?->fetchColumn());
        $activeState = self::$pdo?->prepare(
            'SELECT state FROM agent_update_commands WHERE id = CAST(:id AS uuid)'
        );
        $activeState?->execute(['id' => $active['id']]);
        self::assertSame('succeeded', $activeState?->fetchColumn());
    }
}
