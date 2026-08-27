<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/001_initial.sql');
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/002_timeseries_policies.sql');
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/003_current_metric_values.sql');
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/004_alert_state_invariants.sql');
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/005_notification_dead_letters.sql');
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/006_notification_delivery.sql');
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/016_dashboard_widgets_and_availability.sql');
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/017_incident_history.sql');
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/018_audit_log.sql');
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/019_audit_retention.sql');
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/020_website_monitoring.sql');
        self::assertFileExists(dirname(__DIR__, 3) . '/migrations/021_website_timeseries.sql');

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

    public function testTimescaleObjectsExist(): void
    {
        $extension = self::$pdo?->query(
            "SELECT extversion FROM pg_extension WHERE extname = 'timescaledb'"
        )->fetchColumn();
        self::assertIsString($extension);

        $hypertables = self::$pdo?->query(
            "SELECT hypertable_name
             FROM timescaledb_information.hypertables
             WHERE hypertable_name IN (
                 'metric_samples',
                 'process_snapshots',
                 'website_check_samples'
             )
             ORDER BY hypertable_name"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([
            'metric_samples',
            'process_snapshots',
            'website_check_samples',
        ], $hypertables);

        $aggregates = self::$pdo?->query(
            "SELECT view_name
             FROM timescaledb_information.continuous_aggregates
             WHERE view_name IN (
                 'metric_samples_daily',
                 'metric_samples_hourly',
                 'website_check_samples_daily',
                 'website_check_samples_hourly'
             )
             ORDER BY view_name"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([
            'metric_samples_daily',
            'metric_samples_hourly',
            'website_check_samples_daily',
            'website_check_samples_hourly',
        ], $aggregates);
    }

    public function testSecurityAndQueueTablesExistWithoutSeededAdministrator(): void
    {
        self::assertSame('0', (string) self::$pdo?->query('SELECT count(*) FROM users')->fetchColumn());

        $tables = self::$pdo?->query(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = 'public'
               AND table_name IN (
                   'agent_tokens',
                   'current_metric_values',
                   'ingested_samples',
                   'installer_tokens',
                   'notification_outbox',
                   'notification_settings'
               )
             ORDER BY table_name"
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame([
            'agent_tokens',
            'current_metric_values',
            'ingested_samples',
            'installer_tokens',
            'notification_outbox',
            'notification_settings',
        ], $tables);

        $secretColumns = self::$pdo?->query(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = 'notification_settings'
               AND column_name IN (
                   'smtp_password_encrypted',
                   'telegram_bot_token_encrypted',
                   'telegram_proxy_password_encrypted'
               )
             ORDER BY column_name"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([
            'smtp_password_encrypted',
            'telegram_bot_token_encrypted',
            'telegram_proxy_password_encrypted',
        ], $secretColumns);

        $plaintextSecretColumns = self::$pdo?->query(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND (
                   (table_name = 'notification_settings'
                    AND column_name IN (
                        'smtp_password',
                        'telegram_bot_token',
                        'telegram_proxy_password'
                    ))
                   OR
                   (table_name = 'agent_tokens' AND column_name = 'encrypted_token')
               )"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], $plaintextSecretColumns);
    }

    public function testSampleIdentityAndForeignKeysAreEnforced(): void
    {
        $primaryKey = self::$pdo?->query(
            "SELECT array_agg(attribute.attname ORDER BY key.ordinality)
             FROM pg_constraint constraint_definition
             JOIN unnest(constraint_definition.conkey) WITH ORDINALITY
               AS key(attribute_number, ordinality) ON TRUE
             JOIN pg_attribute attribute
               ON attribute.attrelid = constraint_definition.conrelid
              AND attribute.attnum = key.attribute_number
             WHERE constraint_definition.conrelid = 'ingested_samples'::regclass
               AND constraint_definition.contype = 'p'"
        )->fetchColumn();
        self::assertSame('{server_id,sample_id}', $primaryKey);

        $foreignKeys = self::$pdo?->query(
            "SELECT count(*)
             FROM pg_constraint
             WHERE contype = 'f'
               AND conrelid IN (
                   'metric_samples'::regclass,
                   'current_metric_values'::regclass,
                   'process_snapshots'::regclass,
                   'notification_outbox'::regclass,
                   'agent_tokens'::regclass
               )"
        )->fetchColumn();
        self::assertSame('9', (string) $foreignKeys);
    }

    public function testTimescaleMaintenancePoliciesAreInstalled(): void
    {
        $jobs = self::$pdo?->query(
            "SELECT regexp_replace(application_name, ' \\[[0-9]+\\]$', '')
             FROM timescaledb_information.jobs
             WHERE hypertable_name IN (
                 'metric_samples',
                 'process_snapshots',
                 'metric_samples_hourly',
                 'metric_samples_daily',
                 'website_check_samples',
                 'website_check_samples_hourly',
                 'website_check_samples_daily'
             )
             ORDER BY 1, hypertable_name"
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame([
            'Columnstore Policy',
            'Columnstore Policy',
            'Columnstore Policy',
            'Refresh Continuous Aggregate Policy',
            'Refresh Continuous Aggregate Policy',
            'Refresh Continuous Aggregate Policy',
            'Refresh Continuous Aggregate Policy',
            'Retention Policy',
            'Retention Policy',
            'Retention Policy',
            'Retention Policy',
            'Retention Policy',
        ], $jobs);
    }

    public function testMigrationsAreRecordedAndRepeatable(): void
    {
        $migrator = new Migrator(self::$pdo, dirname(__DIR__, 3) . '/migrations');
        self::assertSame([], $migrator->migrate());

        $count = self::$pdo?->query('SELECT count(*) FROM schema_migrations')->fetchColumn();
        self::assertSame('21', (string) $count);
    }

    public function testAvailabilitySchemaExists(): void
    {
        $tables = self::$pdo?->query(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = 'public'
               AND table_name IN ('server_availability_events', 'server_availability_state')
             ORDER BY table_name"
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame([
            'server_availability_events',
            'server_availability_state',
        ], $tables);
    }

    public function testAuditLogSchemaIsAppendOnlyWithControlledRetentionFunction(): void
    {
        self::assertSame('audit_log', self::$pdo?->query(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = 'public'
               AND table_name = 'audit_log'"
        )->fetchColumn());

        $columns = self::$pdo?->query(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = 'audit_log'
               AND column_name IN (
                   'occurred_at',
                   'actor_user_id',
                   'actor_username',
                   'actor_role',
                   'action',
                   'object_type',
                   'object_id',
                   'object_label',
                   'description',
                   'metadata'
               )
             ORDER BY column_name"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([
            'action',
            'actor_role',
            'actor_user_id',
            'actor_username',
            'description',
            'metadata',
            'object_id',
            'object_label',
            'object_type',
            'occurred_at',
        ], $columns);

        $trigger = self::$pdo?->query(
            "SELECT tgname
             FROM pg_trigger
             WHERE tgrelid = 'audit_log'::regclass
               AND NOT tgisinternal
               AND tgname = 'audit_log_append_only'"
        )->fetchColumn();
        self::assertSame('audit_log_append_only', $trigger);

        $retentionFunction = self::$pdo?->query(
            "SELECT proname
             FROM pg_proc
             WHERE proname = 'mirvmon_prune_audit_log'
               AND pg_get_function_identity_arguments(oid) = 'cutoff timestamp with time zone'"
        )->fetchColumn();
        self::assertSame('mirvmon_prune_audit_log', $retentionFunction);
    }

    public function testReportedOperatingSystemColumnIsNullable(): void
    {
        $column = self::$pdo?->query(
            "SELECT is_nullable, character_maximum_length
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = 'servers'
               AND column_name = 'os_version'"
        )->fetch(PDO::FETCH_NUM);
        self::assertSame(['YES', 255], $column);
    }

    public function testAgentSelfUpdateSchemaHasBoundedCommandState(): void
    {
        $columns = self::$pdo?->query(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = 'servers'
               AND column_name IN ('agent_artifact', 'agent_capabilities')
             ORDER BY column_name"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['agent_artifact', 'agent_capabilities'], $columns);

        self::assertSame('agent_update_commands', self::$pdo?->query(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = 'public'
               AND table_name = 'agent_update_commands'"
        )->fetchColumn());
    }
}
