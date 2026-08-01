<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AgentCredentialIssuer;
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
        private readonly AgentCredentialIssuer $credentials
    ) {
    }

    /** @param array<string, string> $args */
    public function index(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $servers = $this->pdo->query(
            'SELECT servers.*, server_groups.name AS group_name
             FROM servers
             LEFT JOIN server_groups ON server_groups.id = servers.group_id
             ORDER BY servers.name, servers.id'
        )?->fetchAll() ?? [];

        return $this->twig->render($response, 'servers/index.twig', [
            'title' => 'Серверы',
            'servers' => $servers,
        ]);
    }

    /** @param array<string, string> $args */
    public function create(
        Request $request,
        Response $response,
        array $args
    ): Response {
        return $this->twig->render($response, 'servers/create.twig', [
            'title' => 'Добавить сервер',
            'groups' => $this->groups(),
        ]);
    }

    /** @param array<string, string> $args */
    public function store(
        Request $request,
        Response $response,
        array $args
    ): Response {
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
                    name,
                    address,
                    group_id,
                    description,
                    offline_timeout_seconds,
                    notify_on_offline
                 ) VALUES (
                    :name,
                    :address,
                    :group_id,
                    :description,
                    :offline_timeout_seconds,
                    TRUE
                 )
                 RETURNING id'
            );
            $statement->execute([
                'name' => $name,
                'address' => $this->optionalString($body['address'] ?? null, 255),
                'group_id' => $this->optionalId($body['group_id'] ?? null),
                'description' => $this->optionalString($body['description'] ?? null),
                'offline_timeout_seconds' => $this->defaultOfflineTimeout(),
            ]);
            $serverId = (int) $statement->fetchColumn();

            $config = $this->pdo->prepare(
                'INSERT INTO agent_configs (server_id) VALUES (:server_id)'
            );
            $config->execute(['server_id' => $serverId]);
            $installerTokens = $this->installerTokens($serverId);
            $this->commitTransaction($ownsTransaction, 'server_store');

            return $this->createdResponse(
                $response,
                $serverId,
                $name,
                $installerTokens
            );
        } catch (Throwable) {
            $this->rollbackTransaction($ownsTransaction, 'server_store');

            return $this->redirect($response, '/servers/create');
        }
    }

    /** @param array<string, string> $args */
    public function edit(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $this->redirect($response, '/servers');
        }

        $statement = $this->pdo->prepare(
            'SELECT * FROM servers WHERE id = :server_id'
        );
        $statement->execute(['server_id' => $serverId]);
        $server = $statement->fetch();
        if (!is_array($server)) {
            return $this->redirect($response, '/servers');
        }

        $metrics = $this->pdo->prepare(
            'SELECT DISTINCT metric_names.id, metric_names.name, metric_names.unit
             FROM current_metric_values
             INNER JOIN metric_names
               ON metric_names.id = current_metric_values.metric_id
             WHERE current_metric_values.server_id = :server_id
             ORDER BY metric_names.name'
        );
        $metrics->execute(['server_id' => $serverId]);
        $hasToken = $this->pdo->prepare(
            'SELECT EXISTS(
                SELECT 1 FROM agent_tokens WHERE server_id = :server_id
             )'
        );
        $hasToken->execute(['server_id' => $serverId]);

        return $this->twig->render($response, 'servers/edit.twig', [
            'title' => 'Редактировать сервер',
            'server' => $server,
            'groups' => $this->groups(),
            'has_agent_token' => $this->toBool($hasToken->fetchColumn()),
            'allMetrics' => $metrics->fetchAll(),
            'server_display_metrics' => $this->decodeStringList(
                $server['display_metrics'] ?? '[]'
            ),
            'server_notification_emails' => $this->decodeStringList(
                $server['notification_emails'] ?? '[]'
            ),
        ]);
    }

    /** @param array<string, string> $args */
    public function update(
        Request $request,
        Response $response,
        array $args
    ): Response {
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
        $displayMetrics = $body['display_metrics'] ?? [];
        if (
            $name === ''
            || strlen($name) > 100
            || $timeout === false
            || !is_array($displayMetrics)
        ) {
            return $this->redirect($response, '/servers/' . $serverId . '/edit');
        }
        $displayMetrics = array_values(array_unique(array_filter(
            $displayMetrics,
            static fn (mixed $metric): bool =>
                is_string($metric)
                && preg_match('/^[a-z][a-z0-9_]{0,99}$/', $metric) === 1
        )));

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
            'notify_on_offline' => isset($body['notify_on_offline']),
            'notification_telegram_chat_id' => $this->optionalString(
                $body['notification_telegram_chat_id'] ?? null,
                100
            ),
            'notification_emails' => json_encode($recipientEmails, JSON_THROW_ON_ERROR),
            'display_metrics' => json_encode($displayMetrics, JSON_THROW_ON_ERROR),
        ]);

        return $this->redirect($response, '/servers');
    }

    /** @param array<string, string> $args */
    public function delete(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $serverId = $this->serverId($args);
        if ($serverId !== null) {
            $statement = $this->pdo->prepare(
                'DELETE FROM servers WHERE id = :server_id'
            );
            $statement->execute(['server_id' => $serverId]);
        }

        return $this->redirect($response, '/servers');
    }

    /** @param array<string, string> $args */
    public function regenerateToken(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $this->redirect($response, '/servers');
        }

        $statement = $this->pdo->prepare(
            'SELECT name FROM servers WHERE id = :server_id'
        );
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

            return $this->createdResponse(
                $response,
                $serverId,
                (string) $name,
                $installerTokens
            );
        } catch (Throwable) {
            $this->rollbackTransaction($ownsTransaction, 'server_regenerate');

            return $this->redirect($response, '/servers/' . $serverId . '/edit');
        }
    }

    /** @param array<string, string> $args */
    public function installers(
        Request $request,
        Response $response,
        array $args
    ): Response {
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
        try {
            return $this->createdResponse(
                $response,
                $serverId,
                (string) $name,
                $this->installerTokens($serverId)
            );
        } catch (Throwable) {
            return $this->redirect($response, '/servers/' . $serverId . '/edit');
        }
    }

    /** @return list<array<string, mixed>> */
    private function groups(): array
    {
        return $this->pdo->query(
            'SELECT * FROM server_groups ORDER BY name, id'
        )?->fetchAll() ?? [];
    }

    private function defaultOfflineTimeout(): int
    {
        $statement = $this->pdo->prepare(
            "SELECT setting_value
             FROM app_settings
             WHERE setting_key = 'default_offline_timeout'"
        );
        $statement->execute();
        $value = $statement->fetchColumn();
        if ($value === false) {
            return 300;
        }

        try {
            return max(0, min(86400, (int) json_decode(
                (string) $value,
                true,
                512,
                JSON_THROW_ON_ERROR
            )));
        } catch (JsonException) {
            return 300;
        }
    }

    /**
     * @return array{
     *     linux: string,
     *     powershell: string,
     *     batch: string,
     *     legacy_powershell: string,
     *     legacy_batch: string
     * }
     */
    private function installerTokens(int $serverId): array
    {
        return [
            'linux' => $this->credentials->issueInstaller($serverId),
            'powershell' => $this->credentials->issueInstaller($serverId),
            'batch' => $this->credentials->issueInstaller($serverId),
            'legacy_powershell' => $this->credentials->issueInstaller($serverId),
            'legacy_batch' => $this->credentials->issueInstaller($serverId),
        ];
    }

    /**
     * @param array{
     *     linux: string,
     *     powershell: string,
     *     batch: string,
     *     legacy_powershell: string,
     *     legacy_batch: string
     * } $tokens
     */
    private function createdResponse(
        Response $response,
        int $serverId,
        string $name,
        array $tokens
    ): Response {
        return $this->twig->render($response, 'servers/created.twig', [
            'title' => 'Установщики агента',
            'server' => ['id' => $serverId, 'name' => $name],
            'installer_tokens' => $tokens,
        ]);
    }

    /**
     * @return list<string>
     * @throws InvalidArgumentException When an address is not an email.
     */
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
                    'Проверьте адреса получателей: «' . $email . '» не похож на email'
                );
            }
            if (!in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }
        if (count($emails) > 20) {
            throw new InvalidArgumentException('Слишком много адресов получателей');
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

    private function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $id === false ? null : $id;
    }

    /** @return list<string> */
    private function decodeStringList(mixed $value): array
    {
        try {
            $decoded = is_string($value)
                ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
                : $value;
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded)
            ? array_values(array_filter($decoded, 'is_string'))
            : [];
    }

    /** @param array<string, mixed> $args */
    private function serverId(array $args): ?int
    {
        $serverId = filter_var(
            $args['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $serverId === false ? null : $serverId;
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
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

    private function commitTransaction(
        bool $ownsTransaction,
        string $savepoint
    ): void {
        if ($ownsTransaction) {
            $this->pdo->commit();
            return;
        }

        $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }

    private function rollbackTransaction(
        bool $ownsTransaction,
        string $savepoint
    ): void {
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
