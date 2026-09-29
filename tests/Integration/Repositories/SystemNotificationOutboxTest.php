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

final class SystemNotificationOutboxTest extends TestCase
{
    private static ?PDO $pdo = null;
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
        (new NotificationSettingsRepository(
            self::$pdo,
            new SecretCipher(str_repeat('s', 32))
        ))->save([
            'email_enabled' => 'on',
            'smtp_host' => 'smtp.example.net',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_username' => 'monitor',
            'smtp_password' => 'smtp-secret',
            'smtp_from_email' => 'monitor@example.net',
            'smtp_from_name' => 'MirvMon',
            'smtp_recipients' => "ops@example.net\n",
            'telegram_enabled' => 'on',
            'telegram_bot_token' => '123:token',
            'telegram_chat_id' => '-100',
            'notify_on_warning' => 'on',
            'notify_on_critical' => 'on',
            'cooldown_seconds' => '0',
        ]);
        $this->outbox = new NotificationOutboxRepository(self::$pdo);
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testSystemNotificationUsesGlobalRecipientsAndDeduplicates(): void
    {
        $payload = [
            'type' => 'connectivity',
            'event' => 'recovered',
            'outage_started_at' => '2026-09-29T15:14:32+00:00',
            'outage_ended_at' => '2026-09-29T16:07:18+00:00',
            'duration_seconds' => 3166,
            'event_time' => '2026-09-29T16:07:18+00:00',
        ];

        self::assertSame(
            2,
            $this->outbox->enqueueSystemConfigured(
                'connectivity_recovered',
                $payload,
                'connectivity:test-outage'
            )
        );
        self::assertSame([
            ['email', 'ops@example.net', null, null, null],
            ['telegram', '-100', null, null, null],
        ], self::$pdo?->query(
            'SELECT channel, recipient, server_id, website_id, alert_id
             FROM notification_outbox
             ORDER BY channel, recipient'
        )->fetchAll(PDO::FETCH_NUM));

        self::assertSame(
            0,
            $this->outbox->enqueueSystemConfigured(
                'connectivity_recovered',
                $payload,
                'connectivity:test-outage'
            )
        );
    }
}
