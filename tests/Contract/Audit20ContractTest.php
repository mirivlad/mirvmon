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
        self::assertStringContainsString('$administration->add($csrf)->add($admin)->add($auth)', $routes);
        self::assertStringContainsString('href="/admin/audit"', $layout);
        self::assertStringContainsString("t('nav.audit')", $layout);
    }

    public function testAuditStorageIsAppendOnlyAndDoesNotExposeMutationRoutes(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($root . '/migrations/018_audit_log.sql');
        $repository = (string) file_get_contents($root . '/src/Repositories/AuditLogRepository.php');
        $routes = (string) file_get_contents($root . '/src/Application/AppFactory.php');

        self::assertStringContainsString('BEFORE UPDATE OR DELETE ON audit_log', $migration);
        self::assertStringContainsString('audit_log is append-only', $migration);
        self::assertStringContainsString('public function append(', $repository);
        self::assertStringNotContainsString('public function update(', $repository);
        self::assertStringNotContainsString('public function delete(', $repository);
        self::assertStringNotContainsString("post('/audit", $routes);
        self::assertStringNotContainsString("delete('/audit", $routes);
    }

    public function testAuditUiProvidesOperationalFiltersAndSafeDetails(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/audit.twig'
        );

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
        ] as $needle) {
            self::assertStringContainsString($needle, $template);
        }
        self::assertStringNotContainsString('|raw', $template);
    }
}
