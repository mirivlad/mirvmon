<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class WebsiteSchemaTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/020_website_monitoring.sql');

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

        (new Migrator(self::pdo(), dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        self::pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    public function testWebsiteTablesAndGeneralizedSourcesExist(): void
    {
        self::assertSame('monitoring_groups', $this->table('monitoring_groups'));
        self::assertFalse($this->table('server_groups'));

        $tables = [
            'websites',
            'website_endpoints',
            'website_content_checks',
            'website_endpoint_state',
            'website_state',
            'website_tls_targets',
            'website_tls_state',
            'website_certificate_events',
            'website_domain_state',
            'website_check_jobs',
            'website_availability_events',
        ];
        foreach ($tables as $table) {
            self::assertSame($table, $this->table($table));
        }
    }

    public function testServerGroupRowsAndForeignKeyUseRenamedTable(): void
    {
        $groupId = self::pdo()->query(
            "INSERT INTO monitoring_groups (name) VALUES ('Existing') RETURNING id"
        )->fetchColumn();
        self::pdo()->exec(
            "INSERT INTO servers (name, group_id) VALUES ('Preserved server', " . (int) $groupId . ')'
        );

        $row = self::pdo()->query(
            "SELECT monitoring_groups.name
             FROM servers
             JOIN monitoring_groups ON monitoring_groups.id = servers.group_id
             WHERE servers.name = 'Preserved server'"
        )->fetchColumn();

        self::assertSame('Existing', $row);
    }

    public function testOnlyOnePrimaryEndpointIsAllowedPerWebsite(): void
    {
        $websiteId = $this->createWebsite('Portal');
        $this->createEndpoint($websiteId, 'Primary', true);

        $this->expectException(PDOException::class);
        $this->createEndpoint($websiteId, 'Second primary', true);
    }

    public function testAlertRejectsMissingSource(): void
    {
        $this->expectException(PDOException::class);
        self::pdo()->exec(
            "INSERT INTO alerts (kind, severity) VALUES ('website_http', 'critical')"
        );
    }

    public function testAlertRejectsTwoSources(): void
    {
        $websiteId = $this->createWebsite('Portal');
        $serverId = self::pdo()->query(
            "INSERT INTO servers (name) VALUES ('Host') RETURNING id"
        )->fetchColumn();

        $this->expectException(PDOException::class);
        self::pdo()->exec(sprintf(
            "INSERT INTO alerts (server_id, website_id, kind, severity)
             VALUES (%d, %d, 'website_http', 'critical')",
            (int) $serverId,
            $websiteId
        ));
    }

    public function testWebsiteAlertEndpointMustBelongToItsWebsite(): void
    {
        $firstWebsite = $this->createWebsite('First');
        $secondWebsite = $this->createWebsite('Second');
        $endpointId = $this->createEndpoint($firstWebsite, 'Primary', true);

        $this->expectException(PDOException::class);
        self::pdo()->exec(sprintf(
            "INSERT INTO alerts (website_id, endpoint_id, kind, severity)
             VALUES (%d, %d, 'website_http', 'critical')",
            $secondWebsite,
            $endpointId
        ));
    }

    public function testJobTargetMustMatchItsKind(): void
    {
        $websiteId = $this->createWebsite('Portal');
        $endpointId = $this->createEndpoint($websiteId, 'Primary', true);

        $this->expectException(PDOException::class);
        self::pdo()->exec(sprintf(
            "INSERT INTO website_check_jobs (
                website_id, endpoint_id, kind, scheduled_for, available_at
             ) VALUES (%d, %d, 'domain', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            $websiteId,
            $endpointId
        ));
    }

    public function testMaintenanceWindowRejectsMissingSource(): void
    {
        $this->expectException(PDOException::class);
        self::pdo()->exec(
            "INSERT INTO maintenance_windows (ends_at) VALUES (CURRENT_TIMESTAMP + INTERVAL '1 hour')"
        );
    }

    public function testOutboxAllowsNoSourceButRejectsTwoSources(): void
    {
        self::pdo()->exec(
            "INSERT INTO notification_outbox (channel, event_type, payload)
             VALUES ('email', 'transport_test', '{}'::jsonb)"
        );

        $websiteId = $this->createWebsite('Portal');
        $serverId = self::pdo()->query(
            "INSERT INTO servers (name) VALUES ('Host') RETURNING id"
        )->fetchColumn();

        $this->expectException(PDOException::class);
        self::pdo()->exec(sprintf(
            "INSERT INTO notification_outbox (
                server_id, website_id, channel, event_type, payload
             ) VALUES (%d, %d, 'email', 'alert', '{}'::jsonb)",
            (int) $serverId,
            $websiteId
        ));
    }

    public function testWebsiteSecretsHaveOnlyEncryptedColumns(): void
    {
        $columns = self::pdo()->query(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = 'website_endpoints'
             ORDER BY column_name"
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('auth_encrypted', $columns);
        self::assertContains('headers_encrypted', $columns);
        self::assertNotContains('auth_secret', $columns);
        self::assertNotContains('headers', $columns);
    }

    private function table(string $name): string|false
    {
        $statement = self::pdo()->prepare(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name = :name"
        );
        $statement->execute(['name' => $name]);

        return $statement->fetchColumn();
    }

    private function createWebsite(string $name): int
    {
        $statement = self::pdo()->prepare(
            'INSERT INTO websites (name) VALUES (:name) RETURNING id'
        );
        $statement->execute(['name' => $name]);

        return (int) $statement->fetchColumn();
    }

    private function createEndpoint(int $websiteId, string $name, bool $primary): int
    {
        $statement = self::pdo()->prepare(
            "INSERT INTO website_endpoints (website_id, name, url, is_primary)
             VALUES (:website_id, :name, 'https://example.com/', :is_primary)
             RETURNING id"
        );
        $statement->execute([
            'website_id' => $websiteId,
            'name' => $name,
            'is_primary' => $primary,
        ]);

        return (int) $statement->fetchColumn();
    }

    private static function pdo(): PDO
    {
        return self::$pdo ?? throw new \LogicException('Integration database is not connected.');
    }
}
