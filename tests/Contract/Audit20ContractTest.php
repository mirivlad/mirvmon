<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class Audit20ContractTest extends TestCase
{
    public function testAuditLogIsAdminOnlyAndLinkedFromSettingsNavigation(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/src/Application/AppFactory.php');
        $layout = (string) file_get_contents($root . '/templates/layout.twig');

        self::assertStringContainsString(
            '$group->get(\'/audit\', self::controller($container, AuditController::class, \'index\'))',
            $routes
        );
        self::assertStringContainsString('$administration->add($auditTrail)->add($csrf)->add($admin)->add($auth)', $routes);
        self::assertStringContainsString('href="/admin/audit"', $layout);
        self::assertStringContainsString("t('nav.audit')", $layout);
    }

    public function testAuditStorageIsAppendOnlyWithOnlyControlledRetentionDeletion(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($root . '/migrations/018_audit_log.sql');
        $retentionMigration = (string) file_get_contents($root . '/migrations/019_audit_retention.sql');
        $repository = (string) file_get_contents($root . '/src/Repositories/AuditLogRepository.php');
        $routes = (string) file_get_contents($root . '/src/Application/AppFactory.php');

        self::assertStringContainsString('BEFORE UPDATE OR DELETE ON audit_log', $migration);
        self::assertStringContainsString('audit_log is append-only', $migration);
        self::assertStringContainsString('mirvmon_prune_audit_log', $retentionMigration);
        self::assertStringContainsString("current_setting('mirvmon.audit_retention_prune', true) = 'on'", $retentionMigration);
        self::assertStringContainsString('public function append(', $repository);
        self::assertStringNotContainsString('public function update(', $repository);
        self::assertStringNotContainsString('public function delete(', $repository);
        self::assertStringNotContainsString("post('/audit/delete", $routes);
        self::assertStringNotContainsString("delete('/audit", $routes);
    }

    public function testAuditUiProvidesOperationalFiltersSafeDetailsAndSeparateRetentionPolicy(): void
    {
        $root = dirname(__DIR__, 2);
        $template = (string) file_get_contents($root . '/templates/admin/audit.twig');
        $routes = (string) file_get_contents($root . '/src/Application/AppFactory.php');

        foreach ([
            'name="actor"',
            'name="action"',
            'name="object_type"',
            'name="object_id"',
            'name="from"',
            'name="to"',
            'name="q"',
            'row.object_url',
            'row.metadata_text',
            'action="/admin/audit/retention"',
            'name="retention_days"',
        ] as $needle) {
            self::assertStringContainsString($needle, $template);
        }
        self::assertStringContainsString(
            '$group->post(\'/audit/retention\', self::controller($container, AuditController::class, \'saveRetention\'))',
            $routes
        );
        self::assertStringNotContainsString('|raw', $template);
    }

    public function testRequiredAdministrativeMutationsAreInsideAuditBoundary(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/src/Application/AppFactory.php');
        $middleware = (string) file_get_contents($root . '/src/Middlewares/AuditTrailMiddleware.php');

        self::assertStringContainsString('$protected->add($auditTrail)->add($csrf)->add($auth)', $routes);
        self::assertStringContainsString('$administration->add($auditTrail)->add($csrf)->add($admin)->add($auth)', $routes);

        foreach ([
            'server.update',
            'server.delete',
            'server.token.rotate',
            'server.maintenance.start',
            'server.maintenance.cancel',
            'server.thresholds.save',
            'server.services.save',
            'server.agent_update.request',
            'server.agent_update.bulk_request',
            'group.create',
            'group.update',
            'group.delete',
            'user.create',
            'user.update',
            'user.delete',
            'notifications.save',
            'notification_queue.retry',
            'notification_queue.job.retry',
            'notification_queue.job.delete',
            'notification_queue.delete',
        ] as $action) {
            self::assertStringContainsString("'{$action}'", $middleware, $action);
        }
    }

    public function testAuditDescriptionsAreGeneratedAndMetadataHasSecretRedactionLayer(): void
    {
        $root = dirname(__DIR__, 2);
        $middleware = (string) file_get_contents($root . '/src/Middlewares/AuditTrailMiddleware.php');
        $logger = (string) file_get_contents($root . '/src/Services/AuditLogger.php');

        self::assertStringContainsString('$this->translator->trans($descriptionKey, $parameters)', $middleware);
        self::assertStringContainsString('self::sanitizeMetadata($metadata)', $logger);
        foreach (['password', 'token', 'secret', 'credential', 'authorization', 'api[_-]?key'] as $needle) {
            self::assertStringContainsString($needle, $logger);
        }
    }

    public function testAuditRetentionRunsAsItsOwnWorkerAndNeverUsesTimescaleMetricPolicies(): void
    {
        $root = dirname(__DIR__, 2);
        $worker = (string) file_get_contents($root . '/bin/audit-retention-worker');
        $supervisor = (string) file_get_contents($root . '/docker/supervisord.conf');
        $service = (string) file_get_contents($root . '/src/Services/AuditRetentionService.php');
        $documentation = (string) file_get_contents($root . '/docs/audit-log.md');

        self::assertStringContainsString('AuditRetentionService', $worker);
        self::assertStringContainsString('[program:audit-retention-worker]', $supervisor);
        self::assertStringContainsString("public const SETTING_KEY = 'audit_retention_days'", $service);
        self::assertStringContainsString('mirvmon_prune_audit_log', $service);
        self::assertStringContainsString('independent from metrics/process retention', $documentation);
    }
}
