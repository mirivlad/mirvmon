<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\AdminController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\NotificationSettingsRepository;
use App\Repositories\WorkerHeartbeatRepository;
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class AdminNotificationControllerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AdminController $controller;
    private Twig $twig;

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
        self::$pdo?->beginTransaction();
        $_SESSION = ['role' => 'admin'];
        $this->twig = Twig::create(
            dirname(__DIR__, 3) . '/templates',
            ['cache' => false]
        );
        $this->twig->getEnvironment()->addGlobal('session', []);
        $settings = new NotificationSettingsRepository(
            self::$pdo,
            new SecretCipher(str_repeat('a', 32))
        );
        $this->controller = new AdminController(
            self::$pdo,
            $this->twig,
            $settings,
            new NotificationOutboxRepository(self::$pdo),
            new WorkerHeartbeatRepository(self::$pdo)
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testTestActionSavesSecretsAndOnlyQueuesDelivery(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'POST',
                'https://monitor.example/admin/notifications/test'
            )
            ->withParsedBody($this->settings());

        $response = $this->controller->testNotification(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/notifications', $response->getHeaderLine('Location'));
        self::assertStringContainsString(
            'Тест поставлен в очередь',
            (string) $_SESSION['flash_message']
        );
        self::assertSame(
            ['pending', 'pending'],
            self::$pdo?->query(
                "SELECT status
                 FROM notification_outbox
                 WHERE event_type = 'test'
                 ORDER BY channel"
            )->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function testSettingsPageNeverContainsStoredSecrets(): void
    {
        $repository = new NotificationSettingsRepository(
            self::$pdo,
            new SecretCipher(str_repeat('a', 32))
        );
        $repository->save($this->settings());

        $response = $this->controller->notificationSettings(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'https://monitor.example/admin/notifications'
            ),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $response->getBody();

        self::assertStringNotContainsString('smtp-secret', $html);
        self::assertStringNotContainsString('123:bot-secret', $html);
        self::assertStringNotContainsString('proxy-secret', $html);
        self::assertStringContainsString('SOCKS5H', $html);
        self::assertStringContainsString('сохранён', $html);
    }

    public function testSettingsPageShowsWhyAQueuedNotificationWasNotDelivered(): void
    {
        $repository = new NotificationSettingsRepository(
            self::$pdo,
            new SecretCipher(str_repeat('a', 32))
        );
        $repository->save($this->settings());
        $outbox = new NotificationOutboxRepository(self::$pdo);
        $outbox->enqueueTest([
            'type' => 'test',
            'event' => 'test',
            'event_time' => '2026-07-31T00:00:00+00:00',
        ]);
        foreach ($outbox->claim() as $job) {
            $outbox->markFailed($job['id'], $job['attempts'], 'telegram_http_401: Unauthorized');
        }

        $response = $this->controller->notificationSettings(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'https://monitor.example/admin/notifications'
            ),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $response->getBody();

        self::assertStringContainsString('Очередь уведомлений', $html);
        self::assertStringContainsString('telegram_http_401: Unauthorized', $html);
        self::assertStringContainsString('/admin/notifications/queue/retry', $html);
    }

    public function testQueueRetryGivesUndeliveredJobsAFreshBudget(): void
    {
        $repository = new NotificationSettingsRepository(
            self::$pdo,
            new SecretCipher(str_repeat('a', 32))
        );
        $repository->save($this->settings());
        $outbox = new NotificationOutboxRepository(self::$pdo);
        $outbox->enqueueTest([
            'type' => 'test',
            'event' => 'test',
            'event_time' => '2026-07-31T00:00:00+00:00',
        ]);
        foreach ($outbox->claim() as $job) {
            $outbox->markFailed($job['id'], 10, 'telegram_http_401: Unauthorized');
        }
        self::assertSame(['dead' => 2], $outbox->statusCounts());

        $response = $this->controller->retryNotificationQueue(
            (new ServerRequestFactory())->createServerRequest(
                'POST',
                'https://monitor.example/admin/notifications/queue/retry'
            ),
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/notifications', $response->getHeaderLine('Location'));
        self::assertSame(['pending' => 2], $outbox->statusCounts());
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return [
            'email_enabled' => 'on',
            'smtp_host' => 'smtp.example.net',
            'smtp_port' => '587',
            'smtp_username' => 'monitor@example.net',
            'smtp_password' => 'smtp-secret',
            'smtp_encryption' => 'tls',
            'smtp_from_email' => 'monitor@example.net',
            'smtp_recipient_email' => 'ops@example.net',
            'telegram_enabled' => 'on',
            'telegram_bot_token' => '123:bot-secret',
            'telegram_chat_id' => '-100123',
            'telegram_proxy_type' => 'socks5h',
            'telegram_proxy_host' => 'proxy.internal',
            'telegram_proxy_port' => '1080',
            'telegram_proxy_username' => 'proxy-user',
            'telegram_proxy_password' => 'proxy-secret',
            'notify_on_warning' => 'on',
            'notify_on_critical' => 'on',
        ];
    }
}
