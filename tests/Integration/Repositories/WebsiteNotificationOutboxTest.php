<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\MaintenanceWindowRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\NotificationSettingsRepository;
use App\Security\SecretCipher;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class WebsiteNotificationOutboxTest extends TestCase
{
    private static ?PDO $pdo = null;
    private int $websiteId;
    private int $alertId;
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
        (new NotificationSettingsRepository(self::$pdo, new SecretCipher(str_repeat('w', 32))))->save([
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
            'cooldown_seconds' => '600',
        ]);
        $this->websiteId = (int) self::$pdo?->query(
            "INSERT INTO websites (name, notification_telegram_chat_id, notification_emails)
             VALUES ('Portal', '-500', '[\"site@example.net\"]'::jsonb)
             RETURNING id"
        )->fetchColumn();
        $this->alertId = (int) self::$pdo?->query(
            "INSERT INTO alerts (website_id, kind, subject, severity)
             VALUES ({$this->websiteId}, 'website_http', 'Home', 'critical')
             RETURNING id"
        )->fetchColumn();
        $this->outbox = new NotificationOutboxRepository(self::$pdo);
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testWebsiteRecipientsOverrideGlobalRecipientsAndCooldownIsScopedToEndpoint(): void
    {
        self::assertSame(2, $this->enqueue('first', 8));
        self::assertSame([
            ['email', 'site@example.net'],
            ['telegram', '-500'],
        ], self::$pdo?->query(
            'SELECT channel, recipient FROM notification_outbox ORDER BY channel, recipient'
        )->fetchAll(PDO::FETCH_NUM));
        self::assertSame(0, $this->enqueue('second', 8));
        self::assertSame(2, $this->enqueue('other-endpoint', 9));
    }

    public function testWebsiteMaintenanceSuppressesDeliveryButNotTheAlert(): void
    {
        $maintenance = new MaintenanceWindowRepository(self::$pdo);
        $maintenance->startWebsite($this->websiteId, 3600, 'Deployment', 'operator');

        self::assertSame(0, $this->enqueue('during', 8));
        self::assertSame(1, (int) self::$pdo?->query('SELECT count(*) FROM alerts')->fetchColumn());
        self::assertSame('Deployment', $maintenance->activeWebsite($this->websiteId)['reason']);
        self::assertSame(1, $maintenance->cancelWebsite($this->websiteId));
        self::assertSame(2, $this->enqueue('after', 8));
    }

    public function testWebsitePayloadRejectsSecretBearingKeys(): void
    {
        $payload = $this->payload(8);
        $payload['headers'] = ['Authorization' => 'Bearer do-not-store'];

        $this->expectException(InvalidArgumentException::class);
        $this->outbox->enqueueWebsiteConfigured($this->websiteId, $this->alertId, 'website_http_triggered', $payload, 'secret');
    }

    private function enqueue(string $key, int $endpointId): int
    {
        return $this->outbox->enqueueWebsiteConfigured(
            $this->websiteId,
            $this->alertId,
            'website_http_triggered',
            $this->payload($endpointId),
            $key,
        );
    }

    /** @return array<string, scalar|null> */
    private function payload(int $endpointId): array
    {
        return [
            'type' => 'website_http',
            'event' => 'triggered',
            'severity' => 'critical',
            'website_id' => $this->websiteId,
            'website_name' => 'Portal',
            'endpoint_id' => $endpointId,
            'endpoint_name' => 'Home',
            'safe_url' => 'https://example.com/?token=%5Bredacted%5D',
            'event_time' => '2026-08-28T00:00:00+00:00',
        ];
    }
}
