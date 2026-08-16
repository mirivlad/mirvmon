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
 * Wraps significant authenticated mutations in an audit boundary.
 *
 * The middleware deliberately observes the database before and after the
 * controller runs instead of trusting submitted form values. A row is written
 * only when the operation actually changed persisted state. The audit insert
 * shares the same database transaction, so an audit failure cannot leave an
 * unaudited administrative change behind.
 */
final class AuditTrailMiddleware implements MiddlewareInterface
{
    private const SAVEPOINT = 'mirvmon_audit_trail_request';

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

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        $this->pdo->exec('SAVEPOINT ' . self::SAVEPOINT);

        try {
            $before = $this->snapshot($operation, $request);
            $response = $handler->handle($request);

            if ($this->handlerFailed($response)) {
                $this->discard($ownsTransaction);
                return $response;
            }

            $after = $this->snapshot($operation, $request);
            foreach ($this->events($operation, $request, $before, $after) as $event) {
                $this->audit->record(
                    $event['action'],
                    $event['object_type'],
                    $event['object_id'],
                    $event['object_label'],
                    $event['description'],
                    $event['metadata']
                );
            }

            $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $response;
        } catch (Throwable $exception) {
            $this->rollback($ownsTransaction);
            throw $exception;
        }
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
            '#^/servers/([1-9][0-9]*)/installers$#' => 'installers_issue',
            '#^/servers/([1-9][0-9]*)/delete$#' => 'server_delete',
            '#^/servers/([1-9][0-9]*)$#' => 'server_update',
            '#^/groups/([1-9][0-9]*)/delete$#' => 'group_delete',
            '#^/groups/([1-9][0-9]*)$#' => 'group_update',
            '#^/alerts/([1-9][0-9]*)/resolve$#' => 'alert_resolve',
            '#^/agent/([1-9][0-9]*)/config$#' => 'agent_config',
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
            '/admin/notifications/test' => ['kind' => 'notifications_test', 'id' => null],
            '/admin/notifications/queue/retry' => ['kind' => 'queue_retry', 'id' => null],
            '/admin/notifications/queue/delete' => ['kind' => 'queue_delete', 'id' => null],
            '/admin/defaults/save' => ['kind' => 'defaults_save', 'id' => null],
            '/admin/language' => ['kind' => 'language_save', 'id' => null],
            '/admin/system/host' => ['kind' => 'system_host', 'id' => null],
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
            'installers_issue' => $id === null ? null : $this->installerState($id),
            'maintenance_start', 'maintenance_cancel' => $id === null ? null : $this->maintenanceState($id),
            'thresholds_save' => $id === null ? null : $this->thresholdState($id),
            'services_save' => $id === null ? null : $this->serviceState($id),
            'agent_update' => $id === null ? null : $this->agentUpdateState($id),
            'agent_config' => $id === null ? null : $this->agentConfigState($id),
            'group_create' => $this->groupByName($body['name'] ?? null),
            'group_update', 'group_delete' => $id === null ? null : $this->groupState($id),
            'user_save' => $this->userSaveState($body),
            'user_delete' => $id === null ? null : $this->userState($id),
            'notifications_save' => $this->notificationSettingsState(),
            'notifications_test' => [
                'settings' => $this->notificationSettingsState(),
                'queue_total' => $this->queueTotal(),
            ],
            'queue_retry' => $this->queueRetryState(),
            'queue_job_retry', 'queue_job_delete' => $id === null ? null : $this->queueJobState($id),
            'queue_delete' => $this->queueDeleteState($body),
            'defaults_save' => $this->defaultsState(),
            'language_save' => $this->settingState('ui_language'),
            'system_host' => $this->settingState('system_host_server_id'),
            'alert_resolve' => $id === null ? null : $this->alertState($id),
            default => null,
        };
    }

    /**
     * @param array{kind:string,id:?int} $operation
     * @return list<array{action:string,object_type:string,object_id:int|string|null,object_label:?string,description:string,metadata:array<string,mixed>}>
     */
    private function events(
        array $operation,
        ServerRequestInterface $request,
        mixed $before,
        mixed $after
    ): array {
        $body = $this->body($request);
        $id = $operation['id'];

        switch ($operation['kind']) {
            case 'server_create':
                if (!is_array($after) || $after === $before) {
                    return [];
                }
                return [$this->event(
                    'server.create',
                    'server',
                    (int) $after['id'],
                    (string) $after['name'],
                    'audit.event.server.created',
                    ['name' => (string) $after['name']],
                    ['configured_fields' => $this->presentFields($body, [
                        'name', 'address', 'group_id', 'description',
                    ])]
                )];

            case 'server_update':
                if (!is_array($before) || !is_array($after) || $before === $after) {
                    return [];
                }
                $changed = $this->changedFields($before, $after, [
                    'notification_telegram_chat_id' => 'notification_recipients',
                    'notification_emails' => 'notification_recipients',
                ]);
                return [$this->event(
                    'server.update',
                    'server',
                    $id,
                    (string) $after['name'],
                    'audit.event.server.updated',
                    ['name' => (string) $after['name']],
                    ['changed_fields' => $changed]
                )];

            case 'server_delete':
                if (!is_array($before) || $after !== null) {
                    return [];
                }
                return [$this->event(
                    'server.delete',
                    'server',
                    $id,
                    (string) $before['name'],
                    'audit.event.server.deleted',
                    ['name' => (string) $before['name']]
                )];

            case 'token_rotate':
                if (!is_array($before) || !is_array($after)
                    || $before['generation'] === $after['generation']) {
                    return [];
                }
                return [$this->event(
                    'server.token.rotate',
                    'server',
                    $id,
                    (string) $after['name'],
                    'audit.event.server.token_rotated',
                    ['name' => (string) $after['name']],
                    ['generation' => $after['generation']]
                )];

            case 'installers_issue':
                if (!is_array($before) || !is_array($after)
                    || $after['active_tokens'] <= $before['active_tokens']) {
                    return [];
                }
                return [$this->event(
                    'server.installers.issue',
                    'server',
                    $id,
                    (string) $after['name'],
                    'audit.event.server.installers_issued',
                    ['name' => (string) $after['name']],
                    ['issued_count' => $after['active_tokens'] - $before['active_tokens']]
                )];

            case 'maintenance_start':
                if (!is_array($before) || !is_array($after)
                    || $after['active_count'] <= $before['active_count']) {
                    return [];
                }
                $minutes = filter_var(
                    $body['duration_minutes'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => 10080]]
                );
                return [$this->event(
                    'server.maintenance.start',
                    'server',
                    $id,
                    (string) $after['name'],
                    'audit.event.server.maintenance_started',
                    [
                        'name' => (string) $after['name'],
                        'minutes' => $minutes === false ? 0 : (int) $minutes,
                    ],
                    [
                        'duration_minutes' => $minutes === false ? null : (int) $minutes,
                        'reason_provided' => trim((string) ($body['reason'] ?? '')) !== '',
                    ]
                )];

            case 'maintenance_cancel':
                if (!is_array($before) || !is_array($after)
                    || $after['active_count'] >= $before['active_count']) {
                    return [];
                }
                return [$this->event(
                    'server.maintenance.cancel',
                    'server',
                    $id,
                    (string) $before['name'],
                    'audit.event.server.maintenance_cancelled',
                    ['name' => (string) $before['name']],
                    ['closed_windows' => $before['active_count'] - $after['active_count']]
                )];

            case 'thresholds_save':
                if (!is_array($before) || !is_array($after)
                    || $before['thresholds'] === $after['thresholds']) {
                    return [];
                }
                return [$this->event(
                    'server.thresholds.save',
                    'server',
                    $id,
                    (string) $after['name'],
                    'audit.event.server.thresholds_saved',
                    ['name' => (string) $after['name']],
                    [
                        'changed_metrics' => $this->changedMapKeys(
                            $before['thresholds'],
                            $after['thresholds']
                        ),
                        'configured_count' => count($after['thresholds']),
                    ]
                )];

            case 'services_save':
                if (!is_array($before) || !is_array($after)
                    || $before['services'] === $after['services']) {
                    return [];
                }
                return [$this->event(
                    'server.services.save',
                    'server',
                    $id,
                    (string) $after['name'],
                    'audit.event.server.services_saved',
                    ['name' => (string) $after['name']],
                    [
                        'added' => array_values(array_diff($after['services'], $before['services'])),
                        'removed' => array_values(array_diff($before['services'], $after['services'])),
                    ]
                )];

            case 'agent_config':
                if (!is_array($before) || !is_array($after) || $before === $after) {
                    return [];
                }
                return [$this->event(
                    'server.agent_config.save',
                    'server',
                    $id,
                    (string) $after['name'],
                    'audit.event.server.agent_config_saved',
                    ['name' => (string) $after['name']],
                    ['changed_fields' => $this->changedFields($before, $after)]
                )];

            case 'agent_update':
                if (!is_array($after) || $after === $before) {
                    return [];
                }
                return [$this->event(
                    'server.agent_update.request',
                    'server',
                    $id,
                    (string) $after['name'],
                    'audit.event.server.agent_update_requested',
                    [
                        'name' => (string) $after['name'],
                        'version' => (string) ($after['target_version'] ?? ''),
                    ],
                    [
                        'command_id' => $after['command_id'] ?? null,
                        'target_version' => $after['target_version'] ?? null,
                        'target_artifact' => $after['target_artifact'] ?? null,
                    ]
                )];

            case 'group_create':
                if (!is_array($after) || $after === $before) {
                    return [];
                }
                return [$this->event(
                    'group.create',
                    'group',
                    (int) $after['id'],
                    (string) $after['name'],
                    'audit.event.group.created',
                    ['name' => (string) $after['name']]
                )];

            case 'group_update':
                if (!is_array($before) || !is_array($after) || $before === $after) {
                    return [];
                }
                return [$this->event(
                    'group.update',
                    'group',
                    $id,
                    (string) $after['name'],
                    'audit.event.group.updated',
                    ['name' => (string) $after['name']],
                    ['changed_fields' => $this->changedFields($before, $after)]
                )];

            case 'group_delete':
                if (!is_array($before) || $after !== null) {
                    return [];
                }
                return [$this->event(
                    'group.delete',
                    'group',
                    $id,
                    (string) $before['name'],
                    'audit.event.group.deleted',
                    ['name' => (string) $before['name']]
                )];

            case 'user_save':
                if (!is_array($after) || $after === $before) {
                    return [];
                }
                $created = $before === null;
                return [$this->event(
                    $created ? 'user.create' : 'user.update',
                    'user',
                    (int) $after['id'],
                    (string) $after['username'],
                    $created ? 'audit.event.user.created' : 'audit.event.user.updated',
                    ['name' => (string) $after['username']],
                    $created ? [] : [
                        'changed_fields' => $this->changedFields($before, $after, [
                            'password_hash' => 'password',
                            'telegram_chat_id' => 'notification_recipients',
                            'email_for_alerts' => 'notification_recipients',
                        ]),
                    ]
                )];

            case 'user_delete':
                if (!is_array($before) || $after !== null) {
                    return [];
                }
                return [$this->event(
                    'user.delete',
                    'user',
                    $id,
                    (string) $before['username'],
                    'audit.event.user.deleted',
                    ['name' => (string) $before['username']]
                )];

            case 'notifications_save':
                return $this->notificationSettingEvents($before, $after);

            case 'notifications_test':
                if (!is_array($before) || !is_array($after)) {
                    return [];
                }
                $events = $this->notificationSettingEvents(
                    $before['settings'] ?? null,
                    $after['settings'] ?? null
                );
                $queued = max(0, (int) ($after['queue_total'] ?? 0) - (int) ($before['queue_total'] ?? 0));
                if ($queued > 0) {
                    $events[] = $this->event(
                        'notifications.test',
                        'notification_queue',
                        null,
                        null,
                        'audit.event.notifications.tested',
                        ['count' => $queued],
                        ['queued_count' => $queued]
                    );
                }
                return $events;

            case 'queue_retry':
                if (!is_array($before) || !is_array($after)) {
                    return [];
                }
                $retried = max(0, (int) $before['retryable'] - (int) $after['retryable']);
                if ($retried === 0) {
                    return [];
                }
                return [$this->event(
                    'notification_queue.retry',
                    'notification_queue',
                    null,
                    null,
                    'audit.event.queue.retried',
                    ['count' => $retried],
                    ['affected_count' => $retried]
                )];

            case 'queue_job_retry':
                if (!is_array($before) || !is_array($after)
                    || $before['status'] === $after['status']) {
                    return [];
                }
                return [$this->event(
                    'notification_queue.job.retry',
                    'notification_job',
                    $id,
                    '#' . $id,
                    'audit.event.queue.job_retried',
                    ['id' => $id],
                    [
                        'previous_status' => $before['status'],
                        'new_status' => $after['status'],
                        'channel' => $after['channel'],
                        'server_id' => $after['server_id'],
                    ]
                )];

            case 'queue_job_delete':
                if (!is_array($before) || $after !== null) {
                    return [];
                }
                return [$this->event(
                    'notification_queue.job.delete',
                    'notification_job',
                    $id,
                    '#' . $id,
                    'audit.event.queue.job_deleted',
                    ['id' => $id],
                    [
                        'status' => $before['status'],
                        'channel' => $before['channel'],
                        'server_id' => $before['server_id'],
                    ]
                )];

            case 'queue_delete':
                if (!is_array($before) || !is_array($after)) {
                    return [];
                }
                $deleted = max(0, (int) $before['matching'] - (int) $after['matching']);
                if ($deleted === 0) {
                    return [];
                }
                return [$this->event(
                    'notification_queue.delete',
                    'notification_queue',
                    null,
                    null,
                    'audit.event.queue.deleted',
                    ['count' => $deleted],
                    [
                        'affected_count' => $deleted,
                        'filters' => $this->safeQueueFilters($before['filters']),
                    ]
                )];

            case 'defaults_save':
                if (!is_array($before) || !is_array($after) || $before === $after) {
                    return [];
                }
                return [$this->event(
                    'defaults.save',
                    'defaults',
                    null,
                    null,
                    'audit.event.defaults.saved',
                    [],
                    ['changed_fields' => $this->changedFields($before, $after)]
                )];

            case 'language_save':
                if ($before === $after || !is_array($after)) {
                    return [];
                }
                $locale = (string) ($after['value'] ?? '');
                return [$this->event(
                    'language.save',
                    'language',
                    null,
                    null,
                    'audit.event.language.saved',
                    ['locale' => $locale],
                    ['locale' => $locale]
                )];

            case 'system_host':
                if ($before === $after) {
                    return [];
                }
                $serverId = is_array($after) ? $this->positiveInt($after['value'] ?? null) : null;
                $serverName = $serverId === null ? null : ($this->servers->find($serverId)['name'] ?? null);
                return [$this->event(
                    $serverId === null ? 'system.host.clear' : 'system.host.save',
                    'system',
                    $serverId,
                    is_string($serverName) ? $serverName : null,
                    $serverId === null
                        ? 'audit.event.system.host_cleared'
                        : 'audit.event.system.host_saved',
                    [],
                    ['server_id' => $serverId]
                )];

            case 'alert_resolve':
                if (!is_array($before) || !is_array($after)
                    || $before['resolved'] === true || $after['resolved'] !== true) {
                    return [];
                }
                return [$this->event(
                    'alert.resolve',
                    'alert',
                    $id,
                    '#' . $id,
                    'audit.event.alert.resolved',
                    ['id' => $id],
                    [
                        'server_id' => $after['server_id'],
                        'kind' => $after['kind'],
                        'severity' => $after['severity'],
                    ]
                )];
        }

        return [];
    }

    /** @return list<array{action:string,object_type:string,object_id:int|string|null,object_label:?string,description:string,metadata:array<string,mixed>}> */
    private function notificationSettingEvents(mixed $before, mixed $after): array
    {
        if (!is_array($before) || !is_array($after) || $before === $after) {
            return [];
        }
        return [$this->event(
            'notifications.save',
            'notification_settings',
            1,
            null,
            'audit.event.notifications.saved',
            [],
            ['changed_fields' => $this->changedFields($before, $after, [
                'smtp_password_encrypted' => 'smtp_password',
                'telegram_bot_token_encrypted' => 'telegram_bot_token',
                'telegram_proxy_password_encrypted' => 'telegram_proxy_password',
            ])]
        )];
    }

    /**
     * @param array<string, scalar> $parameters
     * @param array<string, mixed> $metadata
     * @return array{action:string,object_type:string,object_id:int|string|null,object_label:?string,description:string,metadata:array<string,mixed>}
     */
    private function event(
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
        if (!is_string($name) || trim($name) === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id FROM servers WHERE name = :name ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['name' => trim($name)]);
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
        if (!is_array($row)) {
            return null;
        }
        return [
            'name' => (string) $row['name'],
            'generation' => $row['token_generation'] === null
                ? null
                : (int) $row['token_generation'],
        ];
    }

    /** @return array{name:string,active_tokens:int}|null */
    private function installerState(int $serverId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT servers.name,
                    count(installer_tokens.id) FILTER (
                        WHERE installer_tokens.consumed_at IS NULL
                          AND installer_tokens.expires_at > CURRENT_TIMESTAMP
                    ) AS active_tokens
             FROM servers
             LEFT JOIN installer_tokens ON installer_tokens.server_id = servers.id
             WHERE servers.id = :id
             GROUP BY servers.id, servers.name'
        );
        $statement->execute(['id' => $serverId]);
        $row = $statement->fetch();
        return is_array($row) ? [
            'name' => (string) $row['name'],
            'active_tokens' => (int) $row['active_tokens'],
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
        if ($server === null) {
            return null;
        }
        return [
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
        return ['name' => (string) $server['name'], 'services' => $services];
    }

    /** @return array<string, mixed>|null */
    private function agentConfigState(int $serverId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT servers.name,
                    agent_configs.interval_seconds,
                    agent_configs.monitor_services,
                    agent_configs.enabled
             FROM servers
             LEFT JOIN agent_configs ON agent_configs.server_id = servers.id
             WHERE servers.id = :id'
        );
        $statement->execute(['id' => $serverId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $services = $this->stringList($row['monitor_services'] ?? []);
        sort($services, SORT_STRING);
        return [
            'name' => (string) $row['name'],
            'interval_seconds' => $row['interval_seconds'] === null ? null : (int) $row['interval_seconds'],
            'monitor_services' => $services,
            'enabled' => $this->toBool($row['enabled'] ?? true),
        ];
    }

    /** @return array<string, mixed>|null */
    private function agentUpdateState(int $serverId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT servers.name,
                    commands.id AS command_id,
                    commands.target_version,
                    commands.target_artifact,
                    commands.state
             FROM servers
             LEFT JOIN LATERAL (
                SELECT id, target_version, target_artifact, state
                FROM agent_update_commands
                WHERE server_id = servers.id
                ORDER BY created_at DESC, id DESC
                LIMIT 1
             ) AS commands ON TRUE
             WHERE servers.id = :id'
        );
        $statement->execute(['id' => $serverId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'name' => (string) $row['name'],
            'command_id' => $row['command_id'] === null ? null : (string) $row['command_id'],
            'target_version' => $row['target_version'] === null ? null : (string) $row['target_version'],
            'target_artifact' => $row['target_artifact'] === null ? null : (string) $row['target_artifact'],
            'state' => $row['state'] === null ? null : (string) $row['state'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function groupState(int $groupId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, description, icon, color FROM server_groups WHERE id = :id'
        );
        $statement->execute(['id' => $groupId]);
        $row = $statement->fetch();
        return is_array($row) ? [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => $row['description'] ?? null,
            'icon' => (string) $row['icon'],
            'color' => (string) $row['color'],
        ] : null;
    }

    /** @return array<string, mixed>|null */
    private function groupByName(mixed $name): ?array
    {
        if (!is_string($name) || trim($name) === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id FROM server_groups WHERE name = :name ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['name' => trim($name)]);
        $id = $statement->fetchColumn();
        return $id === false ? null : $this->groupState((int) $id);
    }

    /** @param array<string,mixed> $body */
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
            'password_hash' => (string) $row['password_hash'],
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
        unset($row['updated_at']);
        ksort($row, SORT_STRING);
        return $row;
    }

    /** @return array{retryable:int} */
    private function queueRetryState(): array
    {
        $count = $this->pdo->query(
            "SELECT count(*) FROM notification_outbox WHERE status IN ('failed', 'dead')"
        )?->fetchColumn();
        return ['retryable' => (int) $count];
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

    /** @param array<string,mixed> $body @return array{matching:int,filters:array<string,mixed>} */
    private function queueDeleteState(array $body): array
    {
        $filters = $this->outbox->filters($body);
        return [
            'matching' => $this->outbox->countMatching($filters),
            'filters' => $filters,
        ];
    }

    /** @return array<string, mixed> */
    private function defaultsState(): array
    {
        $keys = [
            'default_offline_timeout',
            'default_warning_threshold',
            'default_critical_threshold',
            'default_duration_seconds',
            'default_recovery_duration_seconds',
        ];
        $statement = $this->pdo->query(
            "SELECT setting_key, setting_value::text AS setting_value
             FROM app_settings
             WHERE setting_key IN ('" . implode("','", $keys) . "')
             ORDER BY setting_key"
        );
        $settings = [];
        foreach ($statement?->fetchAll() ?? [] as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $settings;
    }

    /** @return array{value:mixed}|null */
    private function settingState(string $key): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_value FROM app_settings WHERE setting_key = :key'
        );
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        if ($value === false) {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return ['value' => $decoded];
    }

    /** @return array<string,mixed>|null */
    private function alertState(int $alertId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, server_id, kind, severity, resolved
             FROM alerts WHERE id = :id'
        );
        $statement->execute(['id' => $alertId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'server_id' => (int) $row['server_id'],
            'kind' => (string) $row['kind'],
            'severity' => (string) $row['severity'],
            'resolved' => $this->toBool($row['resolved']),
        ];
    }

    private function queueTotal(): int
    {
        return (int) ($this->pdo->query('SELECT count(*) FROM notification_outbox')?->fetchColumn() ?: 0);
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
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

    /** @param array<string,mixed> $before @param array<string,mixed> $after @param array<string,string> $aliases @return list<string> */
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

    /** @param array<string,mixed> $before @param array<string,mixed> $after @return list<string> */
    private function changedMapKeys(array $before, array $after): array
    {
        return $this->changedFields($before, $after);
    }

    /** @param array<string,mixed> $body @param list<string> $fields @return list<string> */
    private function presentFields(array $body, array $fields): array
    {
        return array_values(array_filter(
            $fields,
            static fn (string $field): bool => array_key_exists($field, $body)
        ));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_string'));
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? null : (int) $value;
    }

    private function toBool(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array($value, ['1', 't', 'true'], true);
    }

    private function handlerFailed(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return true;
        }
        $flashType = $_SESSION['flash_type'] ?? null;
        return is_string($flashType) && in_array($flashType, ['error', 'danger'], true);
    }

    private function discard(bool $ownsTransaction): void
    {
        $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
        $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        if ($ownsTransaction) {
            $this->pdo->commit();
        }
    }

    private function rollback(bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        }
    }
}
