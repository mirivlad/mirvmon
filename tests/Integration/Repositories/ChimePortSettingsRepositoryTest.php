<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repositories\ChimePortSettingsRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Security\SecretCipher;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ChimePortSettingsRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private ChimePortSettingsRepository $settings;
    private array $oldEnv = [];
    private string $legacyFile;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            self::markTestSkipped('Requires TimescaleDB integration database.');
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
        foreach (['CHIMEPORT_ENABLED','CHIMEPORT_BASE_URL','CHIMEPORT_CHANNEL','CHIMEPORT_TOKEN_FILE'] as $key) {
            $this->oldEnv[$key] = getenv($key);
            putenv($key);
        }
        $this->legacyFile = (string) tempnam(sys_get_temp_dir(), 'chimeport-settings-test-');
        file_put_contents($this->legacyFile, 'cp_t_' . str_repeat('A', 43));
        chmod($this->legacyFile, 0600);
        $this->settings = new ChimePortSettingsRepository(
            self::$pdo, new SecretCipher(str_repeat('c',32))
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->oldEnv as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        @unlink($this->legacyFile);
        if (self::$pdo?->inTransaction()) self::$pdo->rollBack();
    }

    public function testLegacyEnvironmentConfigurationCanBeSavedWithoutPastingToken(): void
    {
        putenv('CHIMEPORT_ENABLED=1');
        putenv('CHIMEPORT_BASE_URL=https://notify.example.org');
        putenv('CHIMEPORT_CHANNEL=servers');
        putenv('CHIMEPORT_TOKEN_FILE=' . $this->legacyFile);

        $public = $this->settings->getPublic();
        self::assertTrue($public['enabled']);
        self::assertTrue($public['has_token']);
        self::assertTrue($public['legacy']);
        self::assertArrayNotHasKey('token', $public);

        $this->settings->save([
            'chimeport_enabled'=>'on',
            'chimeport_base_url'=>'https://notify.example.org',
            'chimeport_channel'=>'servers',
            'chimeport_token'=>'',
        ]);

        $public = $this->settings->getPublic();
        self::assertTrue($public['enabled']);
        self::assertTrue($public['has_token']);
        self::assertFalse($public['legacy']);
        self::assertArrayNotHasKey('token', $public);

        $encrypted = self::$pdo?->query(
            "SELECT encode(chimeport_token_encrypted,'escape') FROM notification_settings WHERE id=1"
        )->fetchColumn();
        self::assertIsString($encrypted);
        self::assertStringNotContainsString('cp_t_', $encrypted);

        putenv('CHIMEPORT_ENABLED=0');
        putenv('CHIMEPORT_TOKEN_FILE');
        $delivery = $this->settings->getForDelivery();
        self::assertTrue($delivery['enabled']);
        self::assertSame('servers', $delivery['channel']);
        self::assertSame('cp_t_' . str_repeat('A',43), $delivery['token']);

        $this->settings->save([
            'chimeport_enabled'=>'on',
            'chimeport_base_url'=>'https://notify.example.org',
            'chimeport_channel'=>'servers',
        ]);
        self::assertSame($delivery, $this->settings->getForDelivery());
    }

    public function testExplicitDisableOverridesLegacyEnvironmentAndDoesNotQueue(): void
    {
        putenv('CHIMEPORT_ENABLED=1');
        putenv('CHIMEPORT_BASE_URL=https://notify.example.org');
        putenv('CHIMEPORT_CHANNEL=servers');
        putenv('CHIMEPORT_TOKEN_FILE=' . $this->legacyFile);
        $this->settings->save([
            'chimeport_base_url'=>'https://notify.example.org',
            'chimeport_channel'=>'servers',
        ]);
        self::assertFalse($this->settings->getPublic()['enabled']);
        $count = (new NotificationOutboxRepository(self::$pdo))->enqueueTest([
            'event_time'=>'2026-11-05T12:00:00Z',
        ], 'chimeport');
        self::assertSame(0, $count);
    }

    public function testOnlyChimePortGetsQueuedWhenTestingChimePort(): void
    {
        $this->settings->save([
            'chimeport_enabled'=>'on',
            'chimeport_base_url'=>'https://notify.example.org',
            'chimeport_channel'=>'servers',
            'chimeport_token'=>'cp_t_' . str_repeat('B',43),
        ]);
        self::assertSame(1, (new NotificationOutboxRepository(self::$pdo))->enqueueTest(
            ['event_time'=>'2026-11-05T12:01:00Z'],
            'chimeport'
        ));
        $recent = self::$pdo?->query(
            "SELECT channel FROM notification_outbox WHERE event_type='test' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        self::assertSame('chimeport',$recent);
    }

    public function testInvalidUrlSlugAndMissingTokenRejected(): void
    {
        foreach ([
            ['chimeport_enabled'=>'on','chimeport_base_url'=>'http://notify.example.org','chimeport_channel'=>'servers','chimeport_token'=>'cp_t_'.str_repeat('B',43)],
            ['chimeport_enabled'=>'on','chimeport_base_url'=>'https://notify.example.org','chimeport_channel'=>'../admin','chimeport_token'=>'cp_t_'.str_repeat('B',43)],
            ['chimeport_enabled'=>'on','chimeport_base_url'=>'https://notify.example.org','chimeport_channel'=>'servers'],
        ] as $input) {
            try {
                $this->settings->save($input);
                self::fail('Expected invalid settings to be rejected.');
            } catch (InvalidArgumentException) {
                // Input is rejected before any DB update.
            }
        }
        self::assertNull(self::$pdo?->query('SELECT chimeport_enabled FROM notification_settings WHERE id=1')->fetchColumn());
    }

    public function testClearingTokenWorksOnlyWhenDisabled(): void
    {
        $this->settings->save([
            'chimeport_enabled'=>'on',
            'chimeport_base_url'=>'https://notify.example.org',
            'chimeport_channel'=>'servers',
            'chimeport_token'=>'cp_t_' . str_repeat('B',43),
        ]);
        $this->expectException(InvalidArgumentException::class);
        $this->settings->save([
            'chimeport_enabled'=>'on',
            'chimeport_base_url'=>'https://notify.example.org',
            'chimeport_channel'=>'servers',
            'clear_chimeport_token'=>'on',
        ]);
    }
}
