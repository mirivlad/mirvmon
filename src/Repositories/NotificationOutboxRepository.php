<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
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
                notify_on_critical,
                telegram_chat_id,
                smtp_recipients,
                cooldown_seconds
             FROM notification_settings
             WHERE id = 1'
        )?->fetch();

        if (!is_array($settings) || !$this->severityIsEnabled($settings, $payload)) {
            return 0;
        }
        if ($this->underMaintenance($serverId)) {
            return 0;
        }
        if (
            $this->withinCooldown(
                (int) ($settings['cooldown_seconds'] ?? 0),
                $serverId,
                $eventType,
                $payload
            )
        ) {
            return 0;
        }

        $deliveries = $this->deliveries($settings, $serverId);
        if ($deliveries === []) {
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
                recipient,
                event_type,
                payload,
                deduplication_key
             ) VALUES (
                :server_id,
                :alert_id,
                :channel,
                :recipient,
                :event_type,
                CAST(:payload AS jsonb),
                :deduplication_key
             )
             ON CONFLICT (deduplication_key) DO NOTHING'
        );

        $inserted = 0;
        foreach ($deliveries as [$channel, $recipient]) {
            $statement->execute([
                'server_id' => $serverId,
                'alert_id' => $alertId,
                'channel' => $channel,
                'recipient' => $recipient,
                'event_type' => $eventType,
                'payload' => $encodedPayload,
                'deduplication_key' => $this->recipientKey(
                    $deduplicationKey,
                    $channel,
                    $recipient
                ),
            ]);
            $inserted += $statement->rowCount();
        }

        return $inserted;
    }

    /**
     * A metric sitting on its threshold can trigger and recover repeatedly.
     * The cooldown rate-limits one kind of event about one subject on one
     * server; recoveries carry a different event type, so the all-clear is
     * never swallowed.
     *
     * @param array<string, mixed> $payload
     */
    private function withinCooldown(
        int $cooldownSeconds,
        int $serverId,
        string $eventType,
        array $payload
    ): bool {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        $statement = $this->pdo->prepare(
            "SELECT EXISTS(
                SELECT 1
                FROM notification_outbox
                WHERE server_id = :server_id
                  AND event_type = :event_type
                  AND COALESCE(
                        payload->>'metric',
                        payload->>'service',
                        ''
                      ) = :subject
                  AND created_at > CURRENT_TIMESTAMP
                      - CAST(:cooldown AS integer) * INTERVAL '1 second'
             )"
        );
        $statement->execute([
            'server_id' => $serverId,
            'event_type' => $eventType,
            'subject' => (string) (
                $payload['metric'] ?? $payload['service'] ?? ''
            ),
            'cooldown' => $cooldownSeconds,
        ]);

        return $this->toBool($statement->fetchColumn());
    }

    /**
     * Planned work still produces alerts, it just does not wake anybody.
     */
    private function underMaintenance(int $serverId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT EXISTS(
                SELECT 1
                FROM maintenance_windows
                WHERE server_id = :server_id
                  AND starts_at <= CURRENT_TIMESTAMP
                  AND ends_at > CURRENT_TIMESTAMP
             )'
        );
        $statement->execute(['server_id' => $serverId]);

        return $this->toBool($statement->fetchColumn());
    }

    /**
     * Resolves who this event reaches: a server may override the
     * installation-wide chat and mailboxes, and each recipient becomes its own
     * job so one rejected address cannot hold up the rest.
     *
     * @param array<string, mixed> $settings
     * @return list<array{0: string, 1: ?string}>
     */
    private function deliveries(array $settings, ?int $serverId): array
    {
        $override = ['telegram' => null, 'emails' => []];
        if ($serverId !== null) {
            $statement = $this->pdo->prepare(
                'SELECT notification_telegram_chat_id, notification_emails
                 FROM servers
                 WHERE id = :id'
            );
            $statement->execute(['id' => $serverId]);
            $row = $statement->fetch();
            if (is_array($row)) {
                $chatId = trim((string) ($row['notification_telegram_chat_id'] ?? ''));
                $override['telegram'] = $chatId === '' ? null : $chatId;
                $override['emails'] = $this->emailList($row['notification_emails'] ?? null);
            }
        }

        $deliveries = [];
        if ($this->toBool($settings['email_enabled'])) {
            $emails = $override['emails'] !== []
                ? $override['emails']
                : $this->emailList($settings['smtp_recipients'] ?? null);
            foreach ($emails as $email) {
                $deliveries[] = ['email', $email];
            }
        }
        if ($this->toBool($settings['telegram_enabled'])) {
            $chatId = $override['telegram']
                ?? $this->nullableString($settings['telegram_chat_id'] ?? null);
            if ($chatId !== null) {
                $deliveries[] = ['telegram', $chatId];
            }
        }

        return $deliveries;
    }

    /**
     * The recipient is hashed into the key so an address of any length keeps
     * the unique key inside its column.
     */
    private function recipientKey(
        string $deduplicationKey,
        string $channel,
        ?string $recipient
    ): string {
        return $deduplicationKey
            . ':' . $channel
            . ':' . substr(hash('sha256', (string) $recipient), 0, 16);
    }

    /**
     * @return list<string>
     */
    private function emailList(mixed $value): array
    {
        $decoded = is_array($value)
            ? $value
            : json_decode((string) ($value ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }

        $emails = [];
        foreach ($decoded as $email) {
            if (is_string($email) && trim($email) !== '') {
                $emails[] = trim($email);
            }
        }

        return $emails;
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /** @param array<string, mixed> $payload */
    public function enqueueTest(array $payload): int
    {
        $settings = $this->pdo->query(
            'SELECT
                email_enabled,
                telegram_enabled,
                telegram_chat_id,
                smtp_recipients
             FROM notification_settings
             WHERE id = 1'
        )?->fetch();
        if (!is_array($settings)) {
            return 0;
        }

        $deliveries = $this->deliveries($settings, null);
        if ($deliveries === []) {
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
                recipient,
                event_type,
                payload,
                deduplication_key
             ) VALUES (
                :channel,
                :recipient,
                'test',
                CAST(:payload AS jsonb),
                :deduplication_key
             )"
        );
        $batchKey = 'test:' . bin2hex(random_bytes(16));
        foreach ($deliveries as [$channel, $recipient]) {
            $statement->execute([
                'channel' => $channel,
                'recipient' => $recipient,
                'payload' => $encodedPayload,
                'deduplication_key' => $this->recipientKey($batchKey, $channel, $recipient),
            ]);
        }

        return count($deliveries);
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
                    jobs.recipient,
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
                jobs.recipient,
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

    /**
     * Normalize the supported notification-queue filters before they reach
     * dynamic SQL. Unknown values intentionally become empty filters.
     *
     * @param array<string, mixed> $input
     * @return array{
     *     statuses:list<string>,
     *     channel:?string,
     *     server_id:?int,
     *     from:?string,
     *     to:?string,
     *     error:?string
     * }
     */
    public function filters(array $input): array
    {
        $statusInput = $input['status'] ?? [];
        if (!is_array($statusInput)) {
            $statusInput = [$statusInput];
        }
        $statuses = [];
        foreach ($statusInput as $status) {
            if (
                is_string($status)
                && in_array(
                    $status,
                    ['pending', 'processing', 'sent', 'failed', 'dead'],
                    true
                )
                && !in_array($status, $statuses, true)
            ) {
                $statuses[] = $status;
            }
        }

        $channel = $input['channel'] ?? null;
        $serverId = filter_var(
            $input['server'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return [
            'statuses' => $statuses,
            'channel' => is_string($channel)
                && in_array($channel, ['email', 'telegram'], true)
                ? $channel
                : null,
            'server_id' => $serverId === false ? null : $serverId,
            'from' => $this->dateBoundary($input['from'] ?? null, false),
            'to' => $this->dateBoundary($input['to'] ?? null, true),
            'error' => $this->substring($input['error'] ?? null),
        ];
    }

    /**
     * @param array{
     *     statuses:list<string>,
     *     channel:?string,
     *     server_id:?int,
     *     from:?string,
     *     to:?string,
     *     error:?string
     * } $filters
     * @return array{jobs:list<array<string, mixed>>,total:int,pages:int}
     */
    public function page(array $filters, int $page, int $perPage = 25): array
    {
        if ($perPage < 1 || $perPage > 100) {
            throw new RuntimeException('Outbox page size must be between 1 and 100.');
        }

        [$where, $parameters] = $this->whereClause($filters);
        $total = $this->countWhere($where, $parameters);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $statement = $this->pdo->prepare(
            'SELECT
                jobs.id,
                jobs.channel,
                jobs.recipient,
                jobs.event_type,
                jobs.payload::text AS payload_json,
                jobs.status,
                jobs.attempts,
                jobs.last_error,
                jobs.created_at,
                jobs.available_at,
                jobs.sent_at,
                servers.name AS server_name
             FROM notification_outbox AS jobs
             LEFT JOIN servers ON servers.id = jobs.server_id'
            . $where
            . ' ORDER BY jobs.id DESC
                LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        $jobs = $statement->fetchAll();
        foreach ($jobs as &$job) {
            $job['id'] = (int) $job['id'];
            $job['attempts'] = (int) $job['attempts'];
            try {
                $job['payload_pretty'] = json_encode(
                    json_decode(
                        (string) $job['payload_json'],
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                $job['payload_pretty'] = '{}';
            }
            unset($job['payload_json']);
        }
        unset($job);

        return ['jobs' => $jobs, 'total' => $total, 'pages' => $pages];
    }

    /**
     * @param array{
     *     statuses:list<string>,
     *     channel:?string,
     *     server_id:?int,
     *     from:?string,
     *     to:?string,
     *     error:?string
     * } $filters
     */
    public function countMatching(array $filters): int
    {
        [$where, $parameters] = $this->whereClause($filters);

        return $this->countWhere($where, $parameters);
    }

    /** @param list<int> $ids */
    public function retryByIds(array $ids): int
    {
        [$where, $parameters] = $this->idsWhere($ids);
        if ($where === '') {
            return 0;
        }
        $statement = $this->pdo->prepare(
            "UPDATE notification_outbox
             SET
                status = 'pending',
                attempts = 0,
                available_at = CURRENT_TIMESTAMP,
                locked_at = NULL,
                last_error = NULL
             WHERE status IN ('failed', 'dead')" . $where
        );
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    /** @param list<int> $ids */
    public function deleteByIds(array $ids): int
    {
        [$where, $parameters] = $this->idsWhere($ids);
        if ($where === '') {
            return 0;
        }
        $statement = $this->pdo->prepare(
            'DELETE FROM notification_outbox WHERE TRUE' . $where
        );
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    /**
     * @param array{
     *     statuses:list<string>,
     *     channel:?string,
     *     server_id:?int,
     *     from:?string,
     *     to:?string,
     *     error:?string
     * } $filters
     */
    public function deleteMatching(array $filters): int
    {
        [$where, $parameters] = $this->whereClause($filters);
        $statement = $this->pdo->prepare(
            'DELETE FROM notification_outbox AS jobs' . $where
        );
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    /**
     * @param array{
     *     statuses:list<string>,
     *     channel:?string,
     *     server_id:?int,
     *     from:?string,
     *     to:?string,
     *     error:?string
     * } $filters
     * @return array{string, array<string, mixed>}
     */
    private function whereClause(array $filters): array
    {
        $conditions = [];
        $parameters = [];
        foreach ($filters['statuses'] as $index => $status) {
            $name = 'status_' . $index;
            $conditions[] = 'jobs.status = :' . $name;
            $parameters[$name] = $status;
        }
        if ($filters['statuses'] !== []) {
            $statusConditions = array_splice(
                $conditions,
                0,
                count($filters['statuses'])
            );
            $conditions[] = '(' . implode(' OR ', $statusConditions) . ')';
        }
        if ($filters['channel'] !== null) {
            $conditions[] = 'jobs.channel = :channel';
            $parameters['channel'] = $filters['channel'];
        }
        if ($filters['server_id'] !== null) {
            $conditions[] = 'jobs.server_id = :server_id';
            $parameters['server_id'] = $filters['server_id'];
        }
        if ($filters['from'] !== null) {
            $conditions[] = 'jobs.created_at >= CAST(:from AS timestamptz)';
            $parameters['from'] = $filters['from'];
        }
        if ($filters['to'] !== null) {
            $conditions[] = "jobs.created_at < CAST(:to AS timestamptz) + INTERVAL '1 day'";
            $parameters['to'] = $filters['to'];
        }
        if ($filters['error'] !== null) {
            $conditions[] = "jobs.last_error ILIKE :error ESCAPE E'\\\\'";
            $parameters['error'] = '%' . $this->escapeLike($filters['error']) . '%';
        }

        return [
            $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            $parameters,
        ];
    }

    /** @param array<string, mixed> $parameters */
    private function countWhere(string $where, array $parameters): int
    {
        $statement = $this->pdo->prepare(
            'SELECT count(*) FROM notification_outbox AS jobs' . $where
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** @param list<int> $ids @return array{string, array<string, int>} */
    private function idsWhere(array $ids): array
    {
        $parameters = [];
        foreach (array_values(array_unique($ids)) as $index => $id) {
            if ($id > 0) {
                $parameters['id_' . $index] = $id;
            }
        }
        if ($parameters === []) {
            return ['', []];
        }

        return [
            ' AND jobs.id IN (:' . implode(', :', array_keys($parameters)) . ')',
            $parameters,
        ];
    }

    private function dateBoundary(mixed $value, bool $end): ?string
    {
        if (
            !is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1
        ) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value . 'T00:00:00+00:00');
        } catch (Throwable) {
            return null;
        }

        return $date->format(DATE_ATOM);
    }

    private function substring(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : substr($value, 0, 200);
    }

    private function escapeLike(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
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
