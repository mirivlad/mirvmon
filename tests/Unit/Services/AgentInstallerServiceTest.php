<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AgentInstallerService;
use PHPUnit\Framework\TestCase;

final class AgentInstallerServiceTest extends TestCase
{
    private AgentInstallerService $installer;

    protected function setUp(): void
    {
        $this->installer = new AgentInstallerService();
    }

    public function testLinuxInstallerDownloadsNativeBinaryAndMigratesState(): void
    {
        $script = $this->installer->linux(
            'https://monitor.example',
            str_repeat('a', 64)
        );

        self::assertStringContainsString(
            'https://monitor.example/agent/binaries/linux-amd64',
            $script
        );
        self::assertStringContainsString('migrate --source-config', $script);
        self::assertStringContainsString('User=mirvmon-agent', $script);
        self::assertStringContainsString('ExecStart=/opt/mirvmon-agent/mirvmon-agent run', $script);
        self::assertStringNotContainsString('python', mb_strtolower($script));
        self::assertStringNotContainsString('?token=', $script);
    }

    public function testModernWindowsInstallerUsesModernNativeArtifactAndService(): void
    {
        $script = $this->installer->windowsPowerShell(
            'https://windows-monitor.example',
            str_repeat('b', 64)
        );

        self::assertStringContainsString(
            'https://windows-monitor.example/agent/binaries/windows-amd64',
            $script
        );
        self::assertStringContainsString('sc.exe create MirvMonAgent', $script);
        self::assertStringContainsString('migrate --source-config', $script);
        self::assertStringContainsString('"verify_tls": true', $script);
        self::assertStringNotContainsString('Invoke-WebRequest', $script);
        self::assertStringNotContainsString('?token=', $script);
    }

    public function testLegacyWindowsInstallerUsesLegacyNativeArtifactWithoutNewPowerShellApis(): void
    {
        $script = $this->installer->windowsLegacyPowerShell(
            'https://legacy-monitor.example',
            str_repeat('c', 64)
        );

        self::assertStringContainsString(
            'https://legacy-monitor.example/agent/binaries/windows-legacy-amd64',
            $script
        );
        self::assertStringContainsString('New-Object Net.WebClient', $script);
        self::assertStringContainsString('sc.exe create MirvMonAgent', $script);
        foreach (['Invoke-WebRequest', 'ConvertTo-Json', 'Register-ScheduledTask', 'Get-CimInstance'] as $unavailable) {
            self::assertStringNotContainsString($unavailable, $script);
        }
        self::assertStringNotContainsString('python', mb_strtolower($script));
    }

    public function testBatchWrappersKeepTokensOutOfCommandLine(): void
    {
        foreach ([
            $this->installer->windowsBatch('https://monitor.example', str_repeat('d', 64)),
            $this->installer->windowsLegacyBatch('https://monitor.example', str_repeat('e', 64)),
        ] as $batch) {
            self::assertStringContainsString('-EncodedCommand', $batch);
            self::assertStringNotContainsString('?token=', $batch);
            self::assertStringNotContainsString(str_repeat('d', 64), $batch);
            self::assertStringNotContainsString(str_repeat('e', 64), $batch);
        }
    }
}
