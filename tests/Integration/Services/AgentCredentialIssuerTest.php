<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Services\AgentCredentialIssuer;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentCredentialIssuerTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AgentCredentialIssuer $issuer;
    private int $serverId;

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
        $this->serverId = (int) self::$pdo?->query(
            "INSERT INTO servers (name) VALUES ('installer-server') RETURNING id"
        )->fetchColumn();
        $this->issuer = new AgentCredentialIssuer(self::$pdo, str_repeat('k', 32));
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    public function testInstallerCredentialsReuseTheCurrentTokenUntilExplicitRotation(): void
    {
        $firstInstaller = $this->issuer->issueInstaller($this->serverId);
        $secondInstaller = $this->issuer->issueInstaller($this->serverId);
        $credential = $this->issuer->exchange($firstInstaller);
        $sameCredential = $this->issuer->exchange($secondInstaller);

        self::assertSame($this->serverId, $credential->serverId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $credential->token);
        self::assertSame($credential->token, $sameCredential->token);
        self::assertSame(
            hash('sha256', $credential->token),
            self::$pdo?->query(
                'SELECT token_hash FROM agent_tokens WHERE server_id = ' . $this->serverId
            )->fetchColumn()
        );
        self::assertSame(
            '2',
            (string) self::$pdo?->query(
                'SELECT count(*) FROM installer_tokens WHERE consumed_at IS NOT NULL'
            )->fetchColumn()
        );

        $this->issuer->rotate($this->serverId);
        $rotatedInstaller = $this->issuer->issueInstaller($this->serverId);
        $rotated = $this->issuer->exchange($rotatedInstaller);
        self::assertNotSame($credential->token, $rotated->token);

        $this->expectException(RuntimeException::class);
        $this->issuer->exchange($firstInstaller);
    }

    public function testInstallerIssuanceRequiresRotationAfterRestoreWithDifferentKey(): void
    {
        $installer = $this->issuer->issueInstaller($this->serverId);
        $credential = $this->issuer->exchange($installer);
        $restoredIssuer = new AgentCredentialIssuer(self::$pdo, str_repeat('b', 32));

        self::assertTrue($this->issuer->canIssueInstaller($this->serverId));
        self::assertFalse($restoredIssuer->canIssueInstaller($this->serverId));
        self::assertSame(
            hash('sha256', $credential->token),
            self::$pdo?->query(
                'SELECT token_hash FROM agent_tokens WHERE server_id = ' . $this->serverId
            )->fetchColumn()
        );

        try {
            $restoredIssuer->issueInstaller($this->serverId);
            self::fail('Restored installation must not issue an unusable installer.');
        } catch (RuntimeException $exception) {
            self::assertSame('Agent credential requires explicit rotation.', $exception->getMessage());
        }

        $restoredIssuer->rotate($this->serverId);
        self::assertTrue($restoredIssuer->canIssueInstaller($this->serverId));
        $newInstaller = $restoredIssuer->issueInstaller($this->serverId);
        $newCredential = $restoredIssuer->exchange($newInstaller);
        self::assertNotSame($credential->token, $newCredential->token);
    }

    public function testInstallerCredentialCanBeValidatedWithoutConsumption(): void
    {
        $installer = $this->issuer->issueInstaller($this->serverId);

        self::assertTrue($this->issuer->validateInstaller($installer));
        self::assertTrue($this->issuer->validateInstaller($installer));
        self::assertSame(
            '0',
            (string) self::$pdo?->query(
                'SELECT count(*) FROM installer_tokens WHERE consumed_at IS NOT NULL'
            )->fetchColumn()
        );

        $this->issuer->exchange($installer);

        self::assertFalse($this->issuer->validateInstaller($installer));
        self::assertFalse($this->issuer->validateInstaller('not-a-token'));
    }

    public function testExpiredInstallerCredentialCannotBeValidatedOrExchanged(): void
    {
        $installer = $this->issuer->issueInstaller($this->serverId);
        self::$pdo?->exec(
            "UPDATE installer_tokens SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second'"
        );

        self::assertFalse($this->issuer->validateInstaller($installer));
        $this->expectException(RuntimeException::class);
        $this->issuer->exchange($installer);
    }
}
