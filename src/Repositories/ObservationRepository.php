<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class ObservationRepository
{
    private const RESOLUTION_GRACE_SECONDS = 1200;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array{id:int,status:string,notification_cycle:int,should_notify:bool}
     */
    public function recordCandidate(
        int $serverId,
        int $metricId,
        array $candidate,
        DateTimeImmutable $now
    ): array {
        $ownsTransaction = $this->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT id, kind, status, recurrence_count, notification_cycle, notified_at
                 FROM observations
                 WHERE server_id = :server_id AND fingerprint = :fingerprint
                 FOR UPDATE'
            );
            $select->execute([
                'server_id' => $serverId,
                'fingerprint' => (string) $candidate['fingerprint'],
            ]);
            $existing = $select->fetch();
            if (!is_array($existing)) {
                $result = $this->insertCandidate(
                    $serverId,
                    $metricId,
                    $candidate,
                    $now
                );
                $this->commitTransaction($ownsTransaction);
                return $result;
            }

            $result = $this->refreshCandidate(
                $existing,
                $metricId,
                $candidate,
                $now
            );
            $this->commitTransaction($ownsTransaction);
            return $result;
        } catch (Throwable $exception) {
            $this->rollbackTransaction($ownsTransaction);
            throw $exception;
        }
    }
    /**
     * @param array<string, mixed> $candidate
     * @return array{id:int,status:string,notification_cycle:int,should_notify:bool}
     */
    private function insertCandidate(
        int $serverId,
        int $metricId,
        array $candidate,
        DateTimeImmutable $now
    ): array {
        $statement = $this->pdo->prepare(
            "INSERT INTO observations (
                server_id, metric_id, kind, detector, fingerprint, status,
                current_value, baseline_value, confidence, forecast_at, details,
                first_seen_at, last_seen_at
             ) VALUES (
                :server_id, :metric_id, :kind, :detector, :fingerprint, 'active',
                :current_value, :baseline_value, :confidence, :forecast_at,
                CAST(:details AS jsonb), :first_seen_at, :last_seen_at
             )
             RETURNING id, notification_cycle"
        );
        $statement->execute($this->candidateParams(
            $serverId,
            $metricId,
            $candidate,
            $now
        ));
        $created = $statement->fetch();
        if (!is_array($created)) {
            throw new RuntimeException('Failed to create observation.');
        }

        return [
            'id' => (int) $created['id'],
            'status' => 'active',
            'notification_cycle' => (int) $created['notification_cycle'],
            'should_notify' => true,
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $candidate
     * @return array{id:int,status:string,notification_cycle:int,should_notify:bool}
     */
    private function refreshCandidate(        array $existing,
        int $metricId,
        array $candidate,
        DateTimeImmutable $now
    ): array {
        $status = (string) $existing['status'];
        $kind = (string) $existing['kind'];
        $cycle = (int) $existing['notification_cycle'];
        $recurrence = (int) $existing['recurrence_count'];
        $notifiedAt = $existing['notified_at'];
        $shouldNotify = false;
        $newStatus = $status;

        if ($status === 'resolved') {
            $newStatus = 'active';
            $recurrence++;
            if ($kind === 'prediction') {
                $cycle++;
                $notifiedAt = null;
                $shouldNotify = true;
            } else {
                $shouldNotify = $notifiedAt === null;
            }
        } elseif ($status === 'active') {
            $shouldNotify = $notifiedAt === null;
        }
        $statement = $this->pdo->prepare(
            'UPDATE observations
             SET metric_id = :metric_id,
                 status = :status,
                 current_value = :current_value,
                 baseline_value = :baseline_value,
                 confidence = :confidence,
                 forecast_at = :forecast_at,
                 details = CAST(:details AS jsonb),
                 recurrence_count = :recurrence_count,
                 notification_cycle = :notification_cycle,
                 notified_at = :notified_at,
                 last_seen_at = :last_seen_at,
                 resolved_at = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $statement->execute([
            'metric_id' => $metricId,
            'status' => $newStatus,
            'current_value' => $candidate['current_value'] ?? null,
            'baseline_value' => $candidate['baseline_value'] ?? null,
            'confidence' => $candidate['confidence'] ?? null,
            'forecast_at' => $candidate['forecast_at'] ?? null,
            'details' => $this->json($candidate['details'] ?? []),            'recurrence_count' => $recurrence,
            'notification_cycle' => $cycle,
            'notified_at' => $notifiedAt,
            'last_seen_at' => $this->timestamp($now),
            'updated_at' => $this->timestamp($now),
            'id' => (int) $existing['id'],
        ]);

        return [
            'id' => (int) $existing['id'],
            'status' => $newStatus,
            'notification_cycle' => $cycle,
            'should_notify' => $shouldNotify,
        ];
    }

    /**
     * @param list<string> $seenKeys server_id:fingerprint
     * @param list<string> $evaluatedMetricKeys server_id:metric_id
     */
    public function resolveMissing(
        array $seenKeys,
        array $evaluatedMetricKeys,
        DateTimeImmutable $now
    ): int {
        if ($evaluatedMetricKeys === []) {
            return 0;
        }

        $cutoff = $now->modify('-' . self::RESOLUTION_GRACE_SECONDS . ' seconds');
        $params = [
            'cutoff' => $this->timestamp($cutoff),
            'resolved_at' => $this->timestamp($now),
        ];
        $evaluatedPlaceholders = [];
        foreach (array_values(array_unique($evaluatedMetricKeys)) as $index => $evaluatedKey) {
            $key = 'evaluated_' . $index;
            $evaluatedPlaceholders[] = ':' . $key;
            $params[$key] = $evaluatedKey;
        }

        $seenFilter = '';
        if ($seenKeys !== []) {
            $seenPlaceholders = [];
            foreach (array_values(array_unique($seenKeys)) as $index => $seenKey) {
                $key = 'seen_' . $index;
                $seenPlaceholders[] = ':' . $key;
                $params[$key] = $seenKey;
            }
            $seenFilter = " AND (observations.server_id::text || ':' || observations.fingerprint) NOT IN ("
                . implode(', ', $seenPlaceholders) . ')';
        }

        $statement = $this->pdo->prepare(
            "UPDATE observations
             SET status = 'resolved',
                 resolved_at = :resolved_at,
                 updated_at = :resolved_at
             WHERE status IN ('active', 'handled')
               AND last_seen_at <= :cutoff
               AND (server_id::text || ':' || metric_id::text) IN ("
                . implode(', ', $evaluatedPlaceholders) . "){$seenFilter}
               AND NOT EXISTS (
                   SELECT 1
                   FROM maintenance_windows AS maintenance
                   WHERE maintenance.server_id = observations.server_id
                     AND maintenance.starts_at <= :resolved_at
                     AND maintenance.ends_at > :resolved_at
               )"
        );
        $statement->execute($params);
        return $statement->rowCount();
    }

    public function markNotified(int $id, int $cycle, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE observations
             SET notified_at = :notified_at, updated_at = :updated_at
             WHERE id = :id AND notification_cycle = :cycle'
        );
        $statement->execute([
            'id' => $id,
            'cycle' => $cycle,
            'notified_at' => $this->timestamp($now),
            'updated_at' => $this->timestamp($now),
        ]);
    }

    public function handlePrediction(int $id, ?int $userId, ?string $username): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE observations
             SET status = 'handled',
                 handled_at = CURRENT_TIMESTAMP,
                 handled_by_user_id = :user_id,
                 handled_by_username = :username,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND kind = 'prediction' AND status = 'active'"
        );
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'username' => $this->username($username),
        ]);
        return $statement->rowCount() === 1;
    }

    public function acceptNormal(int $id, ?int $userId, ?string $username): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE observations
             SET status = 'accepted_normal',
                 accepted_at = CURRENT_TIMESTAMP,
                 accepted_by_user_id = :user_id,
                 accepted_by_username = :username,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND kind = 'anomaly' AND status IN ('active', 'resolved')"
        );
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'username' => $this->username($username),
        ]);
        return $statement->rowCount() === 1;
    }

    public function resetAcceptedNormal(int $id): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE observations
             SET status = 'resolved',
                 accepted_at = NULL,
                 accepted_by_user_id = NULL,
                 accepted_by_username = NULL,
                 notified_at = NULL,
                 resolved_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'accepted_normal'"
        );
        $statement->execute(['id' => $id]);
        return $statement->rowCount() === 1;
    }

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        return $this->rows("observations.status = 'active'", 'observations.last_seen_at DESC');
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return $this->rows(
            "observations.status IN ('handled', 'resolved')",
            'COALESCE(observations.resolved_at, observations.handled_at, observations.last_seen_at) DESC'
        );
    }

    /** @return list<array<string, mixed>> */
    public function acceptedNormal(): array
    {
        return $this->rows(
            "observations.status = 'accepted_normal'",
            'observations.accepted_at DESC NULLS LAST'
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $where, string $orderBy): array
    {
        $statement = $this->pdo->query(
            "SELECT observations.*, servers.name AS server_name,
                    metric_names.name AS metric_name, metric_names.unit AS metric_unit
             FROM observations
             INNER JOIN servers ON servers.id = observations.server_id
             LEFT JOIN metric_names ON metric_names.id = observations.metric_id
             WHERE {$where}
             ORDER BY {$orderBy}
             LIMIT 500"
        );
        if ($statement === false) {
            return [];
        }
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $details = $row['details'] ?? null;
            if (is_string($details)) {
                try {
                    $decoded = json_decode($details, true, 512, JSON_THROW_ON_ERROR);
                    $row['details'] = is_array($decoded) ? $decoded : [];
                } catch (JsonException) {
                    $row['details'] = [];
                }
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, int|float|string|null>
     */
    private function candidateParams(
        int $serverId,
        int $metricId,
        array $candidate,
        DateTimeImmutable $now
    ): array {
        return [
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'kind' => (string) $candidate['kind'],
            'detector' => (string) $candidate['detector'],
            'fingerprint' => (string) $candidate['fingerprint'],
            'current_value' => $candidate['current_value'] ?? null,
            'baseline_value' => $candidate['baseline_value'] ?? null,
            'confidence' => $candidate['confidence'] ?? null,
            'forecast_at' => $candidate['forecast_at'] ?? null,
            'details' => $this->json($candidate['details'] ?? []),
            'first_seen_at' => $this->timestamp($now),
            'last_seen_at' => $this->timestamp($now),
        ];
    }

    private function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode observation details.', 0, $exception);
        }
    }
    private function username(?string $username): ?string
    {
        if ($username === null) {
            return null;
        }
        $username = trim($username);
        return $username === '' ? null : substr($username, 0, 80);
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.uP');
    }

    private function beginTransaction(): bool
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            return true;
        }
        $this->pdo->exec('SAVEPOINT observation_repository');
        return false;
    }

    private function commitTransaction(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            $this->pdo->commit();
            return;
        }
        $this->pdo->exec('RELEASE SAVEPOINT observation_repository');
    }
    private function rollbackTransaction(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return;
        }
        $this->pdo->exec('ROLLBACK TO SAVEPOINT observation_repository');
    }
}
