<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Backup\BackupOperationStore;
use App\Controllers\SystemController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\AppSettingsRepository;
use App\Repositories\MetricRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\ServerRepository;
use App\Repositories\WorkerHeartbeatRepository;
use App\Security\SecretCipher;
use App\Services\ServerPlatformService;
use App\Services\ServerStatusService;
use App\Services\SystemHealthService;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class SystemControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AppSettingsRepository $settings;
    private SystemController $controller;
    private string $drRoot;
    private string|false $previousAppKey;
    private string|false $previousAppVersion;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            self::markTestSkipped('Set TEST_DB_* to run the TimescaleDB integration suite.');
        }

        self::$pdo = ConnectionFactory::connect([
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_PORT' => (string) (getenv('TEST_DB_PORT') ?: '5432'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'DB_SSLMODE' => (string) (getenv('TEST_DB_SSLMODE') ?: 'disable'),
        ]);
        (new Migrator(self::$pdo, dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        $this->previousAppKey = getenv('APP_KEY');
        $this->previousAppVersion = getenv('APP_VERSION');
        putenv('APP_KEY=' . base64_encode(str_repeat('k', 32)));
        putenv('APP_VERSION=controller-test');
        $this->drRoot = sys_get_temp_dir() . '/mirvmon-system-controller-dr-' . bin2hex(random_bytes(6));

        self::$pdo?->beginTransaction();
        self::$pdo?->exec('DELETE FROM notification_outbox');
        self::$pdo?->exec('DELETE FROM worker_heartbeats');
        self::$pdo?->prepare(
            "DELETE FROM app_settings WHERE setting_key = 'mirvmon_host_server_id'"
        )->execute();

        $_SESSION = ['username' => 'admin', 'role' => 'admin'];
        $twig = Twig::create(dirname(__DIR__, 3) . '/templates', ['cache' => false]);
        $twig->getEnvironment()->addGlobal('session', $_SESSION);
        $twig->getEnvironment()->addGlobal('csrf', [
            'name_key' => '_csrf_name',
            'name' => 'test',
            'value_key' => '_csrf_value',
            'value' => 'test',
        ]);
        $twig->getEnvironment()->addGlobal('app_version', 'v0.4.16');

        $this->settings = new AppSettingsRepository(self::$pdo);
        $health = new SystemHealthService(
            self::$pdo,
            $this->settings,
            new ServerRepository(self::$pdo),
            new MetricRepository(self::$pdo),
            new WorkerHeartbeatRepository(self::$pdo),
            new NotificationOutboxRepository(self::$pdo),
            new ServerStatusService(new ServerPlatformService()),
            'v0.4.16',
            'test'
        );
        $this->controller = new SystemController(
            self::$pdo,
            $twig,
            $this->settings,
            $health,
            drStateRoot: $this->drRoot
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
        $this->removeTree($this->drRoot);
        $this->restoreEnvironment('APP_KEY', $this->previousAppKey);
        $this->restoreEnvironment('APP_VERSION', $this->previousAppVersion);
    }

    public function testAdminPageRendersDiagnosticsAndHostSelector(): void
    {
        $serverId = $this->server('system-page-host');
        $heartbeats = new WorkerHeartbeatRepository(self::$pdo);
        $heartbeats->record(WorkerHeartbeatRepository::NOTIFICATION_WORKER);
        $heartbeats->record(WorkerHeartbeatRepository::OFFLINE_WORKER);

        $response = $this->controller->index(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://monitor.example/admin/system'),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Система / MirvMon', $html);
        self::assertStringContainsString('system-page-host', $html);
        self::assertStringContainsString('v0.4.16', $html);
        self::assertStringContainsString('/admin/system/host', $html);
        self::assertStringContainsString((string) $serverId, $html);
    }

    public function testAdminCanSelectAndClearMirvMonHost(): void
    {
        $serverId = $this->server('selectable-host');
        $factory = new ServerRequestFactory();
        $responses = new ResponseFactory();

        $saved = $this->controller->saveHost(
            $factory->createServerRequest('POST', 'https://monitor.example/admin/system/host')
                ->withParsedBody(['server_id' => (string) $serverId]),
            $responses->createResponse(),
            []
        );
        self::assertSame(302, $saved->getStatusCode());
        self::assertSame('/admin/system', $saved->getHeaderLine('Location'));
        self::assertSame($serverId, $this->settings->get(SystemHealthService::HOST_SETTING));

        $cleared = $this->controller->saveHost(
            $factory->createServerRequest('POST', 'https://monitor.example/admin/system/host')
                ->withParsedBody(['server_id' => '']),
            $responses->createResponse(),
            []
        );
        self::assertSame(302, $cleared->getStatusCode());
        self::assertNull($this->settings->get(SystemHealthService::HOST_SETTING));
    }

    public function testBackupCreationQueuesAsyncOperation(): void
    {
        $response = $this->controller->createBackup(
            (new ServerRequestFactory())->createServerRequest(
                'POST',
                'https://monitor.example/admin/system/backup/create'
            )->withParsedBody([
                'password' => 'controller-backup-password',
                'password_confirm' => 'controller-backup-password',
            ]),
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertMatchesRegularExpression(
            '#^/admin/system/backup\?backup=([a-f0-9]{32})$#',
            $location
        );
        preg_match('#backup=([a-f0-9]{32})$#', $location, $matches);
        $id = $matches[1] ?? '';
        self::assertNotSame('', $id);

        $operation = $this->backupStore()->operation($id);
        self::assertSame('queued', $operation['status']);
        self::assertArrayNotHasKey('password_handoff', $operation);
    }

    public function testCompletedAsyncBackupCanBeDownloaded(): void
    {
        $store = $this->backupStore();
        $created = $store->begin('download-test-password', 'mirvmon-full-controller-test.mmbak');
        self::assertIsArray($store->claimNext('controller-test-worker'));
        self::assertSame(22, file_put_contents($store->outputPath($created['id']), 'encrypted-backup-bytes'));
        $backupId = '11111111-1111-4111-8111-111111111111';
        $store->markSucceeded($created['id'], ['backup_id' => $backupId]);

        $response = $this->controller->downloadBackup(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'https://monitor.example/admin/system/backup/' . $created['id'] . '/download'
            ),
            (new ResponseFactory())->createResponse(),
            ['id' => $created['id']]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('encrypted-backup-bytes', (string) $response->getBody());
        self::assertSame('22', $response->getHeaderLine('Content-Length'));
        self::assertSame($backupId, $response->getHeaderLine('X-MirvMon-Backup-ID'));
        self::assertStringContainsString(
            'mirvmon-full-controller-test.mmbak',
            $response->getHeaderLine('Content-Disposition')
        );
    }

    public function testNonAdminIsRedirected(): void
    {
        $_SESSION = ['username' => 'viewer', 'role' => 'user'];
        $response = $this->controller->index(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://monitor.example/admin/system'),
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    private function backupStore(): BackupOperationStore
    {
        return new BackupOperationStore(
            $this->drRoot . '/backup-operations',
            new SecretCipher(str_repeat('k', 32))
        );
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
            return;
        }
        putenv($name . '=' . $value);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }

    private function server(string $name): int
    {
        $statement = self::$pdo?->prepare(
            'INSERT INTO servers (name, address, is_active) VALUES (:name, :address, TRUE) RETURNING id'
        );
        $statement?->execute(['name' => $name, 'address' => '127.0.0.1']);

        return (int) $statement?->fetchColumn();
    }
}
