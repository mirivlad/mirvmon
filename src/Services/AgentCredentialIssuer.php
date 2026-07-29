<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class AgentCredentialIssuer
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function issueInstaller(int $serverId, int $lifetimeSeconds = 3600): string
    {
        if ($serverId < 1 || $lifetimeSeconds < 60 || $lifetimeSeconds > 86400) {
            throw new RuntimeException('Invalid installer credential parameters.');
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

            $agentToken = bin2hex(random_bytes(32));
            $agent = $this->pdo->prepare(
                'INSERT INTO agent_tokens (server_id, token_hash)
                 VALUES (:server_id, :token_hash)
                 ON CONFLICT (server_id) DO UPDATE SET
                    token_hash = EXCLUDED.token_hash,
                    created_at = CURRENT_TIMESTAMP,
                    last_used_at = NULL'
            );
            $agent->execute([
                'server_id' => (int) $serverId,
                'token_hash' => hash('sha256', $agentToken),
            ]);

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
