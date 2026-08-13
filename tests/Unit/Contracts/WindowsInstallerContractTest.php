<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class WindowsInstallerContractTest extends TestCase
{
    private string $powerShell;
    private string $nsis;

    protected function setUp(): void
    {
        $directory = dirname(__DIR__, 3) . '/resources/agent/windows';
        $this->powerShell = (string) file_get_contents($directory . '/mirvmon-install.ps1');
        $this->nsis = (string) file_get_contents($directory . '/installer.nsi');
    }

    public function testPowerShellSelectsTheSupportedArtifactBeforeActivation(): void
    {
        self::assertStringContainsString('Win32_OperatingSystem', $this->powerShell);
        self::assertStringContainsString("'windows-legacy-amd64'", $this->powerShell);
        self::assertStringContainsString("'windows-amd64'", $this->powerShell);
        self::assertStringContainsString('ServicePackMajorVersion', $this->powerShell);
        self::assertStringContainsString('only x64 Windows is supported', $this->powerShell);
        self::assertStringContainsString('Windows 7 and Server 2008 R2 require Service Pack 1', $this->powerShell);
        self::assertStringContainsString('unsupported Windows version', $this->powerShell);

        $selection = $this->position('function Select-AgentArtifact');
        $activation = $this->position("'activate'");
        $commit = strrpos($this->powerShell, 'Commit-Installation');
        self::assertNotFalse($commit);
        self::assertLessThan($activation, $selection);
        self::assertLessThan($commit, $activation);
    }

    public function testPowerShellStaysInsidePs2BoundaryAndUsesNativeTlsActivation(): void
    {
        foreach ([
            '$PSScriptRoot',
            'Net.WebClient',
            'Invoke-WebRequest',
            'Start-BitsTransfer',
            'ConvertFrom-Json',
            'ConvertTo-Json',
            'Get-CimInstance',
            '::new(',
            '#requires -Version 3',
            '.Dispose()',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->powerShell);
        }
        self::assertStringContainsString('$MyInvocation.MyCommand.Path', $this->powerShell);
        self::assertStringContainsString('SHA256Managed', $this->powerShell);
        self::assertStringContainsString('$Hasher.Clear()', $this->powerShell);
        self::assertStringContainsString('$Stream.Close()', $this->powerShell);
        self::assertStringContainsString("@('activate', '--bootstrap'", $this->powerShell);
    }

    public function testValidationMigrationAndActivationPrecedeCommit(): void
    {
        $version = $this->position("'version'");
        $activation = $this->position("'activate'");
        $check = $this->position("'check-server-config'");
        $migrate = $this->position("'migrate-state'");
        $finalCheck = $this->position("'check-migrated-config'");
        $commitCall = strrpos($this->powerShell, 'Commit-Installation');
        self::assertNotFalse($commitCall);

        self::assertLessThan($activation, $version);
        self::assertLessThan($check, $activation);
        self::assertLessThan($migrate, $check);
        self::assertLessThan($finalCheck, $migrate);
        self::assertLessThan($commitCall, $finalCheck);
    }

    public function testTransactionRetainsProtectedAclQueueFreezeAndRollback(): void
    {
        self::assertStringNotContainsString("'SYSTEM:", $this->powerShell);
        self::assertStringNotContainsString("'Administrators:", $this->powerShell);
        self::assertStringContainsString('*S-1-5-18:', $this->powerShell);
        self::assertStringContainsString('*S-1-5-32-544:', $this->powerShell);
        self::assertStringContainsString('$LASTEXITCODE', $this->powerShell);
        self::assertStringContainsString('.legacy-', $this->powerShell);
        self::assertStringContainsString("Join-Path \$StateDir 'queue.txt'", $this->powerShell);
        self::assertStringContainsString("'commit-migrate-state'", $this->powerShell);
        self::assertStringContainsString('Wait-ServiceRunning', $this->powerShell);
        self::assertStringContainsString('function Rollback-Installation', $this->powerShell);
        self::assertStringContainsString("schtasks.exe' @('/Delete'", $this->powerShell);
    }

    public function testNsisIsAnElevatedSelfContainedWrapper(): void
    {
        self::assertStringContainsString('RequestExecutionLevel admin', $this->nsis);
        self::assertStringContainsString('ManifestSupportedOS all', $this->nsis);
        self::assertStringContainsString('SetCompressor /SOLID lzma', $this->nsis);
        self::assertStringContainsString('mirvmon-agent-modern.exe', $this->nsis);
        self::assertStringContainsString('mirvmon-agent-legacy.exe', $this->nsis);
        self::assertStringContainsString('bootstrap.json', $this->nsis);
        self::assertStringContainsString('mirvmon-install.ps1', $this->nsis);
        self::assertStringContainsString('SetErrorLevel', $this->nsis);
        self::assertStringNotContainsString('INSTALLER_CREDENTIAL', $this->nsis);
    }

    private function position(string $needle): int
    {
        $position = strpos($this->powerShell, $needle);
        self::assertNotFalse($position, 'Missing installer marker: ' . $needle);
        return $position;
    }
}
