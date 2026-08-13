<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class WindowsInstallerContractTest extends TestCase
{
    private string $nsis;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/resources/agent/windows/installer.nsi';
        $this->nsis = (string) file_get_contents($path);
    }

    public function testNsisSelectsOneNativeX64AgentAndRunsItsInstallerCommand(): void
    {
        self::assertStringContainsString('RequestExecutionLevel admin', $this->nsis);
        self::assertStringContainsString('ManifestSupportedOS all', $this->nsis);
        self::assertStringContainsString('SetCompressor /SOLID lzma', $this->nsis);
        self::assertStringContainsString('x64.nsh', $this->nsis);
        self::assertStringContainsString('WinVer.nsh', $this->nsis);
        self::assertStringContainsString('${RunningX64}', $this->nsis);
        self::assertStringContainsString('${AtLeastWin10}', $this->nsis);
        self::assertStringContainsString('${AtLeastWin7}', $this->nsis);
        self::assertStringContainsString('mirvmon-agent-modern.exe', $this->nsis);
        self::assertStringContainsString('mirvmon-agent-legacy.exe', $this->nsis);
        self::assertStringContainsString('bootstrap.json', $this->nsis);
        self::assertStringContainsString('install-windows', $this->nsis);
        self::assertStringContainsString('--expected-version', $this->nsis);
        self::assertStringContainsString('--expected-artifact', $this->nsis);
        self::assertStringContainsString('--expected-sha256', $this->nsis);
        self::assertStringContainsString('--expected-size', $this->nsis);
        self::assertStringContainsString('nsExec::ExecToLog /OEM', $this->nsis);
        self::assertStringContainsString('SetErrorLevel', $this->nsis);
    }

    public function testNsisContainsNoScriptRuntimeOrSecretCompilerDefine(): void
    {
        foreach (['powershell', 'cmd.exe', '.ps1', '.bat', 'INSTALLER_CREDENTIAL'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $this->nsis);
        }
        self::assertStringContainsString('InitPluginsDir', $this->nsis);
        self::assertLessThan(
            strpos($this->nsis, 'SetOutPath "$PLUGINSDIR"'),
            strpos($this->nsis, 'InitPluginsDir')
        );
        self::assertLessThan(
            strpos($this->nsis, 'nsExec::ExecToLog'),
            strpos($this->nsis, '${AtLeastWin10}')
        );
    }
}
