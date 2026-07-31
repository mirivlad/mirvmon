<?php

declare(strict_types=1);

namespace App\Repositories;

use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class NotificationOutboxRepository
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $payload */
    public function enqueueConfigured(
        int $serverId,
        int $alertId,
        string $eventType,
        array $payload,
        string $deduplicationKey
    ): int {
        $settings = $this->pdo->query(
            'SELECT
                email_enabled,
                telegram_enabled,
                notify_on_warning,
                notify_on_critical
             FROM notification_settings
             WHERE id = 1'
        )?->fetch();

        if (!is_array($settings) || !$this->severityIsEnabled($settings, $payload)) {
            return 0;
        }

        $channels = [];
        if ($this->toBool($settings['email_enabled'])) {
            $channels[] = 'email';
        }
        if ($this->toBool($settings['telegram_enabled'])) {
            $channels[] = 'telegram';
        }
        if ($channels === []) {
            return 0;
        }

        try {
            $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode notification payload.', 0, $exception);
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO notification_outbox (
                server_id,
                alert_id,
                channel,
                event_type,
                payload,
                deduplication_key
             ) VALUES (
                :server_id,
                :alert_id,
                :channel,
                :event_type,
                CAST(:payload AS jsonb),
                :deduplication_key
             )
             ON CONFLICT (deduplication_key) DO NOTHING'
        );

        $inserted = 0;
        foreach ($channels as $channel) {
            $statement->execute([
                'server_id' => $serverId,
                'alert_id' => $alertId,
                'channel' => $channel,
                'event_type' => $eventType,
                'payload' => $encodedPayload,
                'deduplication_key' => $deduplicationKey . ':' . $channel,
            ]);
            $inserted += $statement->rowCount();
        }

        return $inserted;
    }

    /** @param array<string, mixed> $payload */
    public function enqueueTest(array $payload): int
    {
        $settings = $this->pdo->query(
            'SELECT email_enabled, telegram_enabled
             FROM notification_settings
             WHERE id = 1'
        )?->fetch();
        if (!is_array($settings)) {
            return 0;
        }

        $channels = [];
        if ($this->toBool($settings['email_enabled'])) {
            $channels[] = 'email';
        }
        if ($this->toBool($settings['telegram_enabled'])) {
            $channels[] = 'telegram';
        }
        if ($channels === []) {
            return 0;
        }

        try {
            $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode notification payload.', 0, $exception);
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO notification_outbox (
                channel,
                event_type,
                payload,
                deduplication_key
             ) VALUES (
                :channel,
                'test',
                CAST(:payload AS jsonb),
                :deduplication_key
             )"
        );
        $batchKey = 'test:' . bin2hex(random_bytes(16));
        foreach ($channels as $channel) {
            $statement->execute([
                'channel' => $channel,
                'payload' => $encodedPayload,
                'deduplication_key' => $batchKey . ':' . $channel,
            ]);
        }

        return count($channels);
    }

    /**
     * Atomically claim jobs. A stale processing lease becomes eligible again
     * after five minutes, so a killed worker cannot strand a notification.
     *
     * @return list<array<string, mixed>>
     */
    public function claim(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Outbox claim limit must be between 1 and 100.');
        }

        $transaction = $this->beginTransaction('notification_outbox_claim');
        try {
            $statement = $this->pdo->prepare(
                <<<'SQL'
                WITH claimed AS (
                    SELECT id
                    FROM notification_outbox
                    WHERE (
                        status IN ('pending', 'failed')
                        AND available_at <= CURRENT_TIMESTAMP
                    ) OR (
                        status = 'processing'
                        AND locked_at < CURRENT_TIMESTAMP - INTERVAL '5 minutes'
                    )
                    ORDER BY available_at, id
                    FOR UPDATE SKIP LOCKED
                    LIMIT :limit
                )
                UPDATE notification_outbox AS jobs
                SET
                    status = 'processing',
                    attempts = jobs.attempts + 1,
                    locked_at = CURRENT_TIMESTAMP,
                    last_error = NULL
                FROM claimed
                WHERE jobs.id = claimed.id
                RETURNING
                    jobs.id,
                    jobs.server_id,
                    jobs.alert_id,
                    jobs.channel,
                    jobs.event_type,
                    jobs.payload,
                    jobs.attempts
                SQL
            );
            $statement->bindValue('limit', $limit, PDO::PARAM_INT);
            $statement->execute();
            $jobs = $statement->fetchAll();
            $this->commitTransaction($transaction);
        } catch (Throwable $exception) {
            $this->rollbackTransaction($transaction);
            throw $exception;
        }

        foreach ($jobs as &$job) {
            try {
                $payload = json_decode(
                    (string) $job['payload'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $exception) {
                throw new RuntimeException('Invalid payload in notification outbox.', 0, $exception);
            }
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid payload in notification outbox.');
            }
            $job['id'] = (int) $job['id'];
            $job['server_id'] = $job['server_id'] === null ? null : (int) $job['server_id'];
            $job['alert_id'] = $job['alert_id'] === null ? null : (int) $job['alert_id'];
            $job['attempts'] = (int) $job['attempts'];
            $job['payload'] = $payload;
        }
        unset($job);

        return $jobs;
    }

    public function markSent(int $jobId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE notification_outbox
             SET
                status = 'sent',
                sent_at = CURRENT_TIMESTAMP,
                locked_at = NULL,
                last_error = NULL
             WHERE id = :id AND status = 'processing'"
        );
        $statement->execute(['id' => $jobId]);
    }

    public function markDead(int $jobId, string $safeError): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE notification_outbox
             SET
                status = 'dead',
                locked_at = NULL,
                last_error = :last_error
             WHERE id = :id AND status = 'processing'"
        );
        $statement->execute([
            'id' => $jobId,
            'last_error' => substr($safeError, 0, 500),
        ]);
    }

    /**
     * Recent jobs for the administration UI, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new RuntimeException('Outbox listing limit must be between 1 and 200.');
        }

        $statement = $this->pdo->prepare(
            'SELECT
                jobs.id,
                jobs.channel,
                jobs.event_type,
                jobs.status,
                jobs.attempts,
                jobs.last_error,
                jobs.created_at,
                jobs.available_at,
                jobs.sent_at,
                servers.name AS server_name
             FROM notification_outbox AS jobs
             LEFT JOIN servers ON servers.id = jobs.server_id
             ORDER BY jobs.id DESC
             LIMIT :limit'
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $jobs = $statement->fetchAll();
        foreach ($jobs as &$job) {
            $job['id'] = (int) $job['id'];
            $job['attempts'] = (int) $job['attempts'];
        }
        unset($job);

        return $jobs;
    }

    /** @return array<string, int> Job count per status. */
    public function statusCounts(): array
    {
        $rows = $this->pdo->query(
            'SELECT status, COUNT(*) AS total
             FROM notification_outbox
             GROUP BY status
             ORDER BY status'
        )?->fetchAll() ?: [];

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Give every failed and dead job a fresh retry budget, for example after
     * a corrected bot token.
     */
    public function retryUndelivered(): int
    {
        $statement = $this->pdo->query(
            "UPDATE notification_outbox
             SET
                status = 'pending',
                attempts = 0,
                available_at = CURRENT_TIMESTAMP,
                locked_at = NULL,
                last_error = NULL
             WHERE status IN ('failed', 'dead')"
        );

        return $statement === false ? 0 : $statement->rowCount();
    }

    /**
     * The outbox is a log as much as a queue and nothing ever removed a
     * delivered row, so it grew for the lifetime of the installation.
     *
     * @return int Rows removed.
     */
    public function purge(int $sentDays, int $deadDays): int
    {
        if ($sentDays < 1 || $deadDays < 1) {
            throw new RuntimeException('Outbox retention must be at least one day.');
        }

        $statement = $this->pdo->prepare(
            "DELETE FROM notification_outbox
             WHERE (
                status = 'sent'
                AND sent_at IS NOT NULL
                AND sent_at < CURRENT_TIMESTAMP
                    - CAST(:sent_days AS integer) * INTERVAL '1 day'
             ) OR (
                status = 'dead'
                AND created_at < CURRENT_TIMESTAMP
                    - CAST(:dead_days AS integer) * INTERVAL '1 day'
             )"
        );
        $statement->execute([
            'sent_days' => $sentDays,
            'dead_days' => $deadDays,
        ]);

        return $statement->rowCount();
    }

    public function markFailed(int $jobId, int $attempts, string $safeError): void
    {
        $delaySeconds = min(3600, 5 * (2 ** max(0, min(10, $attempts - 1))));
        $statement = $this->pdo->prepare(
            "UPDATE notification_outbox
             SET
                status = CASE
                    WHEN CAST(:attempts_status AS integer)
                        >= CAST(:max_attempts AS integer) THEN 'dead'
                    ELSE 'failed'
                END,
                available_at = CASE
                    WHEN CAST(:attempts_available AS integer)
                        >= CAST(:max_attempts_available AS integer)
                        THEN CURRENT_TIMESTAMP
                    ELSE CURRENT_TIMESTAMP
                        + CAST(:delay_seconds AS integer) * INTERVAL '1 second'
                END,
                locked_at = NULL,
                last_error = :last_error
             WHERE id = :id AND status = 'processing'"
        );
        $statement->execute([
            'id' => $jobId,
            'attempts_status' => $attempts,
            'attempts_available' => $attempts,
            'max_attempts' => self::MAX_ATTEMPTS,
            'max_attempts_available' => self::MAX_ATTEMPTS,
            'delay_seconds' => $delaySeconds,
            'last_error' => substr($safeError, 0, 500),
        ]);
    }

    /** @param array<string, mixed> $settings
     *  @param array<string, mixed> $payload
     */
    private function severityIsEnabled(array $settings, array $payload): bool
    {
        $severity = $payload['severity'] ?? null;
        if ($severity === 'warning') {
            return $this->toBool($settings['notify_on_warning']);
        }
        if ($severity === 'critical') {
            return $this->toBool($settings['notify_on_critical']);
        }

        return true;
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function beginTransaction(string $savepoint): bool
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            return true;
        }

        $this->pdo->exec('SAVEPOINT ' . $savepoint);
        return false;
    }

    private function commitTransaction(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            $this->pdo->commit();
            return;
        }

        $this->pdo->exec('RELEASE SAVEPOINT notification_outbox_claim');
    }

    private function rollbackTransaction(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return;
        }

        $this->pdo->exec('ROLLBACK TO SAVEPOINT notification_outbox_claim');
        $this->pdo->exec('RELEASE SAVEPOINT notification_outbox_claim');
    }
}
