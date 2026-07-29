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
}
