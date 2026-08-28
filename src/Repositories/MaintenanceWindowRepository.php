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
        $this->startForSource('server_id', $serverId, $durationSeconds, $reason, $createdBy);
    }

    public function startWebsite(
        int $websiteId,
        int $durationSeconds,
        ?string $reason,
        ?string $createdBy
    ): void {
        $this->startForSource('website_id', $websiteId, $durationSeconds, $reason, $createdBy);
    }

    private function startForSource(
        string $column,
        int $sourceId,
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
                {$column},
                ends_at,
                reason,
                created_by
             ) VALUES (
                :source_id,
                CURRENT_TIMESTAMP + CAST(:duration AS integer) * INTERVAL '1 second',
                :reason,
                :created_by
             )"
        );
        $statement->execute([
            'source_id' => $sourceId,
            'duration' => $durationSeconds,
            'reason' => $this->trimmed($reason, 255),
            'created_by' => $this->trimmed($createdBy, 80),
        ]);
    }

    /** @return int Windows closed. */
    public function cancel(int $serverId): int
    {
        return $this->cancelForSource('server_id', $serverId);
    }

    public function cancelWebsite(int $websiteId): int
    {
        return $this->cancelForSource('website_id', $websiteId);
    }

    /** @return int Windows closed. */
    private function cancelForSource(string $column, int $sourceId): int
    {
        $statement = $this->pdo->prepare(
            // GREATEST keeps the period valid when a window is cancelled
            // within the transaction that opened it.
            "UPDATE maintenance_windows
             SET ends_at = GREATEST(starts_at, CURRENT_TIMESTAMP)
             WHERE {$column} = :source_id
               AND starts_at <= CURRENT_TIMESTAMP
               AND ends_at > CURRENT_TIMESTAMP"
        );
        $statement->execute(['source_id' => $sourceId]);

        return $statement->rowCount();
    }

    /** @return null|array{id: int, ends_at: string, reason: ?string, created_by: ?string} */
    public function active(int $serverId): ?array
    {
        return $this->activeForSource('server_id', $serverId);
    }

    /** @return null|array{id: int, ends_at: string, reason: ?string, created_by: ?string} */
    public function activeWebsite(int $websiteId): ?array
    {
        return $this->activeForSource('website_id', $websiteId);
    }

    /** @return null|array{id: int, ends_at: string, reason: ?string, created_by: ?string} */
    private function activeForSource(string $column, int $sourceId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, ends_at, reason, created_by
             FROM maintenance_windows
             WHERE {$column} = :source_id
               AND starts_at <= CURRENT_TIMESTAMP
               AND ends_at > CURRENT_TIMESTAMP
             ORDER BY ends_at DESC
             LIMIT 1"
        );
        $statement->execute(['source_id' => $sourceId]);
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
