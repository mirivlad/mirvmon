<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\MaintenanceWindowRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\NotificationSettingsRepository;
use App\Repositories\ObservationRepository;
use App\Security\SecretCipher;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class ObservationNotificationOutboxTest extends TestCase
{
    private static ?PDO $pdo = null;
    private int $serverId;
    private int $observationId;
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
        (new NotificationSettingsRepository(self::$pdo, new SecretCipher(str_repeat('o', 32))))->save([
            'email_enabled' => 'on',
            'smtp_host' => 'smtp.example.net',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_from_email' => 'monitor@example.net',
            'smtp_recipients' => "global@example.net\n",
            'telegram_enabled' => 'on',
            'telegram_bot_token' => '123:token',
            'telegram_chat_id' => '-100',
            'notify_on_warning' => 'on',
            'notify_on_critical' => 'on',
            'cooldown_seconds' => '0',
        ]);
        $this->serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name, notification_telegram_chat_id, notification_emails)
             VALUES ('db-1', '-500', '[\"db@example.net\"]'::jsonb)
             RETURNING id"
        )->fetchColumn();
        $metricId = (int) self::$pdo?->query(
            "SELECT id FROM metric_names WHERE name = 'cpu_load'"
        )->fetchColumn();
        $created = (new ObservationRepository(self::$pdo))->recordCandidate(
            $this->serverId,
            $metricId,
            [
                'kind' => 'anomaly',
                'detector' => 'level_shift_v1',
                'fingerprint' => 'level_shift_v1:cpu_load:p0:b20',
                'current_value' => 21.0,
                'baseline_value' => 3.0,
                'confidence' => 0.9,
                'forecast_at' => null,
                'details' => ['metric' => 'cpu_load', 'reason' => 'sustained'],
            ],
            new DateTimeImmutable('2026-09-16T00:00:00Z')
        );
        $this->observationId = $created['id'];
        $this->outbox = new NotificationOutboxRepository(self::$pdo);
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testObservationUsesServerRecipientsWithoutFakeAlert(): void
    {
        self::assertSame(2, $this->enqueue('observation:test:1'));
        self::assertSame([
            ['email', 'db@example.net'],
            ['telegram', '-500'],
        ], self::$pdo?->query(
            'SELECT channel, recipient FROM notification_outbox ORDER BY channel, recipient'
        )->fetchAll(PDO::FETCH_NUM));
        self::assertSame(0, (int) self::$pdo?->query(
            'SELECT count(*) FROM notification_outbox WHERE alert_id IS NOT NULL'
        )->fetchColumn());
        self::assertSame(2, (int) self::$pdo?->query(
            'SELECT count(*) FROM notification_outbox WHERE observation_id = ' . $this->observationId
        )->fetchColumn());
        self::assertSame(0, $this->enqueue('observation:test:1'));
    }

    public function testMaintenanceSuppressesObservationDelivery(): void
    {
        $maintenance = new MaintenanceWindowRepository(self::$pdo);
        $maintenance->start($this->serverId, 3600, 'Database work', 'operator');

        self::assertSame(0, $this->enqueue('observation:test:maintenance'));
        self::assertSame(0, (int) self::$pdo?->query(
            'SELECT count(*) FROM notification_outbox'
        )->fetchColumn());
    }

    private function enqueue(string $deduplicationKey): int
    {
        return $this->outbox->enqueueObservationConfigured(
            $this->serverId,
            $this->observationId,
            'observation_anomaly',
            [
                'type' => 'observation',
                'kind' => 'anomaly',
                'server_id' => $this->serverId,
                'server_name' => 'db-1',
                'observation_id' => $this->observationId,
                'metric' => 'cpu_load',
                'current_value' => 21.0,
                'baseline_value' => 3.0,
                'confidence' => 0.9,
                'severity' => 'warning',
                'event_time' => '2026-09-16T00:00:00+00:00',
            ],
            $deduplicationKey
        );
    }
}
