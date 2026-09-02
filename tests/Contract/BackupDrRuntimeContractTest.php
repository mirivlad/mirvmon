<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class BackupDrRuntimeContractTest extends TestCase
{
    public function testProductionRuntimeSupervisesDedicatedDrWorker(): void
    {
        $root = dirname(__DIR__, 2);
        $supervisor = (string) file_get_contents($root . '/docker/supervisord.conf');
        $dockerfile = (string) file_get_contents($root . '/docker/Dockerfile');
        $compose = (string) file_get_contents($root . '/docker/docker-compose.yml');

        self::assertStringContainsString('[program:dr-worker]', $supervisor);
        self::assertStringContainsString('command=/app/bin/dr-worker', $supervisor);
        self::assertStringContainsString('/app/bin/dr-worker', $dockerfile);
        self::assertStringContainsString('DR_WORKER_INTERVAL:', $compose);
        self::assertStringContainsString('BACKUP_MAX_UPLOAD_BYTES:', $compose);
    }

    public function testHttpMaintenanceGateIsActuallyInstalled(): void
    {
        $root = dirname(__DIR__, 2);
        $factory = (string) file_get_contents($root . '/src/Application/AppFactory.php');
        $middleware = (string) file_get_contents($root . '/src/Middlewares/DrMaintenanceMiddleware.php');

        self::assertStringContainsString('new DrMaintenanceMiddleware(', $factory);
        self::assertStringContainsString("private readonly array \$exemptPaths = ['/livez']", $middleware);
        self::assertStringContainsString("withHeader('Retry-After', '5')", $middleware);
    }

    public function testRestoreConfirmationQueuesWorkInsteadOfRunningCutoverInHttp(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root . '/src/Controllers/SystemController.php');
        $worker = (string) file_get_contents($root . '/bin/dr-worker');

        self::assertStringContainsString('$store->queue($id);', $controller);
        self::assertStringNotContainsString('new DisasterRecoveryRestorer(', $controller);
        self::assertStringContainsString('new DisasterRecoveryRestorer(', $worker);
        self::assertStringContainsString('recoverInterruptedCutover()', $worker);
    }

    public function testFailedRestoreKeepsSourceVersionsVisibleForTroubleshooting(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/backup.twig');

        self::assertStringContainsString("restore_operation.error_code|default('restore_failed')", $template);
        self::assertStringContainsString('failed_source.mirvmon_version', $template);
        self::assertStringContainsString('failed_source.postgres_version', $template);
        self::assertStringContainsString('failed_source.postgres_version_num', $template);
        self::assertStringContainsString('failed_source.timescale_version', $template);
    }

    public function testBackupCreationQueuesWorkerJobAndDownloadsOnlyCompletedArchive(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root . '/src/Controllers/SystemController.php');
        $worker = (string) file_get_contents($root . '/bin/dr-worker');
        $factory = (string) file_get_contents($root . '/src/Application/AppFactory.php');

        self::assertStringContainsString('$this->backupStore()->begin($password, $filename)', $controller);
        self::assertStringNotContainsString('new FullBackupCreator(', $controller);
        self::assertStringContainsString('new FullBackupCreator(', $worker);
        self::assertStringContainsString('$backupStore->claimNext($workerId)', $worker);
        self::assertStringContainsString("'/system/backup/{id}/download'", $factory);
        self::assertStringContainsString('$this->backupStore()->download($id)', $controller);
    }
}
