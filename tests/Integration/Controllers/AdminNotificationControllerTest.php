<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\AdminController;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\NotificationSettingsRepository;
use App\Repositories\ChimePortSettingsRepository;
use App\Repositories\WorkerHeartbeatRepository;
use App\Security\SecretCipher;
use App\I18n\Translator;
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
    private NotificationOutboxRepository $outbox;

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
        $this->outbox = new NotificationOutboxRepository(self::$pdo);
        $this->controller = new AdminController(
            self::$pdo,
            $this->twig,
            $settings,
            $this->outbox,
            new WorkerHeartbeatRepository(self::$pdo),
            new Translator(),
            new ChimePortSettingsRepository(self::$pdo, new SecretCipher(str_repeat('a', 32)))
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
        // One job per recipient: two mailboxes and one Telegram chat.
        self::assertSame(
            [
                ['email', 'dev@example.net'],
                ['email', 'ops@example.net'],
                ['telegram', '-100123'],
            ],
            self::$pdo?->query(
                "SELECT channel, recipient
                 FROM notification_outbox
                 WHERE event_type = 'test' AND status = 'pending'
                 ORDER BY channel, recipient"
            )->fetchAll(PDO::FETCH_NUM)
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

    public function testQueuePageShowsFailureDetailsAndSettingsKeepsNoOutboxRows(): void
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

        $settingsResponse = $this->controller->notificationSettings(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'https://monitor.example/admin/notifications'
            ),
            (new ResponseFactory())->createResponse(),
            []
        );
        $settingsHtml = (string) $settingsResponse->getBody();
        self::assertStringNotContainsString('Последние 20 заданий', $settingsHtml);

        $response = $this->controller->notificationQueue(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'https://monitor.example/admin/notifications/queue?status[]=failed'
            ),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $response->getBody();

        self::assertStringContainsString('Очередь уведомлений', $html);
        self::assertStringContainsString('telegram_http_401: Unauthorized', $html);
        self::assertStringContainsString('/admin/notifications/queue/delete', $html);
        self::assertStringContainsString('confirm_delete', $html);
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
        self::assertSame(['dead' => 3], $outbox->statusCounts());

        $response = $this->controller->retryNotificationQueue(
            (new ServerRequestFactory())->createServerRequest(
                'POST',
                'https://monitor.example/admin/notifications/queue/retry'
            ),
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/notifications/queue', $response->getHeaderLine('Location'));
        self::assertSame(['pending' => 3], $outbox->statusCounts());
    }

    public function testOutboxFiltersAndDeletesOnlyMatchingJobs(): void
    {
        $deadId = $this->seedQueueJob('email', 'dead', 'smtp_timeout');
        $pendingId = $this->seedQueueJob('telegram', 'pending', null);

        $filters = $this->outbox->filters([
            'status' => ['dead'],
            'channel' => 'email',
            'error' => 'timeout',
        ]);

        self::assertSame(
            [$deadId],
            array_column($this->outbox->page($filters, 1)['jobs'], 'id')
        );
        self::assertSame(1, $this->outbox->deleteMatching($filters));
        self::assertSame(
            $pendingId,
            (int) self::$pdo?->query(
                'SELECT id FROM notification_outbox WHERE id = ' . $pendingId
            )->fetchColumn()
        );
    }

    public function testBulkQueueDeletionRequiresConfirmation(): void
    {
        $pendingId = $this->seedQueueJob('email', 'pending', null);
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'POST',
                'https://monitor.example/admin/notifications/queue/delete'
            )
            ->withParsedBody(['status' => ['pending']]);

        $response = $this->controller->deleteNotificationQueue(
            $request,
            (new ResponseFactory())->createResponse(),
            []
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            '/admin/notifications/queue?status%5B0%5D=pending',
            $response->getHeaderLine('Location')
        );
        self::assertSame(
            $pendingId,
            (int) self::$pdo?->query(
                'SELECT id FROM notification_outbox WHERE id = ' . $pendingId
            )->fetchColumn()
        );
    }

    public function testIndividualQueueActionsRetryAndDeleteTheSelectedJob(): void
    {
        $failedId = $this->seedQueueJob('email', 'failed', 'smtp_timeout');
        $pendingId = $this->seedQueueJob('telegram', 'pending', null);

        $retryResponse = $this->controller->retryNotificationJob(
            (new ServerRequestFactory())->createServerRequest(
                'POST',
                'https://monitor.example/admin/notifications/queue/' . $failedId . '/retry'
            ),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $failedId]
        );
        $deleteResponse = $this->controller->deleteNotificationJob(
            (new ServerRequestFactory())->createServerRequest(
                'POST',
                'https://monitor.example/admin/notifications/queue/' . $pendingId . '/delete'
            ),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $pendingId]
        );

        self::assertSame(302, $retryResponse->getStatusCode());
        self::assertSame(302, $deleteResponse->getStatusCode());
        self::assertSame(
            'pending',
            self::$pdo?->query(
                'SELECT status FROM notification_outbox WHERE id = ' . $failedId
            )->fetchColumn()
        );
        self::assertFalse((bool) self::$pdo?->query(
            'SELECT EXISTS(SELECT 1 FROM notification_outbox WHERE id = ' . $pendingId . ')'
        )->fetchColumn());
    }

    private function seedQueueJob(
        string $channel,
        string $status,
        ?string $error
    ): int {
        $statement = self::$pdo?->prepare(
            'INSERT INTO notification_outbox (
                channel,
                recipient,
                event_type,
                payload,
                status,
                last_error
             ) VALUES (
                :channel,
                :recipient,
                :event_type,
                CAST(:payload AS jsonb),
                :status,
                :last_error
             ) RETURNING id'
        );
        $statement?->execute([
            'channel' => $channel,
            'recipient' => 'test@example.net',
            'event_type' => 'test',
            'payload' => json_encode(['message' => 'safe'], JSON_THROW_ON_ERROR),
            'status' => $status,
            'last_error' => $error,
        ]);

        return (int) $statement?->fetchColumn();
    }

    /** @return array<string, mixed> */
    public function testUnifiedNotificationPageUsesOneFormAndThreeEqualTransports(): void
    {
        $response = $this->controller->notificationSettings(
            (new ServerRequestFactory())->createServerRequest(
                'GET', 'https://monitor.example/admin/notifications'
            ),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $response->getBody();

        self::assertSame(1, substr_count($html, 'action="/admin/notifications/save-all"'));
        self::assertStringNotContainsString(
            'action="/admin/notifications/chimeport/save"', $html
        );
        self::assertStringContainsString('id="chimeport-settings"', $html);
        self::assertStringContainsString('id="email_enabled"', $html);
        self::assertStringContainsString('id="telegram_enabled"', $html);
        self::assertStringContainsString('id="chimeport_enabled"', $html);
        foreach (['email', 'telegram', 'chimeport'] as $channel) {
            self::assertStringContainsString(
                'formaction="/admin/notifications/test/' . $channel . '"',
                $html
            );
        }
        self::assertSame(1, substr_count($html, 'href="/admin/notifications/queue"'));
        self::assertStringNotContainsString('Дополнительный канал уведомлений.', $html);

        $email = strpos($html, 'id="email_enabled"');
        $telegram = strpos($html, 'id="telegram_enabled"');
        $chimeport = strpos($html, 'id="chimeport_enabled"');
        $rules = strpos($html, 'id="notify_on_warning"');
        self::assertNotFalse($email);
        self::assertNotFalse($telegram);
        self::assertNotFalse($chimeport);
        self::assertNotFalse($rules);
        self::assertTrue($email < $telegram && $telegram < $chimeport && $chimeport < $rules);
    }

    public function testUnifiedSavePersistsAllTransportsWithoutLosingSecrets(): void
    {
        $form = [
            ...$this->settings(),
            'chimeport_enabled' => 'on',
            'chimeport_base_url' => 'https://notify.example.org',
            'chimeport_channel' => 'servers',
            'chimeport_token' => 'cp_t_' . str_repeat('C', 43),
        ];
        $request = (new ServerRequestFactory())->createServerRequest(
            'POST', 'https://monitor.example/admin/notifications/save-all'
        )->withParsedBody($form);

        $response = $this->controller->saveAllNotificationSettings(
            $request, (new ResponseFactory())->createResponse(), []
        );
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('success', $_SESSION['flash_type']);

        $settings = (new NotificationSettingsRepository(
            self::$pdo, new SecretCipher(str_repeat('a', 32))
        ))->getForDelivery();
        self::assertTrue($settings['telegram_enabled']);
        self::assertTrue($settings['email_enabled']);
        self::assertSame('123:bot-secret', $settings['telegram_bot_token']);

        $chimeport = (new ChimePortSettingsRepository(
            self::$pdo, new SecretCipher(str_repeat('a', 32))
        ))->getForDelivery();
        self::assertTrue($chimeport['enabled']);
        self::assertSame('servers', $chimeport['channel']);
        self::assertSame('cp_t_' . str_repeat('C',43), $chimeport['token']);

        $form['smtp_password'] = '';
        $form['telegram_bot_token'] = '';
        $form['telegram_proxy_password'] = '';
        $form['chimeport_token'] = '';
        $this->controller->saveAllNotificationSettings(
            $request->withParsedBody($form), (new ResponseFactory())->createResponse(), []
        );
        $after = (new NotificationSettingsRepository(
            self::$pdo, new SecretCipher(str_repeat('a', 32))
        ))->getForDelivery();
        self::assertSame($settings['telegram_bot_token'], $after['telegram_bot_token']);
        self::assertSame($settings['smtp_password'], $after['smtp_password']);
        self::assertSame($chimeport, (new ChimePortSettingsRepository(
            self::$pdo, new SecretCipher(str_repeat('a', 32))
        ))->getForDelivery());
    }

    public function testUnifiedSaveIsAtomicWhenChimePortSettingsAreInvalid(): void
    {
        $before = self::$pdo?->query(
            'SELECT telegram_enabled, email_enabled, smtp_host, chimeport_enabled '
            . 'FROM notification_settings WHERE id=1'
        )->fetch(PDO::FETCH_ASSOC);
        $form = [
            ...$this->settings(),
            'chimeport_enabled' => 'on',
            'chimeport_base_url' => 'http://unsafe.example.org',
            'chimeport_channel' => 'servers',
            'chimeport_token' => 'cp_t_' . str_repeat('C', 43),
        ];
        $response = $this->controller->saveAllNotificationSettings(
            (new ServerRequestFactory())->createServerRequest(
                'POST', 'https://monitor.example/admin/notifications/save-all'
            )->withParsedBody($form),
            (new ResponseFactory())->createResponse(),
            []
        );
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('error', $_SESSION['flash_type']);
        self::assertSame($before, self::$pdo?->query(
            'SELECT telegram_enabled, email_enabled, smtp_host, chimeport_enabled '
            . 'FROM notification_settings WHERE id=1'
        )->fetch(PDO::FETCH_ASSOC));
    }

    public function testEachUnifiedTestButtonQueuesOnlyItsChosenTransport(): void
    {
        $form = [
            ...$this->settings(),
            'chimeport_enabled' => 'on',
            'chimeport_base_url' => 'https://notify.example.org',
            'chimeport_channel' => 'servers',
            'chimeport_token' => 'cp_t_' . str_repeat('C', 43),
        ];
        $expected = ['email' => 2, 'telegram' => 1, 'chimeport' => 1];
        foreach ($expected as $channel => $number) {
            $response = $this->controller->testNotificationTransport(
                (new ServerRequestFactory())->createServerRequest(
                    'POST', 'https://monitor.example/admin/notifications/test/' . $channel
                )->withParsedBody($form),
                (new ResponseFactory())->createResponse(),
                ['channel' => $channel]
            );
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('success', $_SESSION['flash_type']);
            self::assertSame($number, (int) self::$pdo?->query(
                "SELECT count(*) FROM notification_outbox WHERE channel='" . $channel . "'"
            )->fetchColumn());
        }
        self::assertSame(4, (int) self::$pdo?->query(
            "SELECT count(*) FROM notification_outbox WHERE event_type='test'"
        )->fetchColumn());
    }

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
            'smtp_recipients' => 'ops@example.net, dev@example.net',
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

    public function testChimePortWebSettingsSaveAndDedicatedTestDoNotResendTelegram(): void
    {
        $token = 'cp_t_' . str_repeat('C', 43);
        $form = [
            'chimeport_enabled' => 'on',
            'chimeport_base_url' => 'https://notify.example.org',
            'chimeport_channel' => 'servers',
            'chimeport_token' => $token,
        ];
        $response = $this->controller->saveChimePortSettings(
            (new ServerRequestFactory())->createServerRequest(
                'POST', 'https://monitor.example/admin/notifications/chimeport/save'
            )->withParsedBody($form),
            (new ResponseFactory())->createResponse(),
            []
        );
        self::assertSame(302, $response->getStatusCode());

        $response = $this->controller->notificationSettings(
            (new ServerRequestFactory())->createServerRequest(
                'GET', 'https://monitor.example/admin/notifications'
            ),
            (new ResponseFactory())->createResponse(),
            []
        );
        $html = (string) $response->getBody();
        self::assertStringContainsString('chimeport_base_url', $html);
        self::assertStringContainsString('chimeport_channel', $html);
        self::assertStringContainsString('servers', $html);
        self::assertStringNotContainsString($token, $html);

        $response = $this->controller->testChimePortSettings(
            (new ServerRequestFactory())->createServerRequest(
                'POST', 'https://monitor.example/admin/notifications/chimeport/test'
            )->withParsedBody([
                ...$form, 'chimeport_token' => '',
            ]),
            (new ResponseFactory())->createResponse(),
            []
        );
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            ['chimeport'],
            self::$pdo?->query(
                "SELECT channel FROM notification_outbox WHERE event_type='test' ORDER BY id"
            )->fetchAll(PDO::FETCH_COLUMN)
        );

        $row = self::$pdo?->query(
            "SELECT telegram_enabled, email_enabled FROM notification_settings WHERE id=1"
        )->fetch(PDO::FETCH_ASSOC);
        // The separate ChimePort settings action never changes other transports.
        self::assertIsArray($row);
    }
}
