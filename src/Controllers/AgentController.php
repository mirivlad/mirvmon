<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AgentCredential;
use App\Services\AgentCredentialIssuer;
use App\Services\AgentArtifactCatalog;
use App\Services\AgentInstallerService;
use App\Services\WindowsInstallerPackageService;
use App\Services\AgentUpdateService;
use App\Services\PublicUrlResolver;
use Closure;
use JsonException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Throwable;

final class AgentController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PublicUrlResolver $urlResolver,
        private readonly AgentCredentialIssuer $credentials,
        private readonly AgentInstallerService $installers,
        private readonly WindowsInstallerPackageService $windowsPackages,
        /** @var Closure(): AgentArtifactCatalog */
        private readonly Closure $artifactCatalog,
        /** @var Closure(): AgentUpdateService */
        private readonly Closure $updateService
    ) {
    }

    /** @param array<string, string> $args */
    public function generateInstallScript(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $credential = $this->exchangeInstallerCredential($request);
        if ($credential === null) {
            return $this->plainError($response, 403, 'Invalid or expired installer token.');
        }

        try {
            $script = $this->installers->linux(
                $this->urlResolver->resolve($request),
                $credential->token
            );
        } catch (Throwable) {
            return $this->plainError($response, 400, 'Invalid public service URL.');
        }

        return $this->download(
            $response,
            $script,
            'application/x-shellscript; charset=UTF-8',
            'mirvmon-install.sh',
            secret: true
        );
    }

    /** @param array<string, string> $args */
    public function generateWindowsInstaller(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $downloadToken = $request->getQueryParams()['token'] ?? null;
        if (!is_string($downloadToken)) {
            return $this->plainError($response, 403, 'Invalid or expired installer token.');
        }

        try {
            $activationToken = $this->credentials->redeemWindowsDownload($downloadToken);
        } catch (Throwable) {
            return $this->plainError($response, 403, 'Invalid or expired installer token.');
        }

        try {
            $package = $this->windowsPackages->build(
                $this->urlResolver->resolve($request),
                $activationToken,
                ($this->artifactCatalog)()
            );
        } catch (Throwable) {
            $this->credentials->revokeInstaller($activationToken);
            return $this->plainError($response, 400, 'Cannot build Windows installer.');
        }

        return $this->download(
            $response,
            $package->contents,
            $package->contentType,
            $package->filename,
            secret: true
        );
    }

    /** @param array<string, string> $args */
    public function downloadBinary(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $key = $args['artifact'] ?? '';
        if (!is_string($key)) {
            return $this->plainError($response, 404, 'Agent artifact not found.');
        }

        try {
            $artifact = ($this->artifactCatalog)()->require($key);
            $stream = fopen($artifact->path, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Native agent artifact cannot be opened.');
            }
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        throw new RuntimeException('Native agent artifact cannot be read.');
                    }
                    $response->getBody()->write($chunk);
                }
            } finally {
                fclose($stream);
            }
        } catch (RuntimeException) {
            return $this->plainError($response, 404, 'Agent artifact not found.');
        }

        return $response
            ->withHeader('Content-Type', $artifact->contentType)
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $artifact->filename . '"'
            )
            ->withHeader('Content-Length', (string) filesize($artifact->path))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** @param array<string, string> $args */
    public function getAgentConfig(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $token = $this->bearerToken($request);
        if ($token === null) {
            return $this->jsonError($response, 401, 'invalid_token');
        }

        $statement = $this->pdo->prepare(
            'SELECT
                agent_tokens.server_id,
                agent_configs.interval_seconds,
                agent_configs.monitor_services,
                agent_configs.enabled
             FROM agent_tokens
             INNER JOIN servers ON servers.id = agent_tokens.server_id
             LEFT JOIN agent_configs
               ON agent_configs.server_id = agent_tokens.server_id
             WHERE agent_tokens.token_hash = :token_hash
               AND servers.is_active = TRUE'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $config = $statement->fetch();
        if (!is_array($config)) {
            return $this->jsonError($response, 401, 'invalid_token');
        }

        $touch = $this->pdo->prepare(
            'UPDATE agent_tokens
             SET last_used_at = CURRENT_TIMESTAMP
             WHERE server_id = :server_id'
        );
        $touch->execute(['server_id' => (int) $config['server_id']]);

        $payload = [
            'enabled' => $config['enabled'] === null
                ? true
                : $this->toBool($config['enabled']),
            'interval_seconds' => $config['interval_seconds'] === null
                ? 60
                : (int) $config['interval_seconds'],
            'monitor_services' => $this->decodeStringList(
                $config['monitor_services'] ?? '[]'
            ),
        ];
        $command = ($this->updateService)()->commandForServer(
            (int) $config['server_id']
        );
        if ($command !== null) {
            $payload['update_command'] = $command;
        }

        return $this->json($response, $payload);
    }

    /** @param array<string, string> $args */
    public function activateInstaller(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $installerToken = $this->bearerToken($request);
        if ($installerToken === null) {
            return $this->jsonError($response, 401, 'invalid_token')
                ->withHeader('Cache-Control', 'no-store');
        }

        try {
            $credential = $this->credentials->exchange($installerToken);
            $baseUrl = $this->urlResolver->resolve($request);
        } catch (Throwable) {
            return $this->jsonError($response, 401, 'invalid_token')
                ->withHeader('Cache-Control', 'no-store');
        }

        return $this->json($response, [
            'api_url' => $baseUrl . '/api/v1/metrics',
            'config_url' => $baseUrl . '/api/v1/agent/config',
            'token' => $credential->token,
            'interval_seconds' => 60,
            'verify_tls' => true,
            'queue_path' => '%PROGRAMDATA%\\MirvMon\\Agent\\queue.json',
            'collect_process_commands' => false,
            'enabled' => true,
            'monitor_services' => [],
            'queue_limit' => 1000,
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $args */
    public function getConfig(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $this->jsonError($response, 400, 'invalid_server_id');
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO agent_configs (server_id)
             VALUES (:server_id)
             ON CONFLICT (server_id) DO NOTHING'
        );
        $insert->execute(['server_id' => $serverId]);
        $statement = $this->pdo->prepare(
            'SELECT interval_seconds, monitor_services, enabled
             FROM agent_configs
             WHERE server_id = :server_id'
        );
        $statement->execute(['server_id' => $serverId]);
        $config = $statement->fetch();
        if (!is_array($config)) {
            return $this->jsonError($response, 404, 'server_not_found');
        }

        return $this->json($response, [
            'interval_seconds' => (int) $config['interval_seconds'],
            'monitor_services' => $this->decodeStringList($config['monitor_services']),
            'enabled' => $this->toBool($config['enabled']),
        ]);
    }

    /** @param array<string, string> $args */
    public function updateConfig(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $this->jsonError($response, 400, 'invalid_server_id');
        }
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->jsonError($response, 422, 'invalid_config');
        }

        $interval = filter_var(
            $body['interval_seconds'] ?? 60,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 10, 'max_range' => 86400]]
        );
        $services = $body['monitor_services'] ?? [];
        if (is_string($services)) {
            try {
                $services = json_decode($services, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $this->jsonError($response, 422, 'invalid_config');
            }
        }
        if (
            $interval === false
            || !is_array($services)
            || !array_is_list($services)
            || count($services) > 500
            || array_filter($services, static fn (mixed $item): bool => !is_string($item)) !== []
        ) {
            return $this->jsonError($response, 422, 'invalid_config');
        }
        $enabled = isset($body['enabled'])
            && filter_var($body['enabled'], FILTER_VALIDATE_BOOL);

        $statement = $this->pdo->prepare(
            'INSERT INTO agent_configs (
                server_id,
                interval_seconds,
                monitor_services,
                enabled
             ) VALUES (
                :server_id,
                :interval_seconds,
                CAST(:monitor_services AS jsonb),
                :enabled
             )
             ON CONFLICT (server_id) DO UPDATE SET
                interval_seconds = EXCLUDED.interval_seconds,
                monitor_services = EXCLUDED.monitor_services,
                enabled = EXCLUDED.enabled'
        );
        $statement->execute([
            'server_id' => $serverId,
            'interval_seconds' => $interval,
            'monitor_services' => json_encode(
                array_values(array_unique($services)),
                JSON_THROW_ON_ERROR
            ),
            'enabled' => $enabled,
        ]);
        $server = $this->pdo->prepare(
            'UPDATE servers
             SET service_check_enabled = :enabled
             WHERE id = :server_id'
        );
        $server->execute(['server_id' => $serverId, 'enabled' => $enabled]);

        return $this->json($response, ['saved' => true]);
    }

    /** @param array<string, string> $args */
    public function getStatus(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $this->jsonError($response, 400, 'invalid_server_id');
        }

        $statement = $this->pdo->prepare(
            'SELECT
                servers.last_metrics_at,
                servers.last_service_check_at,
                COALESCE(agent_configs.enabled, TRUE) AS enabled
             FROM servers
             LEFT JOIN agent_configs ON agent_configs.server_id = servers.id
             WHERE servers.id = :server_id'
        );
        $statement->execute(['server_id' => $serverId]);
        $status = $statement->fetch();
        if (!is_array($status)) {
            return $this->jsonError($response, 404, 'server_not_found');
        }

        return $this->json($response, [
            'status' => $this->toBool($status['enabled']) ? 'active' : 'disabled',
            'last_seen' => $status['last_metrics_at'],
            'last_service_check' => $status['last_service_check_at'],
        ]);
    }

    private function exchangeInstallerCredential(
        Request $request
    ): ?AgentCredential {
        $token = $request->getQueryParams()['token'] ?? null;
        if (!is_string($token)) {
            return null;
        }

        try {
            return $this->credentials->exchange($token);
        } catch (RuntimeException) {
            return null;
        }
    }

    private function bearerToken(Request $request): ?string
    {
        $authorization = $request->getHeaderLine('Authorization');
        if (
            preg_match('/^Bearer ([A-Za-z0-9._~-]{32,512})$/', $authorization, $matches)
            !== 1
        ) {
            return null;
        }

        return $matches[1];
    }

    private function download(
        Response $response,
        string $content,
        string $contentType,
        string $filename,
        bool $secret = false
    ): Response {
        $response->getBody()->write($content);
        $response = $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $filename . '"'
            )
            ->withHeader('X-Content-Type-Options', 'nosniff');
        if ($secret) {
            $response = $response
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }

    private function plainError(
        Response $response,
        int $status,
        string $message
    ): Response {
        $response->getBody()->write($message);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $payload */
    private function json(
        Response $response,
        array $payload,
        int $status = 200
    ): Response {
        $response->getBody()->write(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    private function jsonError(
        Response $response,
        int $status,
        string $code
    ): Response {
        return $this->json($response, ['error' => ['code' => $code]], $status);
    }

    /** @return list<string> */
    private function decodeStringList(mixed $encoded): array
    {
        try {
            $value = is_string($encoded)
                ? json_decode($encoded, true, 512, JSON_THROW_ON_ERROR)
                : $encoded;
        } catch (JsonException) {
            return [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
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
}
