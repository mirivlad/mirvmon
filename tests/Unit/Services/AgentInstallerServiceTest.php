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

    public function testLinuxInstallerUsesOriginWithoutTokenizedDownloads(): void
    {
        $script = $this->installer->linux(
            'https://monitor.example',
            str_repeat('a', 64)
        );

        self::assertStringContainsString(
            'https://monitor.example/api/v1/metrics',
            $script
        );
        self::assertStringContainsString('User=mirvmon-agent', $script);
        self::assertStringContainsString('useradd --system', $script);
        self::assertStringContainsString('requests==2.34.2', $script);
        self::assertStringContainsString('psutil==7.2.2', $script);
        self::assertStringNotContainsString('?token=', $script);
        self::assertStringNotContainsString('mon.mirv.top', $script);
    }

    public function testWindowsInstallerUsesProgramDataAndTlsVerification(): void
    {
        $script = $this->installer->windowsPowerShell(
            'https://windows-monitor.example',
            str_repeat('b', 64)
        );

        self::assertStringContainsString('$env:ProgramData', $script);
        self::assertStringContainsString('MirvMon\\Agent', $script);
        self::assertStringContainsString('"verify_tls": true', $script);
        self::assertStringContainsString(
            'https://windows-monitor.example/api/v1/agent/config',
            $script
        );
        self::assertStringNotContainsString('?token=', $script);
        self::assertStringNotContainsString('mon.mirv.top', $script);
    }

    public function testBatchWrapperDoesNotExposeTheAgentTokenAsAnArgument(): void
    {
        $batch = $this->installer->windowsBatch(
            'https://monitor.example',
            str_repeat('c', 64)
        );

        self::assertStringContainsString('-EncodedCommand', $batch);
        self::assertStringNotContainsString(str_repeat('c', 64), $batch);
        self::assertStringNotContainsString('?token=', $batch);
    }

    public function testLegacyInstallerAvoidsEveryApiNewerThanPowerShell2(): void
    {
        $script = $this->installer->windowsLegacyPowerShell(
            'https://legacy-monitor.example',
            str_repeat('d', 64)
        );

        foreach ([
            'Invoke-WebRequest',
            'Invoke-RestMethod',
            'ConvertTo-Json',
            'ConvertFrom-Json',
            'Register-ScheduledTask',
            'New-ScheduledTaskAction',
            'Get-CimInstance',
        ] as $unavailable) {
            self::assertStringNotContainsString($unavailable, $script, $unavailable);
        }

        self::assertStringContainsString('schtasks.exe /Create', $script);
        self::assertStringContainsString('Get-WmiObject Win32_OperatingSystem', $script);
        self::assertStringContainsString('[Net.HttpWebRequest]::Create', $script);
        // Tls12 is spelled numerically because the enum member is missing on
        // the .NET versions these systems ship with.
        self::assertStringContainsString('[Net.SecurityProtocolType]3072', $script);
        self::assertStringNotContainsString('python', mb_strtolower($script));
    }

    public function testLegacyCollectorSendsTheSameEnvelopeContract(): void
    {
        $script = $this->installer->windowsLegacyPowerShell(
            'https://legacy-monitor.example',
            str_repeat('e', 64)
        );

        self::assertStringContainsString(
            'https://legacy-monitor.example/api/v1/metrics',
            $script
        );
        self::assertStringContainsString('"version":2,', $script);
        self::assertStringContainsString('"agent_version":"legacy-1.0",', $script);
        self::assertStringContainsString("'yyyy-MM-ddTHH:mm:ss') + 'Z'", $script);
        foreach (['cpu_load', 'ram_used', 'ram_total_gb', 'uptime', 'disk_used'] as $metric) {
            self::assertStringContainsString($metric, $script);
        }
        self::assertStringContainsString("'net_in_'", $script);
        self::assertStringContainsString("'disk_total_gb_'", $script);
        // The token belongs in the request body, never in a command line.
        self::assertStringNotContainsString('?token=', $script);
        self::assertStringContainsString(str_repeat('e', 64), $script);
    }

    public function testLegacyBatchHidesTheTokenAndElevates(): void
    {
        $batch = $this->installer->windowsLegacyBatch(
            'https://legacy-monitor.example',
            str_repeat('f', 64)
        );

        self::assertStringContainsString('net session', $batch);
        self::assertStringContainsString('-EncodedCommand', $batch);
        self::assertStringNotContainsString(str_repeat('f', 64), $batch);
    }
}
