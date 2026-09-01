<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AgentUpdateRepository;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;

final class AgentUpdateService
{
    /** @var list<string> */
    private const ACTIVE_STATES = [
        'pending',
        'accepted',
        'downloading',
        'installing',
        'awaiting_restart',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AgentUpdateRepository $commands,
        private readonly AgentVersionService $versions,
        private readonly AgentArtifactCatalog $artifacts
    ) {
    }

    /** @return array<string, mixed> */
    public function statusForServer(int $serverId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT agent_version, agent_artifact, agent_capabilities
             FROM servers
             WHERE id = :server_id'
        );
        $statement->execute(['server_id' => $serverId]);
        $server = $statement->fetch();
        if (!is_array($server)) {
            throw new InvalidArgumentException('Server was not found.');
        }

        return $this->buildStatus(
            $server,
            $this->commands->latestForServer($serverId)
        );
    }

    /**
     * @param list<int> $serverIds
     * @return array<int, array<string, mixed>>
     */
    public function statusesForServers(array $serverIds): array
    {
        $serverIds = array_values(array_unique(array_filter(
            $serverIds,
            static fn (int $id): bool => $id > 0
        )));
        if ($serverIds === []) {
            return [];
        }
        $placeholders = [];
        $parameters = [];
        foreach ($serverIds as $index => $serverId) {
            $key = 'server_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $serverId;
        }
        $statement = $this->pdo->prepare(
            'SELECT id, agent_version, agent_artifact, agent_capabilities
             FROM servers
             WHERE id IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($parameters);
        $commands = $this->commands->latestForServers($serverIds);
        $statuses = [];
        foreach ($statement->fetchAll() as $server) {
            if (!is_array($server)) {
                continue;
            }
            $serverId = (int) $server['id'];
            $statuses[$serverId] = $this->buildStatus(
                $server,
                $commands[$serverId] ?? null
            );
        }

        return $statuses;
    }

    /**
     * @param array<int, array<string, mixed>> $statuses
     * @return array<string, int|string>
     */
    public function summarizeStatuses(array $statuses): array
    {
        $summary = [
            'target_version' => $this->artifacts->version(),
            'eligible' => 0,
            'already_running' => 0,
            'manual_required' => 0,
        ];
        foreach ($statuses as $status) {
            $state = is_string($status['state'] ?? null) ? $status['state'] : 'unknown';
            if (in_array($state, self::ACTIVE_STATES, true)) {
                $summary['already_running']++;
                continue;
            }
            if ($this->isBulkActionable($status)) {
                $summary['eligible']++;
                continue;
            }
            if (($status['is_outdated'] ?? null) === true) {
                $summary['manual_required']++;
            }
        }

        return $summary;
    }

    /** @return array<string, mixed> */
    public function requestAllOutdated(?int $requestedBy): array
    {
        $serverIds = $this->allServerIds();
        $statuses = $this->statusesForServers($serverIds);
        $result = [
            'target_version' => $this->artifacts->version(),
            'scheduled' => 0,
            'already_running' => 0,
            'manual_required' => 0,
            'scheduled_server_ids' => [],
        ];

        foreach ($statuses as $serverId => $status) {
            $state = is_string($status['state'] ?? null) ? $status['state'] : 'unknown';
            if (in_array($state, self::ACTIVE_STATES, true)) {
                $result['already_running']++;
                continue;
            }
            if (!$this->isBulkActionable($status)) {
                if (($status['is_outdated'] ?? null) === true) {
                    $result['manual_required']++;
                }
                continue;
            }

            try {
                $this->request((int) $serverId, $requestedBy);
                $result['scheduled']++;
                $result['scheduled_server_ids'][] = (int) $serverId;
            } catch (InvalidArgumentException) {
                $fresh = $this->statusForServer((int) $serverId);
                $freshState = is_string($fresh['state'] ?? null) ? $fresh['state'] : 'unknown';
                if (in_array($freshState, self::ACTIVE_STATES, true)) {
                    $result['already_running']++;
                } elseif (($fresh['is_outdated'] ?? null) === true) {
                    $result['manual_required']++;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed>|null $latest
     * @return array<string, mixed>
     */
    private function buildStatus(array $server, ?array $latest): array
    {
        $installed = is_string($server['agent_version'] ?? null)
            ? $server['agent_version']
            : null;
        $artifactKey = is_string($server['agent_artifact'] ?? null)
            ? $server['agent_artifact']
            : null;
        $capabilities = $this->stringList($server['agent_capabilities'] ?? []);
        $available = $this->artifacts->version();
        $isNewer = $installed !== null
            && $this->versions->isUpgrade($installed, $available);
        $targetSupported = $installed !== null
            && $this->versions->supportsSelfUpdateTarget($installed, $available);
        $hasCapability = in_array('self_update_v1', $capabilities, true);
        $artifactExists = $artifactKey !== null && $this->artifactExists($artifactKey);
        $canUpdate = $isNewer && $targetSupported && $hasCapability && $artifactExists;

        $state = 'current';
        if ($installed === null) {
            $state = 'unknown';
        } elseif ($isNewer && !$canUpdate) {
            $state = 'manual_required';
        } elseif ($canUpdate) {
            $state = 'update_available';
        }
        if ($latest !== null && !in_array($latest['state'], ['succeeded'], true)) {
            $state = (string) $latest['state'];
        }

        return [
            'installed_version' => $installed,
            'available_version' => $available,
            'artifact' => $artifactKey,
            'capabilities' => $capabilities,
            'can_update' => $canUpdate,
            'is_outdated' => $installed === null ? null : $isNewer,
            'requires_compatible_updater' => $isNewer && !$targetSupported,
            'state' => $state,
            'command' => $latest,
        ];
    }

    /** @return array<string, mixed> */
    public function request(int $serverId, ?int $requestedBy): array
    {
        $status = $this->statusForServer($serverId);
        if (!$status['can_update'] || !is_string($status['artifact'])) {
            throw new InvalidArgumentException('Agent cannot be updated remotely.');
        }

        return $this->commands->create(
            $serverId,
            $this->artifacts->version(),
            $status['artifact'],
            $requestedBy
        );
    }

    /** @return array<string, int|string>|null */
    public function commandForServer(int $serverId): ?array
    {
        $command = $this->commands->activeForServer($serverId);
        if ($command === null) {
            return null;
        }
        if (!is_string($command['target_artifact'])) {
            return null;
        }
        try {
            $artifact = $this->artifacts->require($command['target_artifact']);
        } catch (RuntimeException) {
            return null;
        }
        if ($command['target_version'] !== $this->artifacts->version()) {
            $this->commands->supersedePending((string) $command['id'], $serverId);
            return null;
        }

        return [
            'id' => (string) $command['id'],
            'target_version' => (string) $command['target_version'],
            'artifact' => $artifact->key,
            'sha256' => $artifact->sha256,
            'size' => $artifact->size,
        ];
    }

    public function report(
        string $token,
        string $commandId,
        string $state,
        ?string $errorCode
    ): bool {
        $statement = $this->pdo->prepare(
            'SELECT server_id
             FROM agent_tokens
             WHERE token_hash = :token_hash'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $serverId = $statement->fetchColumn();
        if ($serverId === false) {
            return false;
        }
        if ($state === 'failed') {
            return $this->commands->fail(
                $commandId,
                (int) $serverId,
                $errorCode ?? 'update_failed'
            );
        }
        if (!in_array(
            $state,
            ['accepted', 'downloading', 'installing', 'awaiting_restart'],
            true
        )) {
            throw new InvalidArgumentException('Invalid update state.');
        }

        return $this->commands->advance($commandId, (int) $serverId, $state);
    }

    /** @return list<int> */
    private function allServerIds(): array
    {
        $statement = $this->pdo->query('SELECT id FROM servers ORDER BY id');
        if ($statement === false) {
            return [];
        }

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string, mixed> $status */
    private function isBulkActionable(array $status): bool
    {
        return ($status['can_update'] ?? false) === true
            && in_array($status['state'] ?? null, ['update_available', 'failed'], true);
    }

    private function artifactExists(string $key): bool
    {
        try {
            $this->artifacts->require($key);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        try {
            $decoded = is_array($value)
                ? $value
                : json_decode((string) $value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }
}
