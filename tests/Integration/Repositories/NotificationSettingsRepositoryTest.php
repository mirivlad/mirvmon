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

    public function testAServerOverrideReplacesTheInstallationWideRecipients(): void
    {
        $this->repository->save($this->validSettings());
        $outbox = new NotificationOutboxRepository(self::$pdo);
        $shared = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('shared') RETURNING id"
        )->fetchColumn();
        $owned = (int) self::$pdo?->query(
            "INSERT INTO servers (name, notification_telegram_chat_id, notification_emails)
             VALUES ('owned', '-500', '[\"ivan@example.net\"]'::jsonb)
             RETURNING id"
        )->fetchColumn();
        $alert = (int) self::$pdo?->query(
            "INSERT INTO alerts (server_id, kind, subject, severity)
             VALUES ({$owned}, 'metric', 'cpu_load', 'critical')
             RETURNING id"
        )->fetchColumn();

        $payload = ['severity' => 'critical', 'server_id' => $owned];
        $outbox->enqueueConfigured($owned, $alert, 'metric_triggered', $payload, 'own');
        $outbox->enqueueConfigured($shared, $alert, 'metric_triggered', $payload, 'shared');

        $statement = self::$pdo?->prepare(
            'SELECT channel, recipient
             FROM notification_outbox
             WHERE server_id = :id
             ORDER BY channel, recipient'
        );
        $statement?->execute(['id' => $owned]);
        self::assertSame(
            [['email', 'ivan@example.net'], ['telegram', '-500']],
            $statement?->fetchAll(PDO::FETCH_NUM)
        );

        $statement?->execute(['id' => $shared]);
        self::assertSame(
            [['email', 'ops@example.net'], ['telegram', '-100123']],
            $statement?->fetchAll(PDO::FETCH_NUM)
        );
    }

    public function testCooldownRateLimitsARepeatingEventButNotItsRecovery(): void
    {
        $settings = $this->validSettings();
        $settings['cooldown_seconds'] = '600';
        $this->repository->save($settings);
        $outbox = new NotificationOutboxRepository(self::$pdo);
        $serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('flapping') RETURNING id"
        )->fetchColumn();
        $alertId = (int) self::$pdo?->query(
            "INSERT INTO alerts (server_id, kind, subject, severity)
             VALUES ({$serverId}, 'metric', 'cpu_load', 'critical')
             RETURNING id"
        )->fetchColumn();

        $trigger = static fn (string $key): array => [
            $key,
            ['severity' => 'critical', 'metric' => 'cpu_load', 'server_id' => $serverId],
        ];

        [$key, $payload] = $trigger('first');
        self::assertSame(2, $outbox->enqueueConfigured(
            $serverId,
            $alertId,
            'metric_triggered',
            $payload,
            $key
        ));

        // Same metric, same event, inside the window: nothing queued.
        [$key, $payload] = $trigger('second');
        self::assertSame(0, $outbox->enqueueConfigured(
            $serverId,
            $alertId,
            'metric_triggered',
            $payload,
            $key
        ));

        // A different metric is a different subject.
        self::assertSame(2, $outbox->enqueueConfigured(
            $serverId,
            $alertId,
            'metric_triggered',
            ['severity' => 'critical', 'metric' => 'ram_used', 'server_id' => $serverId],
            'other-metric'
        ));

        // The all-clear is a different event type and must never be delayed.
        self::assertSame(2, $outbox->enqueueConfigured(
            $serverId,
            $alertId,
            'metric_recovered',
            ['severity' => 'critical', 'metric' => 'cpu_load', 'server_id' => $serverId],
            'recovery'
        ));
    }

    public function testPurgeKeepsUndeliveredJobsAndRecentHistory(): void
    {
        $outbox = new NotificationOutboxRepository(self::$pdo);
        self::$pdo?->exec(
            "INSERT INTO notification_outbox
                (channel, event_type, payload, deduplication_key, status, sent_at, created_at)
             VALUES
                ('telegram', 'test', '{}'::jsonb, 'old-sent', 'sent',
                 CURRENT_TIMESTAMP - INTERVAL '30 days',
                 CURRENT_TIMESTAMP - INTERVAL '30 days'),
                ('telegram', 'test', '{}'::jsonb, 'fresh-sent', 'sent',
                 CURRENT_TIMESTAMP - INTERVAL '1 day',
                 CURRENT_TIMESTAMP - INTERVAL '1 day'),
                ('telegram', 'test', '{}'::jsonb, 'old-dead', 'dead', NULL,
                 CURRENT_TIMESTAMP - INTERVAL '60 days'),
                ('telegram', 'test', '{}'::jsonb, 'fresh-dead', 'dead', NULL,
                 CURRENT_TIMESTAMP - INTERVAL '2 days'),
                ('telegram', 'test', '{}'::jsonb, 'ancient-pending', 'pending', NULL,
                 CURRENT_TIMESTAMP - INTERVAL '90 days')"
        );

        self::assertSame(2, $outbox->purge(7, 30));
        self::assertSame(
            ['ancient-pending', 'fresh-dead', 'fresh-sent'],
            self::$pdo?->query(
                'SELECT deduplication_key FROM notification_outbox
                 ORDER BY deduplication_key'
            )->fetchAll(PDO::FETCH_COLUMN)
        );
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
            'smtp_recipients' => 'ops@example.net',
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
