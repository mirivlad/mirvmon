<?php

declare(strict_types=1);

namespace App\Workers;

use App\Repositories\NotificationOutboxRepository;
use DateTimeImmutable;
use PDO;
use Throwable;

final class OfflineStatusWorker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly NotificationOutboxRepository $outbox
    ) {
    }

    public function runOnce(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $ownsTransaction = $this->beginTransaction();

        try {
            $servers = $this->serversForCheck();
            $transitions = 0;
            foreach ($servers as $server) {
                $timeout = (int) $server['offline_timeout_seconds'];
                $lastMetricsAt = new DateTimeImmutable((string) $server['last_metrics_at']);
                $offline = $lastMetricsAt <= $now->modify('-' . $timeout . ' seconds');
                $alertId = $server['alert_id'] === null
                    ? null
                    : (int) $server['alert_id'];

                if (
                    $offline
                    && $alertId === null
                    && $this->toBool($server['notify_on_offline'])
                ) {
                    $this->createOfflineAlert($server, $now);
                    $transitions++;
                    continue;
                }

                if (!$offline && $alertId !== null) {
                    $this->resolveOfflineAlert($server, $alertId, $now);
                    $transitions++;
                }
            }

            $this->commitTransaction($ownsTransaction);
            return $transitions;
        } catch (Throwable $exception) {
            $this->rollbackTransaction($ownsTransaction);
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    private function serversForCheck(): array
    {
        $statement = $this->pdo->query(
            "SELECT
                servers.id,
                servers.name,
                servers.last_metrics_at,
                servers.offline_timeout_seconds,
                servers.notify_on_offline,
                active_alert.id AS alert_id
             FROM servers
             LEFT JOIN alerts AS active_alert
               ON active_alert.server_id = servers.id
              AND active_alert.kind = 'offline'
              AND active_alert.resolved = FALSE
             WHERE servers.is_active = TRUE
               AND servers.last_metrics_at IS NOT NULL
               AND (
                    servers.notify_on_offline = TRUE
                    OR active_alert.id IS NOT NULL
               )
             ORDER BY servers.id
             FOR UPDATE OF servers"
        );

        return $statement === false ? [] : $statement->fetchAll();
    }

    /** @param array<string, mixed> $server */
    private function createOfflineAlert(
        array $server,
        DateTimeImmutable $now
    ): void {
        $statement = $this->pdo->prepare(
            "INSERT INTO alerts (
                server_id,
                kind,
                subject,
                severity,
                created_at
             ) VALUES (
                :server_id,
                'offline',
                'agent',
                'critical',
                :created_at
             )
             RETURNING id"
        );
        $statement->execute([
            'server_id' => (int) $server['id'],
            'created_at' => $this->timestamp($now),
        ]);
        $alertId = (int) $statement->fetchColumn();

        $update = $this->pdo->prepare(
            'UPDATE servers
             SET last_offline_alert_at = :alert_time
             WHERE id = :server_id'
        );
        $update->execute([
            'server_id' => (int) $server['id'],
            'alert_time' => $this->timestamp($now),
        ]);

        $this->outbox->enqueueConfigured(
            (int) $server['id'],
            $alertId,
            'offline_triggered',
            $this->payload('triggered', $server, $now),
            implode(':', [
                (int) $server['id'],
                $alertId,
                'offline_triggered',
            ])
        );
    }

    /** @param array<string, mixed> $server */
    private function resolveOfflineAlert(
        array $server,
        int $alertId,
        DateTimeImmutable $now
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE alerts
             SET resolved = TRUE, resolved_at = :resolved_at
             WHERE id = :id AND resolved = FALSE'
        );
        $statement->execute([
            'id' => $alertId,
            'resolved_at' => $this->timestamp($now),
        ]);

        $this->outbox->enqueueConfigured(
            (int) $server['id'],
            $alertId,
            'offline_recovered',
            $this->payload('recovered', $server, $now),
            implode(':', [
                (int) $server['id'],
                $alertId,
                'offline_recovered',
            ])
        );
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private function payload(
        string $event,
        array $server,
        DateTimeImmutable $now
    ): array {
        return [
            'type' => 'offline',
            'event' => $event,
            'server_id' => (int) $server['id'],
            'server_name' => (string) $server['name'],
            'last_metrics_at' => (string) $server['last_metrics_at'],
            'severity' => 'critical',
            'event_time' => $now->format(DATE_ATOM),
        ];
    }

    private function timestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->format('Y-m-d H:i:s.uP');
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function beginTransaction(): bool
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            return true;
        }

        $this->pdo->exec('SAVEPOINT offline_status_worker');
        return false;
    }

    private function commitTransaction(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            $this->pdo->commit();
            return;
        }

        $this->pdo->exec('RELEASE SAVEPOINT offline_status_worker');
    }

    private function rollbackTransaction(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return;
        }

        $this->pdo->exec('ROLLBACK TO SAVEPOINT offline_status_worker');
        $this->pdo->exec('RELEASE SAVEPOINT offline_status_worker');
    }
}
