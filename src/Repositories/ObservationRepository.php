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
     * @return array{id:int,status:string,notification_cycle:int,should_notify:bool,resolved_siblings:int}
     */
    public function recordCandidate(
        int $serverId,
        int $metricId,
        array $candidate,
        DateTimeImmutable $now
    ): array {
        $ownsTransaction = $this->beginTransaction();
        try {
            $result = (string) ($candidate['kind'] ?? '') === 'anomaly'
                ? $this->recordAnomalyCandidate($serverId, $metricId, $candidate, $now)
                : $this->recordFingerprintCandidate($serverId, $metricId, $candidate, $now);
            $this->commitTransaction($ownsTransaction);
            return $result;
        } catch (Throwable $exception) {
            $this->rollbackTransaction($ownsTransaction);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array{id:int,status:string,notification_cycle:int,should_notify:bool,resolved_siblings:int}
     */
    private function recordFingerprintCandidate(
        int $serverId,
        int $metricId,
        array $candidate,
        DateTimeImmutable $now
    ): array {
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
            return $this->insertCandidate($serverId, $metricId, $candidate, $now);
        }
        return $this->refreshCandidate($existing, $metricId, $candidate, $now);
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array{id:int,status:string,notification_cycle:int,should_notify:bool,resolved_siblings:int}
     */
    private function recordAnomalyCandidate(
        int $serverId,
        int $metricId,
        array $candidate,
        DateTimeImmutable $now
    ): array {
        $accepted = $this->pdo->prepare(
            "SELECT id, kind, status, recurrence_count, notification_cycle, notified_at
             FROM observations
             WHERE server_id = :server_id
               AND kind = 'anomaly'
               AND fingerprint = :fingerprint
               AND status = 'accepted_normal'
             ORDER BY accepted_at DESC NULLS LAST, id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $accepted->execute([
            'server_id' => $serverId,
            'fingerprint' => (string) $candidate['fingerprint'],
        ]);
        $existing = $accepted->fetch();

        if (!is_array($existing)) {
            $episode = $this->pdo->prepare(
                "SELECT id, kind, status, recurrence_count, notification_cycle, notified_at
                 FROM observations
                 WHERE server_id = :server_id
                   AND metric_id = :metric_id
                   AND kind = 'anomaly'
                   AND detector = :detector
                   AND status IN ('active', 'handled')
                 ORDER BY last_seen_at DESC, id DESC
                 LIMIT 1
                 FOR UPDATE"
            );
            $episode->execute([
                'server_id' => $serverId,
                'metric_id' => $metricId,
                'detector' => (string) $candidate['detector'],
            ]);
            $existing = $episode->fetch();
        }

        $result = is_array($existing)
            ? $this->refreshCandidate($existing, $metricId, $candidate, $now)
            : $this->insertCandidate($serverId, $metricId, $candidate, $now);
        $result['resolved_siblings'] = $this->resolveAnomalySiblings(
            $serverId,
            $metricId,
            (string) $candidate['detector'],
            (int) $result['id'],
            $now
        );
        return $result;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array{id:int,status:string,notification_cycle:int,should_notify:bool,resolved_siblings:int}
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
            'resolved_siblings' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $candidate
     * @return array{id:int,status:string,notification_cycle:int,should_notify:bool,resolved_siblings:int}
     */
    private function refreshCandidate(
        array $existing,
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
                 fingerprint = :fingerprint,
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
            'fingerprint' => (string) $candidate['fingerprint'],
            'status' => $newStatus,
            'current_value' => $candidate['current_value'] ?? null,
            'baseline_value' => $candidate['baseline_value'] ?? null,
            'confidence' => $candidate['confidence'] ?? null,
            'forecast_at' => $candidate['forecast_at'] ?? null,
            'details' => $this->json($candidate['details'] ?? []),
            'recurrence_count' => $recurrence,
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
            'resolved_siblings' => 0,
        ];
    }

    /**
     * @param list<int> $seenObservationIds
     * @param list<string> $evaluatedMetricKeys server_id:metric_id
     */
    public function resolveMissing(
        array $seenObservationIds,
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
        if ($seenObservationIds !== []) {
            $seenPlaceholders = [];
            foreach (array_values(array_unique($seenObservationIds)) as $index => $seenObservationId) {
                $key = 'seen_' . $index;
                $seenPlaceholders[] = ':' . $key;
                $params[$key] = $seenObservationId;
            }
            $seenFilter = ' AND observations.id NOT IN ('
                . implode(', ', $seenPlaceholders) . ')';
        }

        $statement = $this->pdo->prepare(
            "UPDATE observations
             SET status = 'resolved',
                 resolved_at = :resolved_at,
                 updated_at = :resolved_at
             WHERE status IN ('active', 'handled')
               AND NOT (kind = 'anomaly' AND detector = 'level_shift_v2')
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

    /**
     * Keep an already-open contextual anomaly episode alive without creating a
     * new episode. Used for ELEVATED and INCIDENT_OWNED states.
     *
     * @param array<string,mixed> $evidence
     */
    public function touchAnomalyEpisode(
        int $serverId,
        int $metricId,
        string $detector,
        array $evidence,
        DateTimeImmutable $now
    ): ?int {
        $statement = $this->pdo->prepare(
            "UPDATE observations
             SET current_value = :current_value,
                 baseline_value = :baseline_value,
                 details = details || CAST(:details AS jsonb),
                 last_seen_at = :last_seen_at,
                 updated_at = :updated_at
             WHERE server_id = :server_id
               AND metric_id = :metric_id
               AND kind = 'anomaly'
               AND detector = :detector
               AND status IN ('active', 'handled')
             RETURNING id"
        );
        $statement->execute([
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'detector' => $detector,
            'current_value' => $evidence['current_value'] ?? null,
            'baseline_value' => $evidence['baseline_value'] ?? null,
            'details' => $this->json($evidence),
            'last_seen_at' => $this->timestamp($now),
            'updated_at' => $this->timestamp($now),
        ]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function resolveAnomalyEpisode(
        int $serverId,
        int $metricId,
        string $detector,
        DateTimeImmutable $now
    ): int {
        $statement = $this->pdo->prepare(
            "UPDATE observations
             SET status = 'resolved',
                 resolved_at = :resolved_at,
                 updated_at = :resolved_at
             WHERE server_id = :server_id
               AND metric_id = :metric_id
               AND kind = 'anomaly'
               AND detector = :detector
               AND status IN ('active', 'handled')"
        );
        $statement->execute([
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'detector' => $detector,
            'resolved_at' => $this->timestamp($now),
        ]);
        return $statement->rowCount();
    }

    /** @param list<string> $metricKeys server_id:metric_id */
    public function resolveDiskAliases(array $metricKeys, DateTimeImmutable $now): int
    {
        $metricKeys = array_values(array_unique($metricKeys));
        if ($metricKeys === []) {
            return 0;
        }

        $params = ['resolved_at' => $this->timestamp($now)];
        $placeholders = [];
        foreach ($metricKeys as $index => $metricKey) {
            $key = 'alias_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $metricKey;
        }

        $statement = $this->pdo->prepare(
            "UPDATE observations
             SET status = 'resolved', resolved_at = :resolved_at, updated_at = :resolved_at
             WHERE detector = 'disk_growth_v1'
               AND status IN ('active', 'handled')
               AND (server_id::text || ':' || metric_id::text) IN ("
                . implode(', ', $placeholders) . ')'
        );
        $statement->execute($params);
        return $statement->rowCount();
    }

    private function resolveAnomalySiblings(
        int $serverId,
        int $metricId,
        string $detector,
        int $keepId,
        DateTimeImmutable $now
    ): int {
        $statement = $this->pdo->prepare(
            "UPDATE observations
             SET status = 'resolved', resolved_at = :resolved_at, updated_at = :resolved_at
             WHERE server_id = :server_id
               AND metric_id = :metric_id
               AND detector = :detector
               AND kind = 'anomaly'
               AND status IN ('active', 'handled')
               AND id <> :keep_id"
        );
        $statement->execute([
            'server_id' => $serverId,
            'metric_id' => $metricId,
            'detector' => $detector,
            'keep_id' => $keepId,
            'resolved_at' => $this->timestamp($now),
        ]);
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

    public function handle(int $id, ?int $userId, ?string $username): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE observations
             SET status = 'handled',
                 handled_at = CURRENT_TIMESTAMP,
                 handled_by_user_id = :user_id,
                 handled_by_username = :username,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'active'"
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

    public function assess(int $id, int $recurrence, string $outcome, ?int $userId, ?string $username): bool
    {
        if (!in_array($outcome, ['actionable', 'normal', 'intervention', 'uncertain', 'clear'], true)) {
            return false;
        }
        if ($outcome === 'clear') {
            $statement = $this->pdo->prepare(
                'DELETE FROM observation_assessments AS assessment
                 USING observations
                 WHERE assessment.observation_id = observations.id
                   AND assessment.observation_id = :id
                   AND assessment.recurrence_count = :recurrence
                   AND observations.recurrence_count = :current_recurrence'
            );
            $statement->execute(['id' => $id, 'recurrence' => $recurrence, 'current_recurrence' => $recurrence]);
            return $statement->rowCount() === 1;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO observation_assessments (
                observation_id, recurrence_count, outcome, assessed_by_user_id, assessed_by_username
             )
             SELECT id, recurrence_count, :outcome, :user_id, :username
             FROM observations WHERE id = :id AND recurrence_count = :recurrence
             ON CONFLICT (observation_id, recurrence_count) DO UPDATE SET
                outcome = EXCLUDED.outcome,
                assessed_at = CURRENT_TIMESTAMP,
                assessed_by_user_id = EXCLUDED.assessed_by_user_id,
                assessed_by_username = EXCLUDED.assessed_by_username'
        );
        $statement->execute([
            'outcome' => $outcome, 'user_id' => $userId, 'username' => $this->username($username),
            'id' => $id, 'recurrence' => $recurrence,
        ]);
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
            "SELECT observations.*, assessments.outcome AS assessment_outcome,
                    servers.name AS server_name,
                    metric_names.name AS metric_name, metric_names.unit AS metric_unit
             FROM observations
             LEFT JOIN observation_assessments AS assessments
               ON assessments.observation_id = observations.id
              AND assessments.recurrence_count = observations.recurrence_count
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
