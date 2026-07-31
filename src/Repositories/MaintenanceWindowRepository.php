<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * A planned restart trips every threshold it touches. During a window the
 * alerts are still recorded — the history stays honest — but nothing is
 * queued for delivery.
 */
final class MaintenanceWindowRepository
{
    private const MAX_DURATION_SECONDS = 604800;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function start(
        int $serverId,
        int $durationSeconds,
        ?string $reason,
        ?string $createdBy
    ): void {
        if ($durationSeconds < 60 || $durationSeconds > self::MAX_DURATION_SECONDS) {
            throw new InvalidArgumentException(
                'Окно обслуживания должно длиться от минуты до недели'
            );
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO maintenance_windows (
                server_id,
                ends_at,
                reason,
                created_by
             ) VALUES (
                :server_id,
                CURRENT_TIMESTAMP + CAST(:duration AS integer) * INTERVAL '1 second',
                :reason,
                :created_by
             )"
        );
        $statement->execute([
            'server_id' => $serverId,
            'duration' => $durationSeconds,
            'reason' => $this->trimmed($reason, 255),
            'created_by' => $this->trimmed($createdBy, 80),
        ]);
    }

    /** @return int Windows closed. */
    public function cancel(int $serverId): int
    {
        $statement = $this->pdo->prepare(
            // GREATEST keeps the period valid when a window is cancelled
            // within the transaction that opened it.
            'UPDATE maintenance_windows
             SET ends_at = GREATEST(starts_at, CURRENT_TIMESTAMP)
             WHERE server_id = :server_id
               AND starts_at <= CURRENT_TIMESTAMP
               AND ends_at > CURRENT_TIMESTAMP'
        );
        $statement->execute(['server_id' => $serverId]);

        return $statement->rowCount();
    }

    /** @return null|array{id: int, ends_at: string, reason: ?string, created_by: ?string} */
    public function active(int $serverId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, ends_at, reason, created_by
             FROM maintenance_windows
             WHERE server_id = :server_id
               AND starts_at <= CURRENT_TIMESTAMP
               AND ends_at > CURRENT_TIMESTAMP
             ORDER BY ends_at DESC
             LIMIT 1'
        );
        $statement->execute(['server_id' => $serverId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'ends_at' => (string) $row['ends_at'],
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
            'created_by' => $row['created_by'] === null ? null : (string) $row['created_by'],
        ];
    }

    /** @return list<int> Servers currently under maintenance. */
    public function activeServerIds(): array
    {
        $rows = $this->pdo->query(
            'SELECT DISTINCT server_id
             FROM maintenance_windows
             WHERE starts_at <= CURRENT_TIMESTAMP
               AND ends_at > CURRENT_TIMESTAMP'
        )?->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_map(static fn (mixed $id): int => (int) $id, $rows);
    }

    private function trimmed(?string $value, int $maximum): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $maximum);
    }

    public static function humanEnd(string $endsAt): string
    {
        return (new DateTimeImmutable($endsAt))->format('d.m.Y H:i');
    }
}
