<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\MetricRepository;
use App\Repositories\ServerRepository;
use App\Services\AgentCredentialIssuer;
use App\Services\AgentUpdateService;
use App\Services\DashboardMetricService;
use App\Services\ServerStatusService;
use InvalidArgumentException;
use JsonException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

final class ServerController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly AgentCredentialIssuer $credentials,
        private readonly ?AgentUpdateService $agentUpdates,
        private readonly ServerStatusService $status,
        private readonly Translator $translator = new Translator()
    ) {
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        $query = $request->getQueryParams();
        $sortColumns = [
            'name' => 'servers.name',
            'address' => 'servers.address',
            'group' => 'monitoring_groups.name',
            'description' => 'servers.description',
            'last_metrics' => 'servers.last_metrics_at',
        ];
        $sort = is_string($query['sort'] ?? null) && isset($sortColumns[$query['sort']])
            ? $query['sort']
            : 'name';
        $direction = ($query['direction'] ?? null) === 'desc' ? 'DESC' : 'ASC';
        $filters = [];
        $conditions = [];
        $parameters = [];
        foreach ([
            'name' => 'servers.name',
            'address' => 'servers.address',
            'group' => 'monitoring_groups.name',
            'description' => 'servers.description',
            'last_metrics' => 'CAST(servers.last_metrics_at AS text)',
        ] as $key => $column) {
            $value = $this->searchTerm($query[$key] ?? null);
            $filters[$key] = $value ?? '';
            if ($value !== null) {
                $conditions[] = $column . " ILIKE :" . $key . " ESCAPE E'\\\\'";
                $parameters[$key] = '%' . $this->escapeLike($value) . '%';
            }
        }
        $statement = $this->pdo->prepare(
            'SELECT
                servers.*,
                agent_tokens.last_used_at AS last_contact_at,
                monitoring_groups.name AS group_name,
                monitoring_groups.icon AS group_icon,
                monitoring_groups.color AS group_color,
                COALESCE(alert_counts.warning_alerts, 0) AS warning_alerts,
                COALESCE(alert_counts.critical_alerts, 0) AS critical_alerts,
                COALESCE(agent_configs.website_probe_enabled, FALSE) AS website_probe_enabled,
                jsonb_exists(COALESCE(servers.agent_capabilities, \'[]\'::jsonb), \'website_probe_v1\') AS website_probe_capable,
                COALESCE(probe_usage.site_count, 0) AS probe_site_count
             FROM servers
             LEFT JOIN agent_tokens ON agent_tokens.server_id = servers.id
             LEFT JOIN agent_configs ON agent_configs.server_id = servers.id
             LEFT JOIN monitoring_groups ON monitoring_groups.id = servers.group_id
             LEFT JOIN LATERAL (
                SELECT count(*) AS site_count
                FROM website_probe_agents
                WHERE website_probe_agents.server_id = servers.id
             ) AS probe_usage ON TRUE
             LEFT JOIN LATERAL (
                SELECT
                    count(*) FILTER (WHERE severity = \'warning\') AS warning_alerts,
                    count(*) FILTER (WHERE severity = \'critical\') AS critical_alerts
                FROM alerts
                WHERE alerts.server_id = servers.id
                  AND alerts.resolved = FALSE
             ) AS alert_counts ON TRUE'
            . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
            . ' ORDER BY ' . $sortColumns[$sort] . ' ' . $direction
            . ' NULLS LAST, servers.id ASC'
        );
        $statement->execute($parameters);
        $servers = $this->status->enrich($statement->fetchAll());
        foreach ($servers as &$server) {
            $server['website_probe_enabled'] = $this->databaseBool(
                $server['website_probe_enabled'] ?? false
            );
            $server['website_probe_capable'] = $this->databaseBool(
                $server['website_probe_capable'] ?? false
            );
            $server['probe_site_count'] = (int) ($server['probe_site_count'] ?? 0);
        }
        unset($server);
        $agentUpdateSummary = null;
        if ($this->agentUpdates !== null) {
            $statuses = $this->agentUpdates->statusesForServers(array_map(
                static fn (array $server): int => (int) $server['id'],
                $servers
            ));
            foreach ($servers as &$server) {
                $server['agent_update'] = $statuses[(int) $server['id']] ?? null;
            }
            unset($server);
            $agentUpdateSummary = $this->agentUpdates->summarizeStatuses($statuses);
        }
        $sortUrls = [];
        foreach (array_keys($sortColumns) as $key) {
            $sortUrls[$key] = '/servers?' . http_build_query(array_merge(
                $filters,
                [
                    'sort' => $key,
                    'direction' => $sort === $key && $direction === 'ASC' ? 'desc' : 'asc',
                ]
            ));
        }

        return $this->twig->render($response, 'servers/index.twig', [
            'title' => $this->translator->trans('servers.title'),
            'servers' => $servers,
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'sort_urls' => $sortUrls,
            'agent_update_summary' => $agentUpdateSummary,
        ]);
    }

    /** @param array<string, string> $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        return $this->twig->render($response, 'servers/create.twig', [
            'title' => $this->translator->trans('servers.create.title'),
            'groups' => $this->groups(),
        ]);
    }

    /** @param array<string, string> $args */
    public function store(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->redirect($response, '/servers/create');
        }
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || strlen($name) > 100) {
            return $this->redirect($response, '/servers/create');
        }

        $ownsTransaction = $this->beginTransaction('server_store');
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO servers (
                    name, address, group_id, description,
                    offline_timeout_seconds, notify_on_offline
                 ) VALUES (
                    :name, :address, :group_id, :description,
                    :offline_timeout_seconds, TRUE
                 ) RETURNING id'
            );
            $statement->execute([
                'name' => $name,
                'address' => $this->optionalString($body['address'] ?? null, 255),
                'group_id' => $this->optionalId($body['group_id'] ?? null),
                'description' => $this->optionalString($body['description'] ?? null),
                'offline_timeout_seconds' => $this->defaultOfflineTimeout(),
            ]);
            $serverId = (int) $statement->fetchColumn();
            $config = $this->pdo->prepare('INSERT INTO agent_configs (server_id) VALUES (:server_id)');
            $config->execute(['server_id' => $serverId]);
            $installerTokens = $this->installerTokens($serverId);
            $this->commitTransaction($ownsTransaction, 'server_store');

            return $this->createdResponse($response, $serverId, $name, $installerTokens);
        } catch (Throwable) {
            $this->rollbackTransaction($ownsTransaction, 'server_store');
            return $this->redirect($response, '/servers/create');
        }
    }

    /** @param array<string, string> $args */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $this->redirect($response, '/servers');
        }
        $statement = $this->pdo->prepare('SELECT * FROM servers WHERE id = :server_id');
        $statement->execute(['server_id' => $serverId]);
        $server = $statement->fetch();
        if (!is_array($server)) {
            return $this->redirect($response, '/servers');
        }

        $savedMetrics = $this->decodeStringList($server['display_metrics'] ?? '[]');
        $display = (new DashboardMetricService($this->pdo))->displayOptions($serverId, $savedMetrics);
        $agentToken = $this->pdo->prepare('SELECT token_generation FROM agent_tokens WHERE server_id = :server_id');
        $agentToken->execute(['server_id' => $serverId]);
        $tokenGeneration = $agentToken->fetchColumn();
        $probeConfig = $this->pdo->prepare(
            'SELECT website_probe_enabled FROM agent_configs WHERE server_id = :server_id'
        );
        $probeConfig->execute(['server_id' => $serverId]);
        $probeEnabled = $probeConfig->fetchColumn();
        $server['website_probe_enabled'] = $probeEnabled === true
            || $probeEnabled === 1 || $probeEnabled === '1' || $probeEnabled === 't';
        $serverRepository = new ServerRepository($this->pdo);
        $metricRepository = new MetricRepository($this->pdo);

        return $this->twig->render($response, 'servers/edit.twig', [
            'title' => $this->translator->trans('servers.edit.title', ['name' => (string) $server['name']]),
            'server' => $server,
            'groups' => $this->groups(),
            'has_agent_token' => $tokenGeneration !== false,
            'requires_token_rotation' => $this->requiresTokenRotation($serverId),
            'display_groups' => $display['groups'],
            'selected_widgets' => $display['selected'],
            'server_notification_emails' => $this->decodeStringList($server['notification_emails'] ?? '[]'),
            'all_metric_types' => $metricRepository->metricTypes($serverId),
            'existing_thresholds' => $serverRepository->thresholds($serverId),
            'all_services' => $serverRepository->services($serverId),
            'monitor_services' => $serverRepository->monitoredServices($serverId),
        ]);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        $body = $request->getParsedBody();
        if ($serverId === null || !is_array($body)) {
            return $this->redirect($response, '/servers');
        }

        $name = trim((string) ($body['name'] ?? ''));
        $timeout = filter_var(
            $body['offline_timeout'] ?? 300,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 86400]]
        );
        $displayWidgets = $body['display_widgets'] ?? [];
        if ($name === '' || strlen($name) > 100 || $timeout === false || !is_array($displayWidgets)) {
            return $this->redirect($response, '/servers/' . $serverId . '/edit');
        }
        $displayWidgets = array_values(array_unique(array_filter(
            $displayWidgets,
            static fn (mixed $widget): bool =>
                is_string($widget) && preg_match('/^[a-z][a-z0-9_]{0,99}$/', $widget) === 1
        )));
        $displayMetrics = (new DashboardMetricService($this->pdo))->expandWidgets($serverId, $displayWidgets);

        $statement = $this->pdo->prepare(
            'UPDATE servers SET
                name = :name,
                address = :address,
                group_id = :group_id,
                description = :description,
                offline_timeout_seconds = :offline_timeout_seconds,
                notify_on_offline = :notify_on_offline,
                notification_telegram_chat_id = :notification_telegram_chat_id,
                notification_emails = CAST(:notification_emails AS jsonb),
                display_metrics = CAST(:display_metrics AS jsonb)
             WHERE id = :server_id'
        );
        try {
            $recipientEmails = $this->emailList($body['notification_emails'] ?? null);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['flash_message'] = $exception->getMessage();
            $_SESSION['flash_type'] = 'danger';
            return $this->redirect($response, '/servers/' . $serverId . '/edit');
        }
        $statement->execute([
            'server_id' => $serverId,
            'name' => $name,
            'address' => $this->optionalString($body['address'] ?? null, 255),
            'group_id' => $this->optionalId($body['group_id'] ?? null),
            'description' => $this->optionalString($body['description'] ?? null),
            'offline_timeout_seconds' => $timeout,
            'notify_on_offline' => isset($body['notify_on_offline']) ? 1 : 0,
            'notification_telegram_chat_id' => $this->optionalString($body['notification_telegram_chat_id'] ?? null, 100),
            'notification_emails' => json_encode($recipientEmails, JSON_THROW_ON_ERROR),
            'display_metrics' => json_encode($displayMetrics, JSON_THROW_ON_ERROR),
        ]);
        $probe = $this->pdo->prepare(
            'UPDATE agent_configs
             SET website_probe_enabled = :enabled
             WHERE server_id = :server_id'
        );
        $probeEnabled = isset($body['website_probe_enabled']);
        $probe->execute([
            'server_id' => $serverId,
            'enabled' => $probeEnabled ? 1 : 0,
        ]);
        if (!$probeEnabled) {
            $this->detachWebsiteProbeAssignments($serverId);
        }
        $_SESSION['flash_message'] = $this->translator->trans('server18.settings.saved');
        $_SESSION['flash_type'] = 'success';

        return $this->redirect($response, '/servers/' . $serverId . '/edit');
    }

    /** @param array<string, string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId !== null) {
            $this->detachWebsiteProbeAssignments($serverId);
            $statement = $this->pdo->prepare('DELETE FROM servers WHERE id = :server_id');
            $statement->execute(['server_id' => $serverId]);
        }
        return $this->redirect($response, '/servers');
    }

    /** @param array<string, string> $args */
    public function regenerateToken(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $this->redirect($response, '/servers');
        }
        $statement = $this->pdo->prepare('SELECT name FROM servers WHERE id = :server_id');
        $statement->execute(['server_id' => $serverId]);
        $name = $statement->fetchColumn();
        if ($name === false) {
            return $this->redirect($response, '/servers');
        }

        $ownsTransaction = $this->beginTransaction('server_regenerate');
        try {
            $this->credentials->rotate($serverId);
            $installerTokens = $this->installerTokens($serverId);
            $this->commitTransaction($ownsTransaction, 'server_regenerate');
            return $this->createdResponse($response, $serverId, (string) $name, $installerTokens);
        } catch (Throwable) {
            $this->rollbackTransaction($ownsTransaction, 'server_regenerate');
            return $this->redirect($response, '/servers/' . $serverId);
        }
    }

    /** @param array<string, string> $args */
    public function installers(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $this->redirect($response, '/servers');
        }
        $statement = $this->pdo->prepare('SELECT name FROM servers WHERE id = :server_id');
        $statement->execute(['server_id' => $serverId]);
        $name = $statement->fetchColumn();
        if ($name === false) {
            return $this->redirect($response, '/servers');
        }
        if ($this->requiresTokenRotation($serverId)) {
            $_SESSION['flash_message'] = $this->translator->trans('servers.installers.rotation_required');
            $_SESSION['flash_type'] = 'warning';
            return $this->redirect($response, '/servers/' . $serverId);
        }
        try {
            return $this->createdResponse(
                $response,
                $serverId,
                (string) $name,
                $this->installerTokens($serverId)
            );
        } catch (Throwable) {
            return $this->redirect($response, '/servers/' . $serverId);
        }
    }

    /** @param array<string, string> $args */
    public function toggleWebsiteProbe(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $serverId = $this->serverId($args);
        $body = $request->getParsedBody();
        if ($serverId === null || !is_array($body)) {
            return $this->jsonResponse($response, ['error' => 'invalid_request'], 400);
        }

        $enabled = filter_var(
            $body['enabled'] ?? null,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        );
        if ($enabled === null) {
            return $this->jsonResponse($response, ['error' => 'invalid_enabled'], 422);
        }

        $statement = $this->pdo->prepare(
            "SELECT
                jsonb_exists(COALESCE(servers.agent_capabilities, '[]'::jsonb), 'website_probe_v1') AS capable,
                COALESCE(agent_configs.website_probe_enabled, FALSE) AS enabled
             FROM servers
             LEFT JOIN agent_configs ON agent_configs.server_id = servers.id
             WHERE servers.id = :server_id"
        );
        $statement->execute(['server_id' => $serverId]);
        $server = $statement->fetch();
        if (!is_array($server)) {
            return $this->jsonResponse($response, ['error' => 'not_found'], 404);
        }
        if ($enabled && !$this->databaseBool($server['capable'] ?? false)) {
            return $this->jsonResponse($response, ['error' => 'probe_not_supported'], 409);
        }

        $update = $this->pdo->prepare(
            'UPDATE agent_configs
             SET website_probe_enabled = :enabled,
                 updated_at = CURRENT_TIMESTAMP
             WHERE server_id = :server_id'
        );
        $update->execute([
            'server_id' => $serverId,
            'enabled' => $enabled ? 1 : 0,
        ]);
        if ($update->rowCount() === 0) {
            return $this->jsonResponse($response, ['error' => 'agent_not_configured'], 409);
        }

        $removedAssignments = 0;
        if (!$enabled) {
            $count = $this->pdo->prepare(
                'SELECT count(*) FROM website_probe_agents WHERE server_id = :server_id'
            );
            $count->execute(['server_id' => $serverId]);
            $removedAssignments = (int) $count->fetchColumn();
            $this->detachWebsiteProbeAssignments($serverId);
        }

        return $this->jsonResponse($response, [
            'enabled' => $enabled,
            'removed_assignments' => $removedAssignments,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function groups(): array
    {
        return $this->pdo->query('SELECT * FROM monitoring_groups ORDER BY name, id')?->fetchAll() ?? [];
    }

    private function defaultOfflineTimeout(): int
    {
        $statement = $this->pdo->prepare(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'default_offline_timeout'"
        );
        $statement->execute();
        $value = $statement->fetchColumn();
        if ($value === false) {
            return 300;
        }
        try {
            return max(0, min(86400, (int) json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR)));
        } catch (JsonException) {
            return 300;
        }
    }

    /** @return array{linux: string, windows: string} */
    private function installerTokens(int $serverId): array
    {
        return [
            'linux' => $this->credentials->issueInstaller($serverId),
            'windows' => $this->credentials->issueWindowsDownload($serverId),
        ];
    }

    private function requiresTokenRotation(int $serverId): bool
    {
        $statement = $this->pdo->prepare('SELECT token_generation FROM agent_tokens WHERE server_id = :server_id');
        $statement->execute(['server_id' => $serverId]);
        $generation = $statement->fetchColumn();
        if ($generation === false) {
            // A server without a token can safely create its first credential on demand.
            return false;
        }
        if ($generation === null) {
            return true;
        }

        // A restored token can have a perfectly valid generation while its plaintext value was
        // derived with the source installation APP_KEY. Existing agents still authenticate by
        // the restored hash, but this installation must not issue an unusable new installer.
        return !$this->credentials->canIssueInstaller($serverId);
    }

    /** @param array{linux: string, windows: string} $tokens */
    private function createdResponse(Response $response, int $serverId, string $name, array $tokens): Response
    {
        return $this->twig->render($response, 'servers/created.twig', [
            'title' => $this->translator->trans('servers.installers.title'),
            'server' => ['id' => $serverId, 'name' => $name],
            'installer_tokens' => $tokens,
        ]);
    }

    /** @return list<string> */
    private function emailList(mixed $value): array
    {
        $parts = preg_split('/[,;\r\n]+/', (string) ($value ?? '')) ?: [];
        $emails = [];
        foreach ($parts as $part) {
            $email = trim((string) $part);
            if ($email === '') {
                continue;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
                throw new InvalidArgumentException(
                    $this->translator->trans('servers.validation.email', ['email' => $email])
                );
            }
            if (!in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }
        if (count($emails) > 20) {
            throw new InvalidArgumentException($this->translator->trans('servers.validation.too_many_emails'));
        }
        return $emails;
    }

    private function optionalString(mixed $value, int $maximum = 5000): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : substr($value, 0, $maximum);
    }

    private function searchTerm(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : substr($value, 0, 100);
    }

    private function escapeLike(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    private function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    /** @return list<string> */
    private function decodeStringList(mixed $value): array
    {
        try {
            $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        } catch (JsonException) {
            return [];
        }
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @param array<string, mixed> $args */
    private function serverId(array $args): ?int
    {
        $serverId = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $serverId === false ? null : $serverId;
    }

    private function detachWebsiteProbeAssignments(int $serverId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT website_id FROM website_probe_agents WHERE server_id = :server_id'
        );
        $statement->execute(['server_id' => $serverId]);
        $websiteIds = array_map('intval', array_column($statement->fetchAll(), 'website_id'));
        if ($websiteIds === []) {
            return;
        }

        $this->pdo->prepare(
            'DELETE FROM website_probe_agents WHERE server_id = :server_id'
        )->execute(['server_id' => $serverId]);

        $count = $this->pdo->prepare(
            'SELECT central_probe_enabled,
                    (SELECT count(*) FROM website_probe_agents WHERE website_id = websites.id) AS agents
             FROM websites WHERE id = :website_id'
        );
        $update = $this->pdo->prepare(
            'UPDATE websites
             SET central_probe_enabled = :central,
                 probe_quorum = LEAST(probe_quorum, :points)
             WHERE id = :website_id'
        );
        foreach ($websiteIds as $websiteId) {
            $count->execute(['website_id' => $websiteId]);
            $row = $count->fetch();
            if (!is_array($row)) {
                continue;
            }
            $central = $row['central_probe_enabled'] === true
                || $row['central_probe_enabled'] === 1
                || $row['central_probe_enabled'] === '1'
                || $row['central_probe_enabled'] === 't';
            $agents = (int) $row['agents'];
            if (!$central && $agents === 0) {
                $central = true;
            }
            $points = max(1, ($central ? 1 : 0) + $agents);
            $update->execute([
                'website_id' => $websiteId,
                'central' => $central ? 1 : 0,
                'points' => $points,
            ]);
        }
    }

    private function databaseBool(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array($value, ['1', 't', 'true'], true);
    }

    /** @param array<string, mixed> $payload */
    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
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

    private function commitTransaction(bool $ownsTransaction, string $savepoint): void
    {
        if ($ownsTransaction) {
            $this->pdo->commit();
            return;
        }
        $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }

    private function rollbackTransaction(bool $ownsTransaction, string $savepoint): void
    {
        if ($ownsTransaction) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return;
        }
        $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
        $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }
}
