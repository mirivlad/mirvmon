<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class LegacyWindowsInstallerContractTest extends TestCase
{
    private string $batch;
    private string $powerShell;

    protected function setUp(): void
    {
        $directory = dirname(__DIR__, 3) . '/resources/agent/windows-legacy';
        $this->batch = (string) file_get_contents($directory . '/install.bat');
        $this->powerShell = (string) file_get_contents(
            $directory . '/mirvmon-install-legacy.ps1'
        );
    }

    public function testBatchIsReadableLocalBootstrap(): void
    {
        self::assertStringContainsString('%~dp0', $this->batch);
        self::assertStringContainsString('net session', $this->batch);
        self::assertStringContainsString('-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File', $this->batch);
        self::assertStringContainsString('exit /b %INSTALL_EXIT_CODE%', $this->batch);
        self::assertStringNotContainsString('-EncodedCommand', $this->batch);
        self::assertStringNotContainsString('base64', strtolower($this->batch));
    }

    public function testPowerShellStaysInsideOfflinePs2Boundary(): void
    {
        foreach ([
            '$PSScriptRoot',
            'Net.WebClient',
            'Invoke-WebRequest',
            'Start-BitsTransfer',
            'certutil',
            'curl',
            'wget',
            'ConvertTo-Json',
            'Get-CimInstance',
            'Register-ScheduledTask',
            '::new(',
            '#requires -Version 3',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->powerShell);
        }
        self::assertStringContainsString('$MyInvocation.MyCommand.Path', $this->powerShell);
        self::assertStringContainsString('Split-Path -Parent', $this->powerShell);
        self::assertStringContainsString('SHA256Managed', $this->powerShell);
        self::assertStringContainsString(
            '$AbsoluteQueueJson = $QueuePath.Replace',
            $this->powerShell
        );
        self::assertStringContainsString(
            '$MigratedConfigText.Replace($AbsoluteQueueJson, $PreflightQueueJson)',
            $this->powerShell
        );
    }

    public function testClr2CleanupUsesMethodsVisibleToPowerShell2(): void
    {
        self::assertStringNotContainsString('.Dispose()', $this->powerShell);
        self::assertStringContainsString('$Hasher.Clear()', $this->powerShell);
        self::assertStringContainsString('$Stream.Close()', $this->powerShell);
    }

    public function testAclUsesOnlyWellKnownSidSyntaxAndNativeExitChecks(): void
    {
        self::assertStringNotContainsString("'SYSTEM:", $this->powerShell);
        self::assertStringNotContainsString("'Administrators:", $this->powerShell);
        self::assertStringContainsString('*S-1-5-18:', $this->powerShell);
        self::assertStringContainsString('*S-1-5-32-544:', $this->powerShell);
        self::assertStringContainsString('$LASTEXITCODE', $this->powerShell);
        self::assertStringContainsString('Invoke-NativeRequired', $this->powerShell);
        self::assertStringContainsString('exit code', $this->powerShell);
    }

    public function testValidationAndMigrationPrecedeCommit(): void
    {
        $version = $this->position("'version'");
        $check = $this->position("'check-server-config'");
        $migrate = $this->position("'migrate-state'");
        $finalCheck = $this->position("'check-migrated-config'");
        $commit = $this->position('function Commit-Installation');
        $commitCall = strrpos($this->powerShell, 'Commit-Installation');

        self::assertLessThan($check, $version);
        self::assertLessThan($migrate, $check);
        self::assertLessThan($finalCheck, $migrate);
        self::assertLessThan($commitCall, $finalCheck);
        self::assertLessThan($commitCall, $commit);
    }

    public function testCommitHasBackupsQuotedServicePathVerificationAndRollback(): void
    {
        self::assertStringContainsString('.legacy-', $this->powerShell);
        self::assertStringContainsString('Win32_Service', $this->powerShell);
        self::assertStringContainsString('binPath=', $this->powerShell);
        self::assertStringContainsString('Wait-ServiceRunning', $this->powerShell);
        self::assertStringContainsString('function Rollback-Installation', $this->powerShell);
        self::assertStringContainsString('Rollback-Installation', $this->powerShell);
        self::assertStringContainsString("schtasks.exe' @('/Delete'", $this->powerShell);
    }

    public function testCommitFreezesAndRefreshesLegacyQueueBeforeReplacingFiles(): void
    {
        $stopTask = $this->position("'stop-old-task'");
        $disableTask = $this->position("'disable-old-task'");
        $finalMigration = $this->position("'commit-migrate-state'");
        $replaceAgent = $this->position(
            'Copy-Item -LiteralPath $StageAgentPath -Destination $InstalledAgentPath'
        );

        self::assertLessThan($stopTask, $disableTask);
        self::assertLessThan($finalMigration, $disableTask);
        self::assertLessThan($replaceAgent, $finalMigration);
        self::assertStringContainsString('$LegacyTaskWasEnabled', $this->powerShell);
        self::assertStringContainsString("'rollback-enable-old-task'", $this->powerShell);
    }

    private function position(string $needle): int
    {
        $position = strpos($this->powerShell, $needle);
        self::assertNotFalse($position, 'Missing installer marker: ' . $needle);

        return $position;
    }
}
