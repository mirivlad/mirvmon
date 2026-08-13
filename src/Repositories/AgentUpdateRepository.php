<?php

declare(strict_types=1);

namespace App\Repositories;

use InvalidArgumentException;
use PDO;
use Throwable;

final class AgentUpdateRepository
{
    /** @var array<string, string> */
    private const NEXT_STATE = [
        'pending' => 'accepted',
        'accepted' => 'downloading',
        'downloading' => 'installing',
        'installing' => 'awaiting_restart',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function create(
        int $serverId,
        string $targetVersion,
        string $targetArtifact,
        ?int $requestedBy
    ): array {
        if ($this->activeForServer($serverId) !== null) {
            throw new InvalidArgumentException('An update is already active.');
        }
        $id = $this->uuidV4();
        $statement = $this->pdo->prepare(
            "INSERT INTO agent_update_commands (
                id,
                server_id,
                target_version,
                target_artifact,
                requested_by
             ) VALUES (
                CAST(:id AS uuid),
                :server_id,
                :target_version,
                :target_artifact,
                :requested_by
             )
             RETURNING *"
        );
        $statement->execute([
            'id' => $id,
            'server_id' => $serverId,
            'target_version' => $targetVersion,
            'target_artifact' => $targetArtifact,
            'requested_by' => $requestedBy,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new InvalidArgumentException('Cannot create update command.');
        }

        return $this->normalize($row);
    }

    /** @return array<string, mixed>|null */
    public function activeForServer(int $serverId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT *
             FROM agent_update_commands
             WHERE server_id = :server_id
               AND state NOT IN ('succeeded', 'failed')
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $statement->execute(['server_id' => $serverId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    public function supersedePending(string $id, int $serverId): bool
    {
        return $this->withLockedCommand(
            $id,
            $serverId,
            function (array $command): bool {
                if ($command['state'] === 'failed') {
                    return $command['error_code'] === 'target_superseded';
                }
                if ($command['state'] !== 'pending') {
                    return false;
                }
                $statement = $this->pdo->prepare(
                    "UPDATE agent_update_commands
                     SET state = 'failed',
                         error_code = 'target_superseded',
                         completed_at = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = CAST(:id AS uuid)"
                );
                $statement->execute(['id' => $command['id']]);

                return $statement->rowCount() === 1;
            }
        );
    }

    /** @return array<string, mixed>|null */
    public function latestForServer(int $serverId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM agent_update_commands
             WHERE server_id = :server_id
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $statement->execute(['server_id' => $serverId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param list<int> $serverIds
     * @return array<int, array<string, mixed>>
     */
    public function latestForServers(array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }
        $placeholders = [];
        $parameters = [];
        foreach (array_values(array_unique($serverIds)) as $index => $serverId) {
            $key = 'server_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $serverId;
        }
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT ON (server_id) *
             FROM agent_update_commands
             WHERE server_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY server_id, created_at DESC'
        );
        $statement->execute($parameters);
        $commands = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $command = $this->normalize($row);
                $commands[$command['server_id']] = $command;
            }
        }

        return $commands;
    }

    public function advance(string $id, int $serverId, string $state): bool
    {
        return $this->withLockedCommand(
            $id,
            $serverId,
            function (array $command) use ($state): bool {
                if ($command['state'] === $state) {
                    return true;
                }
                if ($command['state'] === 'succeeded') {
                    return true;
                }
                $expected = self::NEXT_STATE[(string) $command['state']] ?? null;
                if ($expected !== $state) {
                    throw new InvalidArgumentException('Invalid update transition.');
                }
                $statement = $this->pdo->prepare(
                    'UPDATE agent_update_commands
                     SET state = :state,
                         error_code = NULL,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = CAST(:id AS uuid)'
                );
                $statement->execute(['id' => $command['id'], 'state' => $state]);

                return $statement->rowCount() === 1;
            }
        );
    }

    public function fail(string $id, int $serverId, string $errorCode): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $errorCode) !== 1) {
            throw new InvalidArgumentException('Invalid update error code.');
        }

        return $this->withLockedCommand(
            $id,
            $serverId,
            function (array $command) use ($errorCode): bool {
                if ($command['state'] === 'succeeded') {
                    return true;
                }
                if ($command['state'] === 'failed') {
                    return $command['error_code'] === $errorCode;
                }
                $statement = $this->pdo->prepare(
                    "UPDATE agent_update_commands
                     SET state = 'failed',
                         error_code = :error_code,
                         completed_at = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = CAST(:id AS uuid)"
                );
                $statement->execute([
                    'id' => $command['id'],
                    'error_code' => $errorCode,
                ]);

                return $statement->rowCount() === 1;
            }
        );
    }

    public function complete(string $id, int $serverId): bool
    {
        return $this->withLockedCommand(
            $id,
            $serverId,
            function (array $command): bool {
                if ($command['state'] === 'succeeded') {
                    return true;
                }
                $statement = $this->pdo->prepare(
                    "UPDATE agent_update_commands
                     SET state = 'succeeded',
                         error_code = NULL,
                         completed_at = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = CAST(:id AS uuid)"
                );
                $statement->execute(['id' => $command['id']]);

                return $statement->rowCount() === 1;
            }
        );
    }

    /** @param callable(array<string, mixed>): bool $callback */
    private function withLockedCommand(
        string $id,
        int $serverId,
        callable $callback
    ): bool {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT *
                 FROM agent_update_commands
                 WHERE id = CAST(:id AS uuid)
                   AND server_id = :server_id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $id, 'server_id' => $serverId]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return false;
            }
            $result = $callback($this->normalize($row));
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $row['server_id'] = (int) $row['server_id'];
        $row['requested_by'] = $row['requested_by'] === null
            ? null
            : (int) $row['requested_by'];

        return $row;
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20);
    }
}
