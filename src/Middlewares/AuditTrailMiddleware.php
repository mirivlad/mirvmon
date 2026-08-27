<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\I18n\Translator;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\ServerRepository;
use App\Services\AuditLogger;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Observes successful authenticated mutations and records their persisted result.
 *
 * This middleware never opens, commits, or rolls back a business transaction.
 * Existing controller semantics therefore stay unchanged. Audit data is derived
 * from persisted before/after state rather than copied from untrusted form input.
 */
final class AuditTrailMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly ServerRepository $servers,
        private readonly NotificationOutboxRepository $outbox,
        private readonly Translator $translator
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $operation = $this->operation($request);
        if ($operation === null) {
            return $handler->handle($request);
        }

        $before = null;
        $captured = false;
        try {
            $before = $this->snapshot($operation, $request);
            $captured = true;
        } catch (Throwable $exception) {
            $this->reportAuditFailure('before', $exception);
        }

        $response = $handler->handle($request);
        if (!$captured || $this->handlerFailed($response)) {
            return $response;
        }

        try {
            $after = $this->snapshot($operation, $request);
            $event = $this->event($operation, $request, $before, $after);
            if ($event !== null) {
                $this->audit->record(
                    $event['action'],
                    $event['object_type'],
                    $event['object_id'],
                    $event['object_label'],
                    $event['description'],
                    $event['metadata']
                );
            }
        } catch (Throwable $exception) {
            // Audit must never change the already completed business operation.
            $this->reportAuditFailure('after', $exception);
        }

        return $response;
    }

    /** @return array{kind:string,id:?int}|null */
    private function operation(ServerRequestInterface $request): ?array
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return null;
        }
        $path = rtrim($request->getUri()->getPath(), '/') ?: '/';

        foreach ([
            '#^/servers/([1-9][0-9]*)/maintenance/cancel$#' => 'maintenance_cancel',
            '#^/servers/([1-9][0-9]*)/regenerate-token$#' => 'token_rotate',
            '#^/servers/([1-9][0-9]*)/agent/update$#' => 'agent_update',
            '#^/servers/([1-9][0-9]*)/maintenance$#' => 'maintenance_start',
            '#^/servers/([1-9][0-9]*)/thresholds$#' => 'thresholds_save',
            '#^/servers/([1-9][0-9]*)/services$#' => 'services_save',
            '#^/servers/([1-9][0-9]*)/delete$#' => 'server_delete',
            '#^/servers/([1-9][0-9]*)$#' => 'server_update',
            '#^/groups/([1-9][0-9]*)/delete$#' => 'group_delete',
            '#^/groups/([1-9][0-9]*)$#' => 'group_update',
            '#^/admin/users/([1-9][0-9]*)/delete$#' => 'user_delete',
            '#^/admin/notifications/queue/([1-9][0-9]*)/retry$#' => 'queue_job_retry',
            '#^/admin/notifications/queue/([1-9][0-9]*)/delete$#' => 'queue_job_delete',
        ] as $pattern => $kind) {
            if (preg_match($pattern, $path, $matches) === 1) {
                return ['kind' => $kind, 'id' => (int) $matches[1]];
            }
        }

        return match ($path) {
            '/servers' => ['kind' => 'server_create', 'id' => null],
            '/groups' => ['kind' => 'group_create', 'id' => null],
            '/admin/users/save' => ['kind' => 'user_save', 'id' => null],
            '/admin/notifications/save' => ['kind' => 'notifications_save', 'id' => null],
            '/admin/notifications/queue/retry' => ['kind' => 'queue_retry', 'id' => null],
            '/admin/notifications/queue/delete' => ['kind' => 'queue_delete', 'id' => null],
            default => null,
        };
    }

    /** @param array{kind:string,id:?int} $operation */
    private function snapshot(array $operation, ServerRequestInterface $request): mixed
    {
        $id = $operation['id'];
        $body = $this->body($request);

        return match ($operation['kind']) {
            'server_create' => $this->serverByName($body['name'] ?? null),
            'server_update', 'server_delete' => $id === null ? null : $this->serverState($id),
            'token_rotate' => $id === null ? null : $this->tokenState($id),
            'maintenance_start', 'maintenance_cancel' => $id === null ? null : $this->maintenanceState($id),
            'thresholds_save' => $id === null ? null : $this->thresholdState($id),
            'services_save' => $id === null ? null : $this->serviceState($id),
            'agent_update' => $id === null ? null : $this->agentUpdateState($id),
            'group_create' => $this->groupByName($body['name'] ?? null),
            'group_update', 'group_delete' => $id === null ? null : $this->groupState($id),
            'user_save' => $this->userSaveState($body),
            'user_delete' => $id === null ? null : $this->userState($id),
            'notifications_save' => $this->notificationSettingsState(),
            'queue_retry' => $this->queueRetryState(),
            'queue_job_retry', 'queue_job_delete' => $id === null ? null : $this->queueJobState($id),
            'queue_delete' => $this->queueDeleteState($body),
            default => null,
        };
    }

    /**
     * @param array{kind:string,id:?int} $operation
     * @return array{action:string,object_type:string,object_id:int|string|null,object_label:?string,description:string,metadata:array<string,mixed>}|null
     */
    private function event(
        array $operation,
        ServerRequestInterface $request,
        mixed $before,
        mixed $after
    ): ?array {
        $id = $operation['id'];

        switch ($operation['kind']) {
            case 'server_create':
                if (!is_array($after) || $after === $before) {
                    return null;
                }
                return $this->eventData(
                    'server.create', 'server', (int) $after['id'], (string) $after['name'],
                    'audit.event.server.created', ['name' => (string) $after['name']]
                );

            case 'server_update':
                if (!is_array($before) || !is_array($after) || $before === $after) {
                    return null;
                }
                return $this->eventData(
                    'server.update', 'server', $id, (string) $after['name'],
                    'audit.event.server.updated', ['name' => (string) $after['name']],
                    ['changed_fields' => $this->changedFields($before, $after, [
                        'notification_telegram_chat_id' => 'notification_recipients',
                        'notification_emails' => 'notification_recipients',
                    ])]
                );

            case 'server_delete':
                if (!is_array($before) || $after !== null) {
                    return null;
                }
                return $this->eventData(
                    'server.delete', 'server', $id, (string) $before['name'],
                    'audit.event.server.deleted', ['name' => (string) $before['name']]
                );

            case 'token_rotate':
                if (!is_array($before) || !is_array($after)
                    || $before['generation'] === $after['generation']) {
                    return null;
                }
                return $this->eventData(
                    'server.token.rotate', 'server', $id, (string) $after['name'],
                    'audit.event.server.token_rotated', ['name' => (string) $after['name']],
                    ['generation' => $after['generation']]
                );

            case 'maintenance_start':
                if (!is_array($before) || !is_array($after)
                    || $after['active_count'] <= $before['active_count']) {
                    return null;
                }
                $body = $this->body($request);
                $minutes = filter_var(
                    $body['duration_minutes'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => 10080]]
                );
                return $this->eventData(
                    'server.maintenance.start', 'server', $id, (string) $after['name'],
                    'audit.event.server.maintenance_started', [
                        'name' => (string) $after['name'],
                        'minutes' => $minutes === false ? 0 : (int) $minutes,
                    ], [
                        'duration_minutes' => $minutes === false ? null : (int) $minutes,
                        'reason_provided' => trim((string) ($body['reason'] ?? '')) !== '',
                    ]
                );

            case 'maintenance_cancel':
                if (!is_array($before) || !is_array($after)
                    || $after['active_count'] >= $before['active_count']) {
                    return null;
                }
                return $this->eventData(
                    'server.maintenance.cancel', 'server', $id, (string) $before['name'],
                    'audit.event.server.maintenance_cancelled', ['name' => (string) $before['name']],
                    ['closed_windows' => $before['active_count'] - $after['active_count']]
                );

            case 'thresholds_save':
                if (!is_array($before) || !is_array($after)
                    || $before['thresholds'] === $after['thresholds']) {
                    return null;
                }
                return $this->eventData(
                    'server.thresholds.save', 'server', $id, (string) $after['name'],
                    'audit.event.server.thresholds_saved', ['name' => (string) $after['name']],
                    [
                        'changed_metrics' => $this->changedFields(
                            $before['thresholds'],
                            $after['thresholds']
                        ),
                        'configured_count' => count($after['thresholds']),
                    ]
                );

            case 'services_save':
                if (!is_array($before) || !is_array($after)
                    || $before['services'] === $after['services']) {
                    return null;
                }
                return $this->eventData(
                    'server.services.save', 'server', $id, (string) $after['name'],
                    'audit.event.server.services_saved', ['name' => (string) $after['name']],
                    [
                        'added' => array_values(array_diff($after['services'], $before['services'])),
                        'removed' => array_values(array_diff($before['services'], $after['services'])),
                    ]
                );

            case 'agent_update':
                if (!is_array($after) || $after === $before
                    || ($after['command_id'] ?? null) === null) {
                    return null;
                }
                return $this->eventData(
                    'server.agent_update.request', 'server', $id, (string) $after['name'],
                    'audit.event.server.agent_update_requested', [
                        'name' => (string) $after['name'],
                        'version' => (string) ($after['target_version'] ?? ''),
                    ], [
                        'command_id' => $after['command_id'],
                        'target_version' => $after['target_version'],
                        'target_artifact' => $after['target_artifact'],
                    ]
                );

            case 'group_create':
                if (!is_array($after) || $after === $before) {
                    return null;
                }
                return $this->eventData(
                    'group.create', 'group', (int) $after['id'], (string) $after['name'],
                    'audit.event.group.created', ['name' => (string) $after['name']]
                );

            case 'group_update':
                if (!is_array($before) || !is_array($after) || $before === $after) {
                    return null;
                }
                return $this->eventData(
                    'group.update', 'group', $id, (string) $after['name'],
                    'audit.event.group.updated', ['name' => (string) $after['name']],
                    ['changed_fields' => $this->changedFields($before, $after)]
                );

            case 'group_delete':
                if (!is_array($before) || $after !== null) {
                    return null;
                }
                return $this->eventData(
                    'group.delete', 'group', $id, (string) $before['name'],
                    'audit.event.group.deleted', ['name' => (string) $before['name']]
                );

            case 'user_save':
                if (!is_array($after) || $after === $before) {
                    return null;
                }
                $created = $before === null;
                return $this->eventData(
                    $created ? 'user.create' : 'user.update',
                    'user', (int) $after['id'], (string) $after['username'],
                    $created ? 'audit.event.user.created' : 'audit.event.user.updated',
                    ['name' => (string) $after['username']],
                    $created ? [] : ['changed_fields' => $this->changedFields($before, $after, [
                        'password_fingerprint' => 'password',
                        'telegram_chat_id' => 'notification_recipients',
                        'email_for_alerts' => 'notification_recipients',
                    ])]
                );

            case 'user_delete':
                if (!is_array($before) || $after !== null) {
                    return null;
                }
                return $this->eventData(
                    'user.delete', 'user', $id, (string) $before['username'],
                    'audit.event.user.deleted', ['name' => (string) $before['username']]
                );

            case 'notifications_save':
                if (!is_array($before) || !is_array($after) || $before === $after) {
                    return null;
                }
                return $this->eventData(
                    'notifications.save', 'notification_settings', 1, null,
                    'audit.event.notifications.saved', [],
                    ['changed_fields' => $this->changedFields($before, $after, [
                        'smtp_password_fingerprint' => 'smtp_password',
                        'telegram_bot_token_fingerprint' => 'telegram_bot_token',
                        'telegram_proxy_password_fingerprint' => 'telegram_proxy_password',
                    ])]
                );

            case 'queue_retry':
                if (!is_array($before) || !is_array($after)) {
                    return null;
                }
                $retried = max(0, (int) $before['retryable'] - (int) $after['retryable']);
                return $retried === 0 ? null : $this->eventData(
                    'notification_queue.retry', 'notification_queue', null, null,
                    'audit.event.queue.retried', ['count' => $retried],
                    ['affected_count' => $retried]
                );

            case 'queue_job_retry':
                if (!is_array($before) || !is_array($after)
                    || $before['status'] === $after['status']) {
                    return null;
                }
                return $this->eventData(
                    'notification_queue.job.retry', 'notification_job', $id, '#' . $id,
                    'audit.event.queue.job_retried', ['id' => $id], [
                        'previous_status' => $before['status'],
                        'new_status' => $after['status'],
                        'channel' => $after['channel'],
                        'server_id' => $after['server_id'],
                    ]
                );

            case 'queue_job_delete':
                if (!is_array($before) || $after !== null) {
                    return null;
                }
                return $this->eventData(
                    'notification_queue.job.delete', 'notification_job', $id, '#' . $id,
                    'audit.event.queue.job_deleted', ['id' => $id], [
                        'status' => $before['status'],
                        'channel' => $before['channel'],
                        'server_id' => $before['server_id'],
                    ]
                );

            case 'queue_delete':
                if (!is_array($before) || !is_array($after)) {
                    return null;
                }
                $deleted = max(0, (int) $before['matching'] - (int) $after['matching']);
                return $deleted === 0 ? null : $this->eventData(
                    'notification_queue.delete', 'notification_queue', null, null,
                    'audit.event.queue.deleted', ['count' => $deleted], [
                        'affected_count' => $deleted,
                        'filters' => $this->safeQueueFilters($before['filters']),
                    ]
                );
        }

        return null;
    }

    /**
     * @param array<string, scalar|null> $parameters
     * @param array<string, mixed> $metadata
     * @return array{action:string,object_type:string,object_id:int|string|null,object_label:?string,description:string,metadata:array<string,mixed>}
     */
    private function eventData(
        string $action,
        string $objectType,
        int|string|null $objectId,
        ?string $objectLabel,
        string $descriptionKey,
        array $parameters = [],
        array $metadata = []
    ): array {
        return [
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'object_label' => $objectLabel,
            'description' => $this->translator->trans($descriptionKey, $parameters),
            'metadata' => $metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /** @return array<string, mixed>|null */
    private function serverState(int $serverId): ?array
    {
        $server = $this->servers->find($serverId);
        if ($server === null) {
            return null;
        }
        return [
            'id' => (int) $server['id'],
            'name' => (string) $server['name'],
            'address' => $server['address'] ?? null,
            'group_id' => $server['group_id'] ?? null,
            'description' => $server['description'] ?? null,
            'offline_timeout_seconds' => (int) ($server['offline_timeout_seconds'] ?? 0),
            'notify_on_offline' => (bool) ($server['notify_on_offline'] ?? false),
            'notification_telegram_chat_id' => $server['notification_telegram_chat_id'] ?? null,
            'notification_emails' => $this->stringList($server['notification_emails'] ?? []),
            'display_metrics' => $this->stringList($server['display_metrics'] ?? []),
            'is_active' => (bool) ($server['is_active'] ?? false),
        ];
    }

    /** @return array<string, mixed>|null */
    private function serverByName(mixed $name): ?array
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id FROM servers WHERE name = :name ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['name' => $name]);
        $id = $statement->fetchColumn();
        return $id === false ? null : $this->serverState((int) $id);
    }

    /** @return array{name:string,generation:?int}|null */
    private function tokenState(int $serverId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT servers.name, agent_tokens.token_generation
             FROM servers
             LEFT JOIN agent_tokens ON agent_tokens.server_id = servers.id
             WHERE servers.id = :id'
        );
        $statement->execute(['id' => $serverId]);
        $row = $statement->fetch();
        return is_array($row) ? [
            'name' => (string) $row['name'],
            'generation' => $row['token_generation'] === null ? null : (int) $row['token_generation'],
        ] : null;
    }

    /** @return array{name:string,active_count:int}|null */
    private function maintenanceState(int $serverId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT servers.name,
                    count(maintenance_windows.id) FILTER (
                        WHERE maintenance_windows.starts_at <= CURRENT_TIMESTAMP
                          AND maintenance_windows.ends_at > CURRENT_TIMESTAMP
                    ) AS active_count
             FROM servers
             LEFT JOIN maintenance_windows ON maintenance_windows.server_id = servers.id
             WHERE servers.id = :id
             GROUP BY servers.id, servers.name'
        );
        $statement->execute(['id' => $serverId]);
        $row = $statement->fetch();
        return is_array($row) ? [
            'name' => (string) $row['name'],
            'active_count' => (int) $row['active_count'],
        ] : null;
    }

    /** @return array{name:string,thresholds:array<string,mixed>}|null */
    private function thresholdState(int $serverId): ?array
    {
        $server = $this->servers->find($serverId);
        return $server === null ? null : [
            'name' => (string) $server['name'],
            'thresholds' => $this->servers->thresholds($serverId),
        ];
    }

    /** @return array{name:string,services:list<string>}|null */
    private function serviceState(int $serverId): ?array
    {
        $server = $this->servers->find($serverId);
        if ($server === null) {
            return null;
        }
        $services = $this->servers->monitoredServices($serverId);
        sort($services, SORT_STRING);
        return [
            'name' => (string) $server['name'],
            'services' => $services,
        ];
    }

    /** @return array<string, mixed>|null */
    private function agentUpdateState(int $serverId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT servers.name,
                    commands.id AS command_id,
                    commands.target_version,
                    commands.target_artifact
             FROM servers
             LEFT JOIN LATERAL (
                SELECT id, target_version, target_artifact
                FROM agent_update_commands
                WHERE server_id = servers.id
                ORDER BY created_at DESC, id DESC
                LIMIT 1
             ) AS commands ON TRUE
             WHERE servers.id = :id'
        );
        $statement->execute(['id' => $serverId]);
        $row = $statement->fetch();
        return is_array($row) ? [
            'name' => (string) $row['name'],
            'command_id' => $row['command_id'] === null ? null : (string) $row['command_id'],
            'target_version' => $row['target_version'] === null ? null : (string) $row['target_version'],
            'target_artifact' => $row['target_artifact'] === null ? null : (string) $row['target_artifact'],
        ] : null;
    }

    /** @return array<string, mixed>|null */
    private function groupState(int $groupId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, description, icon, color FROM monitoring_groups WHERE id = :id'
        );
        $statement->execute(['id' => $groupId]);
        $row = $statement->fetch();
        return is_array($row) ? [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => $row['description'] ?? null,
            'icon' => $row['icon'] ?? null,
            'color' => $row['color'] ?? null,
        ] : null;
    }

    /** @return array<string, mixed>|null */
    private function groupByName(mixed $name): ?array
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id FROM monitoring_groups WHERE name = :name ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['name' => $name]);
        $id = $statement->fetchColumn();
        return $id === false ? null : $this->groupState((int) $id);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function userSaveState(array $body): ?array
    {
        $id = $this->positiveInt($body['user_id'] ?? null);
        if ($id !== null) {
            return $this->userState($id);
        }
        $username = is_string($body['username'] ?? null) ? trim($body['username']) : '';
        if ($username === '') {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $userId = $statement->fetchColumn();
        return $userId === false ? null : $this->userState((int) $userId);
    }

    /** @return array<string, mixed>|null */
    private function userState(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT users.id,
                    users.username,
                    users.email,
                    users.password_hash,
                    users.role,
                    settings.telegram_chat_id,
                    settings.email_for_alerts
             FROM users
             LEFT JOIN user_notification_settings AS settings ON settings.user_id = users.id
             WHERE users.id = :id'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'email' => $row['email'] ?? null,
            'password_fingerprint' => hash('sha256', (string) $row['password_hash']),
            'role' => (string) $row['role'],
            'telegram_chat_id' => $row['telegram_chat_id'] ?? null,
            'email_for_alerts' => $row['email_for_alerts'] ?? null,
        ] : null;
    }

    /** @return array<string, mixed> */
    private function notificationSettingsState(): array
    {
        $row = $this->pdo->query('SELECT * FROM notification_settings WHERE id = 1')?->fetch();
        if (!is_array($row)) {
            return [];
        }
        foreach ([
            'smtp_password_encrypted' => 'smtp_password_fingerprint',
            'telegram_bot_token_encrypted' => 'telegram_bot_token_fingerprint',
            'telegram_proxy_password_encrypted' => 'telegram_proxy_password_fingerprint',
        ] as $secret => $fingerprint) {
            $value = $row[$secret] ?? null;
            $row[$fingerprint] = $value === null ? null : hash('sha256', (string) $value);
            unset($row[$secret]);
        }
        unset($row['updated_at']);
        ksort($row, SORT_STRING);
        return $row;
    }

    /** @return array{retryable:int} */
    private function queueRetryState(): array
    {
        return ['retryable' => (int) ($this->pdo->query(
            "SELECT count(*) FROM notification_outbox WHERE status IN ('failed', 'dead')"
        )?->fetchColumn() ?: 0)];
    }

    /** @return array<string, mixed>|null */
    private function queueJobState(int $jobId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status, channel, server_id, event_type, attempts
             FROM notification_outbox WHERE id = :id'
        );
        $statement->execute(['id' => $jobId]);
        $row = $statement->fetch();
        return is_array($row) ? [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'channel' => (string) $row['channel'],
            'server_id' => $row['server_id'] === null ? null : (int) $row['server_id'],
            'event_type' => (string) $row['event_type'],
            'attempts' => (int) $row['attempts'],
        ] : null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{matching:int,filters:array<string,mixed>}
     */
    private function queueDeleteState(array $body): array
    {
        $filters = $this->outbox->filters($body);
        return [
            'matching' => $this->outbox->countMatching($filters),
            'filters' => $filters,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function safeQueueFilters(array $filters): array
    {
        return [
            'statuses' => $filters['statuses'] ?? [],
            'channel' => $filters['channel'] ?? null,
            'server_id' => $filters['server_id'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'error_filter_used' => ($filters['error'] ?? null) !== null,
        ];
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,string> $aliases
     * @return list<string>
     */
    private function changedFields(array $before, array $after, array $aliases = []): array
    {
        $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        $changed = [];
        foreach ($keys as $key) {
            if (($before[$key] ?? null) === ($after[$key] ?? null)) {
                continue;
            }
            $changed[] = $aliases[$key] ?? $key;
        }
        $changed = array_values(array_unique($changed));
        sort($changed, SORT_STRING);
        return $changed;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? null : (int) $value;
    }

    private function handlerFailed(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return true;
        }
        $flashType = $_SESSION['flash_type'] ?? null;
        return is_string($flashType) && in_array($flashType, ['error', 'danger'], true);
    }

    private function reportAuditFailure(string $phase, Throwable $exception): void
    {
        error_log(sprintf(
            '[MirvMon audit] %s capture failed: %s',
            $phase,
            $exception->getMessage()
        ));
    }
}
