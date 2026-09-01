<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Backup\BackupSecretCatalog;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupSecretCatalogTest extends TestCase
{
    private static ?PDO $pdo = null;

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
        self::$pdo?->exec(
            'UPDATE notification_settings SET
                smtp_password_encrypted = NULL,
                telegram_bot_token_encrypted = NULL,
                telegram_proxy_password_encrypted = NULL
             WHERE id = 1'
        );
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testSecretsCanBeExportedUnderAAndAppliedUnderB(): void
    {
        $cipherA = new SecretCipher(str_repeat('a', 32));
        $cipherB = new SecretCipher(str_repeat('b', 32));
        $notification = self::$pdo?->prepare(
            'UPDATE notification_settings SET
                smtp_password_encrypted = :smtp,
                telegram_bot_token_encrypted = :telegram,
                telegram_proxy_password_encrypted = :proxy
             WHERE id = 1'
        );
        $notification?->execute([
            'smtp' => $cipherA->encrypt('smtp-secret'),
            'telegram' => $cipherA->encrypt('bot-secret'),
            'proxy' => $cipherA->encrypt('proxy-secret'),
        ]);

        $websiteId = (int) self::$pdo?->query(
            "INSERT INTO websites (name) VALUES ('secret-site') RETURNING id"
        )->fetchColumn();
        $endpoint = self::$pdo?->prepare(
            "INSERT INTO website_endpoints (
                website_id, name, url, is_primary, auth_type,
                auth_encrypted, headers_encrypted
             ) VALUES (
                :website_id, 'primary', 'https://example.test/', TRUE, 'basic',
                :auth, :headers
             ) RETURNING id"
        );
        $endpoint?->execute([
            'website_id' => $websiteId,
            'auth' => $cipherA->encrypt('{"type":"basic","username":"alice","secret":"wonderland"}'),
            'headers' => $cipherA->encrypt('{"Authorization":"Bearer hidden"}'),
        ]);
        $endpointId = (int) $endpoint?->fetchColumn();

        $payload = (new BackupSecretCatalog(self::$pdo, $cipherA))->export();
        self::assertSame(1, $payload['version']);
        self::assertSame('smtp-secret', $payload['notification']['smtp_password']);
        self::assertSame('bot-secret', $payload['notification']['telegram_bot_token']);
        self::assertSame('proxy-secret', $payload['notification']['telegram_proxy_password']);
        self::assertSame($endpointId, $payload['website_endpoints'][0]['id']);

        (new BackupSecretCatalog(self::$pdo, $cipherB))->apply($payload);

        $restoredNotification = self::$pdo?->query(
            'SELECT smtp_password_encrypted, telegram_bot_token_encrypted,
                    telegram_proxy_password_encrypted
             FROM notification_settings WHERE id = 1'
        )->fetch();
        self::assertIsArray($restoredNotification);
        self::assertSame('smtp-secret', $cipherB->decrypt($this->databaseBytes($restoredNotification['smtp_password_encrypted'])));
        self::assertSame('bot-secret', $cipherB->decrypt($this->databaseBytes($restoredNotification['telegram_bot_token_encrypted'])));
        self::assertSame('proxy-secret', $cipherB->decrypt($this->databaseBytes($restoredNotification['telegram_proxy_password_encrypted'])));

        $restoredEndpoint = self::$pdo?->query(
            'SELECT auth_encrypted, headers_encrypted
             FROM website_endpoints WHERE id = ' . $endpointId
        )->fetch();
        self::assertIsArray($restoredEndpoint);
        self::assertSame(
            '{"type":"basic","username":"alice","secret":"wonderland"}',
            $cipherB->decrypt($this->databaseBytes($restoredEndpoint['auth_encrypted']))
        );
        self::assertSame(
            '{"Authorization":"Bearer hidden"}',
            $cipherB->decrypt($this->databaseBytes($restoredEndpoint['headers_encrypted']))
        );
    }

    public function testApplyRejectsSecretPayloadThatDoesNotMatchRestoredEndpoints(): void
    {
        $cipher = new SecretCipher(str_repeat('a', 32));
        $websiteId = (int) self::$pdo?->query(
            "INSERT INTO websites (name) VALUES ('secret-site') RETURNING id"
        )->fetchColumn();
        self::$pdo?->exec(
            "INSERT INTO website_endpoints (website_id, name, url, is_primary)
             VALUES ({$websiteId}, 'primary', 'https://example.test/', TRUE)"
        );

        $payload = (new BackupSecretCatalog(self::$pdo, $cipher))->export();
        $payload['website_endpoints'] = [];

        $this->expectException(RuntimeException::class);
        (new BackupSecretCatalog(self::$pdo, $cipher))->apply($payload);
    }

    private function databaseBytes(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            self::assertIsString($contents);
            return $contents;
        }
        self::assertIsString($value);
        if (str_starts_with($value, '\\x')) {
            $decoded = hex2bin(substr($value, 2));
            self::assertIsString($decoded);
            return $decoded;
        }
        return $value;
    }
}
