<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class AgentCredentialIssuer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tokenKey
    ) {
        if (strlen($this->tokenKey) !== 32) {
            throw new RuntimeException('Agent token key must contain exactly 32 bytes.');
        }
    }

    public function issueInstaller(int $serverId, int $lifetimeSeconds = 3600): string
    {
        if ($serverId < 1 || $lifetimeSeconds < 60 || $lifetimeSeconds > 86400) {
            throw new RuntimeException('Invalid installer credential parameters.');
        }

        $this->ensureToken($serverId);
        if (!$this->canIssueInstaller($serverId)) {
            throw new RuntimeException('Agent credential requires explicit rotation.');
        }
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable())
            ->modify('+' . $lifetimeSeconds . ' seconds')
            ->format('Y-m-d H:i:s.uP');
        $statement = $this->pdo->prepare(
            'INSERT INTO installer_tokens (server_id, token_hash, expires_at)
             VALUES (:server_id, :token_hash, :expires_at)'
        );
        $statement->execute([
            'server_id' => $serverId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    public function canIssueInstaller(int $serverId): bool
    {
        if ($serverId < 1) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT token_hash, token_generation
             FROM agent_tokens
             WHERE server_id = :server_id'
        );
        $statement->execute(['server_id' => $serverId]);
        $row = $statement->fetch();
        if (!is_array($row) || $row['token_generation'] === null) {
            return false;
        }

        $expectedHash = hash(
            'sha256',
            $this->agentToken($serverId, (int) $row['token_generation'])
        );

        return hash_equals((string) $row['token_hash'], $expectedHash);
    }

    public function exchange(string $installerToken): AgentCredential
    {
        if (preg_match('/^[a-f0-9]{64}$/', $installerToken) !== 1) {
            throw new RuntimeException('Invalid or expired installer credential.');
        }

        $ownsTransaction = $this->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT server_id
                 FROM installer_tokens
                 WHERE token_hash = :token_hash
                   AND consumed_at IS NULL
                   AND expires_at > CURRENT_TIMESTAMP
                 FOR UPDATE'
            );
            $statement->execute([
                'token_hash' => hash('sha256', $installerToken),
            ]);
            $serverId = $statement->fetchColumn();
            if ($serverId === false) {
                throw new RuntimeException('Invalid or expired installer credential.');
            }

            $consume = $this->pdo->prepare(
                'UPDATE installer_tokens
                 SET consumed_at = CURRENT_TIMESTAMP
                 WHERE token_hash = :token_hash'
            );
            $consume->execute([
                'token_hash' => hash('sha256', $installerToken),
            ]);

            $generation = $this->generation((int) $serverId);
            if ($generation === null) {
                throw new RuntimeException('Legacy agent token requires explicit rotation.');
            }
            $agentToken = $this->agentToken((int) $serverId, $generation);

            $config = $this->pdo->prepare(
                "INSERT INTO agent_configs (server_id)
                 VALUES (:server_id)
                 ON CONFLICT (server_id) DO NOTHING"
            );
            $config->execute(['server_id' => (int) $serverId]);

            $this->commitTransaction($ownsTransaction);
            return new AgentCredential((int) $serverId, $agentToken);
        } catch (Throwable $exception) {
            $this->rollbackTransaction($ownsTransaction);
            throw $exception;
        }
    }

    public function validateInstaller(string $installerToken): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $installerToken) !== 1) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM installer_tokens
             WHERE token_hash = :token_hash
               AND consumed_at IS NULL
               AND expires_at > CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'token_hash' => hash('sha256', $installerToken),
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function rotate(int $serverId): void
    {
        if ($serverId < 1) {
            throw new RuntimeException('Invalid server identifier.');
        }
        $ownsTransaction = $this->beginTransaction();
        try {
            $current = $this->generation($serverId);
            $next = ($current ?? 0) + 1;
            $agent = $this->pdo->prepare(
                'INSERT INTO agent_tokens (server_id, token_hash, token_generation)
                 VALUES (:server_id, :token_hash, :generation)
                 ON CONFLICT (server_id) DO UPDATE SET
                    token_hash = EXCLUDED.token_hash,
                    token_generation = EXCLUDED.token_generation,
                    created_at = CURRENT_TIMESTAMP,
                    last_used_at = NULL'
            );
            $agent->execute([
                'server_id' => $serverId,
                'token_hash' => hash('sha256', $this->agentToken($serverId, $next)),
                'generation' => $next,
            ]);
            $expire = $this->pdo->prepare(
                'UPDATE installer_tokens SET consumed_at = CURRENT_TIMESTAMP
                 WHERE server_id = :server_id AND consumed_at IS NULL'
            );
            $expire->execute(['server_id' => $serverId]);
            $this->commitTransaction($ownsTransaction);
        } catch (Throwable $exception) {
            $this->rollbackTransaction($ownsTransaction);
            throw $exception;
        }
    }

    private function ensureToken(int $serverId): void
    {
        $generation = $this->generation($serverId);
        if ($generation !== false) {
            return;
        }
        $token = $this->agentToken($serverId, 1);
        $statement = $this->pdo->prepare(
            'INSERT INTO agent_tokens (server_id, token_hash, token_generation)
             VALUES (:server_id, :token_hash, 1)'
        );
        $statement->execute(['server_id' => $serverId, 'token_hash' => hash('sha256', $token)]);
    }

    private function generation(int $serverId): int|false|null
    {
        $statement = $this->pdo->prepare(
            'SELECT token_generation FROM agent_tokens WHERE server_id = :server_id FOR UPDATE'
        );
        $statement->execute(['server_id' => $serverId]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? $value : (int) $value;
    }

    private function agentToken(int $serverId, int $generation): string
    {
        return hash_hmac('sha256', 'mirvmon-agent-token:' . $serverId . ':' . $generation, $this->tokenKey);
    }

    private function beginTransaction(): bool
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            return true;
        }

        $this->pdo->exec('SAVEPOINT agent_credential_exchange');
        return false;
    }

    private function commitTransaction(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            $this->pdo->commit();
            return;
        }

        $this->pdo->exec('RELEASE SAVEPOINT agent_credential_exchange');
    }

    private function rollbackTransaction(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return;
        }

        $this->pdo->exec('ROLLBACK TO SAVEPOINT agent_credential_exchange');
        $this->pdo->exec('RELEASE SAVEPOINT agent_credential_exchange');
    }
}
