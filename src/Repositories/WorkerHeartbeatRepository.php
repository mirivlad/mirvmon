<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * A worker that dies silently is indistinguishable from an idle one: the
 * queue simply stops draining. Each loop iteration leaves a timestamp so the
 * administration page can say which background process is still running.
 */
final class WorkerHeartbeatRepository
{
    public const NOTIFICATION_WORKER = 'notification-worker';
    public const OFFLINE_WORKER = 'offline-worker';
    public const WEBSITE_CHECK_WORKER = 'website-check-worker';

    /** A worker is late once it has missed this many seconds of ticks. */
    private const STALE_AFTER_SECONDS = 120;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $worker): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO worker_heartbeats (worker)
             VALUES (:worker)
             ON CONFLICT (worker) DO UPDATE
             SET last_tick_at = CURRENT_TIMESTAMP'
        );
        $statement->execute(['worker' => $worker]);
    }

    /**
     * @return list<array{
     *     worker: string,
     *     last_tick_at: string,
     *     started_at: string,
     *     seconds_since_tick: int,
     *     stale: bool
     * }>
     */
    public function all(): array
    {
        $rows = $this->pdo->query(
            "SELECT
                worker,
                last_tick_at,
                started_at,
                CAST(
                    EXTRACT(EPOCH FROM CURRENT_TIMESTAMP - last_tick_at) AS integer
                ) AS seconds_since_tick
             FROM worker_heartbeats
             ORDER BY worker"
        )?->fetchAll() ?: [];

        $heartbeats = [];
        foreach ($rows as $row) {
            $seconds = (int) $row['seconds_since_tick'];
            $heartbeats[] = [
                'worker' => (string) $row['worker'],
                'last_tick_at' => (string) $row['last_tick_at'],
                'started_at' => (string) $row['started_at'],
                'seconds_since_tick' => $seconds,
                'stale' => $seconds > self::STALE_AFTER_SECONDS,
            ];
        }

        return $heartbeats;
    }
}
