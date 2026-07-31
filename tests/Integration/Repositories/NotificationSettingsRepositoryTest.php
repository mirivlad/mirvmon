<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\NotificationSettingsRepository;
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;

final class NotificationSettingsRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private NotificationSettingsRepository $repository;

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
        $this->repository = new NotificationSettingsRepository(
            self::$pdo,
            new SecretCipher(str_repeat('n', 32))
        );
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testSecretsAreEncryptedAndNeverReturnedToTheDashboard(): void
    {
        $this->repository->save($this->validSettings());

        $stored = self::$pdo?->query(
            "SELECT
                encode(smtp_password_encrypted, 'escape'),
                encode(telegram_bot_token_encrypted, 'escape'),
                encode(telegram_proxy_password_encrypted, 'escape')
             FROM notification_settings
             WHERE id = 1"
        )->fetch(PDO::FETCH_NUM);
        self::assertNotFalse($stored);
        self::assertStringNotContainsString('smtp-secret', (string) $stored[0]);
        self::assertStringNotContainsString('123:bot-secret', (string) $stored[1]);
        self::assertStringNotContainsString('proxy-secret', (string) $stored[2]);

        $public = $this->repository->getPublic();
        self::assertTrue($public['has_smtp_password']);
        self::assertTrue($public['has_telegram_bot_token']);
        self::assertTrue($public['has_telegram_proxy_password']);
        self::assertArrayNotHasKey('smtp_password', $public);
        self::assertArrayNotHasKey('telegram_bot_token', $public);
        self::assertArrayNotHasKey('telegram_proxy_password', $public);

        $delivery = $this->repository->getForDelivery();
        self::assertSame('smtp-secret', $delivery['smtp_password']);
        self::assertSame('123:bot-secret', $delivery['telegram_bot_token']);
        self::assertSame('proxy-secret', $delivery['telegram_proxy_password']);
    }

    public function testBlankSecretFieldsPreserveExistingValues(): void
    {
        $this->repository->save($this->validSettings());
        $update = $this->validSettings();
        $update['smtp_password'] = '';
        $update['telegram_bot_token'] = '';
        $update['telegram_proxy_password'] = '';
        $update['telegram_chat_id'] = '99';

        $this->repository->save($update);

        $delivery = $this->repository->getForDelivery();
        self::assertSame('smtp-secret', $delivery['smtp_password']);
        self::assertSame('123:bot-secret', $delivery['telegram_bot_token']);
        self::assertSame('proxy-secret', $delivery['telegram_proxy_password']);
        self::assertSame('99', $delivery['telegram_chat_id']);
    }

    public function testUncheckedSwitchesArePersistedAsFalse(): void
    {
        $this->repository->save($this->validSettings());

        // Unchecked checkboxes are absent from the submitted form body.
        $this->repository->save([
            'telegram_enabled' => 'on',
            'telegram_chat_id' => '-100123',
            'notify_on_critical' => 'on',
        ]);

        $settings = $this->repository->getPublic();
        self::assertFalse($settings['email_enabled']);
        self::assertTrue($settings['telegram_enabled']);
        self::assertFalse($settings['notify_on_warning']);
        self::assertTrue($settings['notify_on_critical']);
    }

    public function testTestNotificationIsQueuedForEveryEnabledChannel(): void
    {
        $this->repository->save($this->validSettings());
        $outbox = new NotificationOutboxRepository(self::$pdo);

        self::assertSame(2, $outbox->enqueueTest([
            'type' => 'test',
            'event' => 'test',
            'event_time' => '2026-07-30T00:00:00+00:00',
        ]));
        self::assertSame(
            ['email', 'telegram'],
            self::$pdo?->query(
                "SELECT channel
                 FROM notification_outbox
                 WHERE event_type = 'test'
                 ORDER BY channel"
            )->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function testQueueListingExposesFailuresAndRetryRestoresTheBudget(): void
    {
        $this->repository->save($this->validSettings());
        $outbox = new NotificationOutboxRepository(self::$pdo);
        $outbox->enqueueTest([
            'type' => 'test',
            'event' => 'test',
            'event_time' => '2026-07-30T00:00:00+00:00',
        ]);

        $claimed = $outbox->claim();
        self::assertCount(2, $claimed);
        $outbox->markFailed($claimed[0]['id'], 10, 'telegram_http_401: Unauthorized');
        $outbox->markSent($claimed[1]['id']);

        $queue = $outbox->recent();
        self::assertCount(2, $queue);
        $statuses = array_map(
            static fn (array $job): string => (string) $job['status'],
            $queue
        );
        sort($statuses);
        self::assertSame(['dead', 'sent'], $statuses);
        self::assertContains(
            'telegram_http_401: Unauthorized',
            array_column($queue, 'last_error')
        );
        self::assertSame(['dead' => 1, 'sent' => 1], $outbox->statusCounts());

        self::assertSame(1, $outbox->retryUndelivered());
        self::assertSame(['pending' => 1, 'sent' => 1], $outbox->statusCounts());
        $requeued = array_values(array_filter(
            $outbox->recent(),
            static fn (array $job): bool => (string) $job['status'] === 'pending'
        ));
        self::assertSame(0, $requeued[0]['attempts']);
        self::assertNull($requeued[0]['last_error']);
    }

    /** @return array<string, mixed> */
    private function validSettings(): array
    {
        return [
            'email_enabled' => true,
            'smtp_host' => 'smtp.example.net',
            'smtp_port' => 587,
            'smtp_username' => 'monitor@example.net',
            'smtp_password' => 'smtp-secret',
            'smtp_encryption' => 'tls',
            'smtp_from_email' => 'monitor@example.net',
            'smtp_recipient_email' => 'ops@example.net',
            'telegram_enabled' => true,
            'telegram_bot_token' => '123:bot-secret',
            'telegram_chat_id' => '-100123',
            'telegram_proxy_type' => 'socks5h',
            'telegram_proxy_host' => 'proxy.internal',
            'telegram_proxy_port' => 1080,
            'telegram_proxy_username' => 'proxy-user',
            'telegram_proxy_password' => 'proxy-secret',
            'notify_on_warning' => true,
            'notify_on_critical' => true,
        ];
    }
}
