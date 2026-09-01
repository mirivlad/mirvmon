<?php

declare(strict_types=1);

namespace App\Backup;

use PDO;
use RuntimeException;
use Throwable;

final class RestoredDatabaseNormalizer
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Removes installation/process-local state that must not be resurrected on B.
     * Domain state, metric/site history, permanent agent credentials, incidents,
     * audit records and update history are intentionally preserved.
     */
    public function normalize(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM login_attempts');
            $this->pdo->exec('DELETE FROM worker_heartbeats');
            $this->pdo->exec(
                "UPDATE installer_tokens
                 SET consumed_at = CURRENT_TIMESTAMP
                 WHERE consumed_at IS NULL"
            );
            $this->pdo->exec(
                "UPDATE notification_outbox
                 SET status = 'failed',
                     locked_at = NULL,
                     available_at = LEAST(available_at, CURRENT_TIMESTAMP),
                     last_error = 'restored_after_interrupted_delivery'
                 WHERE status = 'processing'"
            );
            $this->pdo->exec(
                "UPDATE website_check_jobs
                 SET state = 'pending',
                     lease_owner = NULL,
                     lease_until = NULL,
                     available_at = LEAST(available_at, CURRENT_TIMESTAMP),
                     safe_error_kind = 'restored_after_interrupted_check'
                 WHERE state = 'leased'"
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $manifest */
    public function assertIntegrity(array $manifest): void
    {
        $stats = $manifest['stats'] ?? null;
        if (!is_array($stats)) {
            throw new RuntimeException('Backup statistics are missing during restore integrity check.');
        }

        foreach (['users', 'servers', 'websites'] as $table) {
            $expected = $stats[$table] ?? null;
            if (!is_int($expected) || $expected < 0) {
                throw new RuntimeException('Invalid backup statistic during restore: ' . $table);
            }
            $actual = (int) ($this->pdo->query('SELECT count(*) FROM ' . $table)?->fetchColumn() ?: 0);
            if ($actual !== $expected) {
                throw new RuntimeException(sprintf(
                    'Restored %s count differs from backup manifest: expected %d, got %d.',
                    $table,
                    $expected,
                    $actual
                ));
            }
        }

        $orphanTokens = (int) ($this->pdo->query(
            'SELECT count(*)
             FROM agent_tokens AS tokens
             LEFT JOIN servers ON servers.id = tokens.server_id
             WHERE servers.id IS NULL'
        )?->fetchColumn() ?: 0);
        if ($orphanTokens !== 0) {
            throw new RuntimeException('Restored agent credentials contain orphaned server references.');
        }

        $timescale = $this->pdo->query(
            "SELECT extversion FROM pg_extension WHERE extname = 'timescaledb'"
        )?->fetchColumn();
        if (!is_string($timescale) || $timescale === '') {
            throw new RuntimeException('TimescaleDB extension is missing after restore.');
        }
    }
}
