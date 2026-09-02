<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Backup\BackupContainer;
use App\Backup\BackupManifest;
use App\Backup\BackupOperationStore;
use App\Backup\BackupPreflight;
use App\Backup\BackupSecretCatalog;
use App\Backup\PostgresBackupTool;
use App\Backup\RestoreOperationStore;
use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\AppSettingsRepository;
use App\Repositories\AuditLogRepository;
use App\Security\SecretCipher;
use App\Services\AuditLogger;
use App\Services\SystemHealthService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Views\Twig;
use Throwable;

final class SystemController
{
    private readonly AuditLogger $audit;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly AppSettingsRepository $settings,
        private readonly SystemHealthService $health,
        private readonly Translator $translator = new Translator(),
        ?AuditLogger $audit = null,
        private readonly ?string $drStateRoot = null
    ) {
        $this->audit = $audit ?? new AuditLogger(new AuditLogRepository($pdo));
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }

        $servers = $this->pdo->query(
            'SELECT id, name, address, is_active FROM servers ORDER BY name, id'
        )?->fetchAll() ?: [];
        foreach ($servers as &$server) {
            $server['id'] = (int) $server['id'];
            $server['is_active'] = $this->toBool($server['is_active'] ?? false);
        }
        unset($server);

        return $this->twig->render($response, 'admin/system.twig', [
            'title' => $this->translator->trans('system.title'),
            'system' => $this->health->details(),
            'servers' => $servers,
            'selected_host_id' => $this->health->selectedHostId(),
        ]);
    }

    /** @param array<string, string> $args */
    public function backup(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }

        $restoreOperation = null;
        $backupOperation = null;
        $query = $request->getQueryParams();
        $restoreId = $query['restore'] ?? null;
        if (is_string($restoreId) && preg_match('/^[a-f0-9]{32}$/', $restoreId) === 1) {
            try {
                $restoreOperation = $this->restoreStore()->operation($restoreId);
            } catch (Throwable) {
                $restoreOperation = null;
            }
        }
        $backupId = $query['backup'] ?? null;
        if (is_string($backupId) && preg_match('/^[a-f0-9]{32}$/', $backupId) === 1) {
            try {
                $backupOperation = $this->backupStore()->operation($backupId);
            } catch (Throwable) {
                $backupOperation = null;
            }
        }

        return $this->twig->render($response, 'admin/backup.twig', [
            'title' => $this->translator->trans('backup.title'),
            'restore_operation' => $restoreOperation,
            'backup_operation' => $backupOperation,
            'restore_max_bytes' => $this->restoreMaximumBytes(),
        ]);
    }

    /** @param array<string, string> $args */
    public function createBackup(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $body = $request->getParsedBody();
        $password = is_array($body) && is_string($body['password'] ?? null)
            ? $body['password']
            : '';
        $confirmation = is_array($body) && is_string($body['password_confirm'] ?? null)
            ? $body['password_confirm']
            : '';
        if ($password !== $confirmation || strlen($password) < 8 || strlen($password) > 1024) {
            if ($password !== '') {
                sodium_memzero($password);
            }
            if ($confirmation !== '') {
                sodium_memzero($confirmation);
            }
            $this->flash('backup.create.password_invalid', 'error');
            return $this->redirect($response, '/admin/system/backup');
        }

        try {
            $filename = sprintf(
                'mirvmon-full-%s-%s.mmbak',
                $this->safeVersion($this->applicationVersion()),
                gmdate('Ymd-His')
            );
            $operation = $this->backupStore()->begin($password, $filename);
            $id = $operation['id'] ?? null;
            if (!is_string($id)) {
                throw new RuntimeException('Backup operation identifier is missing.');
            }
            $this->flash('backup.create.queued', 'success');
            return $this->redirect($response, '/admin/system/backup?backup=' . rawurlencode($id));
        } catch (Throwable $exception) {
            error_log('[mirvmon][backup][queue] ' . $exception->getMessage());
            $this->flash('backup.create.failed', 'error');
            return $this->redirect($response, '/admin/system/backup');
        } finally {
            sodium_memzero($password);
            sodium_memzero($confirmation);
        }
    }

    /** @param array<string, string> $args */
    public function downloadBackup(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $id = $args['id'] ?? '';
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            return $this->redirect($response, '/admin/system/backup');
        }

        try {
            $download = $this->backupStore()->download($id);
            $path = $download['path'] ?? null;
            $filename = $download['filename'] ?? null;
            $size = $download['size'] ?? null;
            if (!is_string($path) || !is_string($filename) || !is_int($size)) {
                throw new RuntimeException('Backup download state is incomplete.');
            }
            $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
            if (!is_string($safeFilename) || $safeFilename === '') {
                throw new RuntimeException('Backup download filename is invalid.');
            }
            $stream = (new StreamFactory())->createStreamFromFile($path, 'rb');
            $response = $response->withBody($stream)
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $safeFilename . '"')
                ->withHeader('Content-Length', (string) $size)
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff');
            $manifest = $download['manifest'] ?? null;
            if (is_array($manifest) && is_string($manifest['backup_id'] ?? null)) {
                $response = $response->withHeader('X-MirvMon-Backup-ID', $manifest['backup_id']);
            }
            return $response;
        } catch (Throwable $exception) {
            error_log('[mirvmon][backup][download] ' . $exception->getMessage());
            $this->flash('backup.create.download_unavailable', 'error');
            return $this->redirect($response, '/admin/system/backup?backup=' . rawurlencode($id));
        }
    }

    /** @param array<string, string> $args */
    public function beginRestoreUpload(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }
        $body = $request->getParsedBody();
        $filename = is_array($body) && is_string($body['filename'] ?? null)
            ? $body['filename']
            : '';
        $total = is_array($body)
            ? filter_var($body['total_bytes'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => $this->restoreMaximumBytes()],
            ])
            : false;
        if ($total === false) {
            return $this->json($response, ['error' => 'invalid_upload'], 422);
        }

        try {
            return $this->json($response, $this->restoreStore()->begin($filename, $total));
        } catch (Throwable $exception) {
            error_log('[mirvmon][restore][upload-start] ' . $exception->getMessage());
            return $this->json($response, ['error' => 'invalid_upload'], 422);
        }
    }

    /** @param array<string, string> $args */
    public function appendRestoreChunk(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }
        $body = $request->getParsedBody();
        $id = is_array($body) && is_string($body['upload_id'] ?? null)
            ? $body['upload_id']
            : '';
        $index = is_array($body)
            ? filter_var($body['index'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
            : false;
        $uploaded = $request->getUploadedFiles()['chunk'] ?? null;
        if ($index === false || $uploaded === null || $uploaded->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => 'invalid_chunk'], 422);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'mirvmon-dr-chunk-');
        if ($temporary === false) {
            return $this->json($response, ['error' => 'upload_failed'], 500);
        }
        @chmod($temporary, 0600);
        try {
            $uploaded->moveTo($temporary);
            $result = $this->restoreStore()->append($id, $index, $temporary);
            return $this->json($response, $result);
        } catch (Throwable $exception) {
            error_log('[mirvmon][restore][upload-chunk] ' . $exception->getMessage());
            return $this->json($response, ['error' => 'invalid_chunk'], 422);
        } finally {
            @unlink($temporary);
        }
    }

    /** @param array<string, string> $args */
    public function preflightRestore(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }
        $body = $request->getParsedBody();
        $id = is_array($body) && is_string($body['upload_id'] ?? null)
            ? $body['upload_id']
            : '';
        $password = is_array($body) && is_string($body['password'] ?? null)
            ? $body['password']
            : '';
        if (strlen($password) < 8 || strlen($password) > 1024) {
            return $this->json($response, ['error' => 'preflight_failed'], 422);
        }

        @set_time_limit(0);
        try {
            $store = $this->restoreStore();
            $upload = $store->uploaded($id);
            $backupPath = $upload['backup_path'] ?? null;
            if (!is_string($backupPath)) {
                throw new RuntimeException('Restore upload path is missing.');
            }
            $key = $this->applicationKey();
            $database = $this->databaseEnvironment();
            $preflight = new BackupPreflight(
                $this->pdo,
                new BackupContainer(),
                new BackupManifest(
                    $this->pdo,
                    dirname(__DIR__, 2) . '/migrations',
                    $this->applicationVersion()
                ),
                new BackupSecretCatalog($this->pdo, new SecretCipher($key)),
                new PostgresBackupTool($database)
            );
            $result = $preflight->run($backupPath, $password, $store->workspacePath($id));
            $store->markReady($id, $result['manifest'], $result['warnings'], $result['workspace']);

            return $this->json($response, [
                'ok' => true,
                'redirect' => '/admin/system/backup?restore=' . $id,
            ]);
        } catch (Throwable $exception) {
            error_log('[mirvmon][restore][preflight] ' . $exception->getMessage());
            return $this->json($response, ['error' => 'preflight_failed'], 422);
        }
    }

    /** @param array<string, string> $args */
    public function executeRestore(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }
        $body = $request->getParsedBody();
        $id = is_array($body) && is_string($body['operation_id'] ?? null)
            ? $body['operation_id']
            : '';
        $confirmed = is_array($body) && ($body['confirm_restore'] ?? null) === '1';
        if (!$confirmed) {
            $this->flash('backup.restore.confirm_required', 'error');
            return $this->redirect($response, '/admin/system/backup?restore=' . rawurlencode($id));
        }

        try {
            $store = $this->restoreStore();
            $store->ready($id);
            $store->queue($id);
            $this->flash('backup.restore.queued', 'success');
            return $this->redirect($response, '/admin/system/backup?restore=' . rawurlencode($id));
        } catch (Throwable $exception) {
            error_log('[mirvmon][restore][queue] ' . $exception->getMessage());
            $this->flash('backup.restore.failed', 'error');
            return $this->redirect($response, '/admin/system/backup');
        }
    }

    /** @param array<string, string> $args */
    public function saveHost(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }

        $before = $this->health->selectedHostId();
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['server_id'] ?? null) : null;
        if ($value === null || $value === '') {
            try {
                $this->settings->set(SystemHealthService::HOST_SETTING, null);
                if ($before !== null) {
                    $this->recordAudit(
                        'system.host.clear',
                        null,
                        null,
                        $this->translator->trans('audit.event.system.host_cleared'),
                        ['server_id' => null]
                    );
                }
                $this->flash('system.host.cleared', 'success');
            } catch (Throwable) {
                $this->flash('system.host.save_failed', 'error');
            }
            return $this->redirect($response, '/admin/system');
        }

        $serverId = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($serverId === false) {
            $this->flash('system.host.invalid', 'error');
            return $this->redirect($response, '/admin/system');
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT name, is_active FROM servers WHERE id = :id'
            );
            $statement->execute(['id' => $serverId]);
            $server = $statement->fetch();
            if (!is_array($server)) {
                $this->flash('system.host.not_found', 'error');
                return $this->redirect($response, '/admin/system');
            }
            if (!$this->toBool($server['is_active'] ?? false)) {
                $this->flash('system.host.inactive', 'error');
                return $this->redirect($response, '/admin/system');
            }

            $this->settings->set(SystemHealthService::HOST_SETTING, $serverId);
            if ($before !== (int) $serverId) {
                $this->recordAudit(
                    'system.host.save',
                    (int) $serverId,
                    (string) $server['name'],
                    $this->translator->trans('audit.event.system.host_saved'),
                    ['server_id' => (int) $serverId]
                );
            }
            $this->flash('system.host.saved', 'success');
        } catch (Throwable) {
            $this->flash('system.host.save_failed', 'error');
        }

        return $this->redirect($response, '/admin/system');
    }

    /** @param array<string, mixed> $metadata */
    private function recordAudit(
        string $action,
        ?int $objectId,
        ?string $objectLabel,
        string $description,
        array $metadata
    ): void {
        try {
            $this->audit->record(
                $action,
                'system',
                $objectId,
                $objectLabel,
                $description,
                $metadata
            );
        } catch (Throwable $exception) {
            error_log('[mirvmon][audit][system-host] ' . $exception->getMessage());
        }
    }

    private function backupStore(): BackupOperationStore
    {
        return new BackupOperationStore(
            $this->drRoot() . '/backup-operations',
            new SecretCipher($this->applicationKey())
        );
    }

    private function restoreStore(): RestoreOperationStore
    {
        return new RestoreOperationStore(
            $this->drRoot() . '/operations',
            $this->restoreMaximumBytes()
        );
    }

    private function drRoot(): string
    {
        $root = $this->drStateRoot ?? dirname(__DIR__, 2) . '/var/dr';
        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function restoreMaximumBytes(): int
    {
        $raw = getenv('BACKUP_MAX_UPLOAD_BYTES');
        if ($raw === false || $raw === '') {
            return 8589934592;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1048576, 'max_range' => PHP_INT_MAX],
        ]);
        return $value === false ? 8589934592 : $value;
    }

    /** @return array<string, string> */
    private function databaseEnvironment(): array
    {
        $result = [];
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SSLMODE'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $result[$name] = $value;
            }
        }
        return $result;
    }

    private function applicationKey(): string
    {
        $key = base64_decode((string) getenv('APP_KEY'), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('APP_KEY must be a base64-encoded 32-byte key.');
        }
        return $key;
    }

    private function applicationVersion(): string
    {
        $version = trim((string) (getenv('APP_VERSION') ?: 'development'));
        return $version === '' ? 'development' : $version;
    }

    private function safeVersion(string $version): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $version);
        return is_string($safe) && $safe !== '' ? $safe : 'unknown';
    }

    /** @param array<string, mixed> $payload */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = '{"error":"encoding_failed"}';
            $status = 500;
        }
        $response->getBody()->write($encoded);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function isAdmin(): bool
    {
        return ($_SESSION['role'] ?? null) === 'admin';
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    private function flash(string $key, string $type): void
    {
        $_SESSION['flash_message'] = $this->translator->trans($key);
        $_SESSION['flash_type'] = $type;
    }

    private function toBool(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array($value, ['1', 't', 'true'], true);
    }
}
