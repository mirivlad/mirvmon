<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AgentArtifactCatalog;
use App\Services\WindowsInstallerPackageService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WindowsInstallerPackageServiceTest extends TestCase
{
    private string $directory;
    private string $artifactDirectory;
    private string $compiler;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mirvmon-windows-package-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
        $this->artifactDirectory = $this->directory . '/artifacts';
        self::assertTrue(mkdir($this->artifactDirectory, 0700));
        $this->createCatalog();
        $this->compiler = $this->directory . '/fake-makensis';
        file_put_contents($this->compiler, <<<'SH'
#!/bin/sh
set -eu
payload=''
output=''
printf '%s\n' "$@" > "$(dirname "$0")/argv.txt"
for argument in "$@"; do
    case "$argument" in
        -DPAYLOAD_DIR=*) payload=${argument#-DPAYLOAD_DIR=} ;;
        -DOUTPUT_FILE=*) output=${argument#-DOUTPUT_FILE=} ;;
    esac
done
[ -n "$payload" ]
[ -n "$output" ]
cp -R "$payload" "$(dirname "$0")/captured"
printf 'MZfake-installer' > "$output"
SH
        );
        chmod($this->compiler, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testBuildsPersonalizedExeFromBothVerifiedArtifacts(): void
    {
        $credential = str_repeat('a', 64);
        $package = $this->service()->build(
            'https://monitor.example:8443',
            $credential,
            AgentArtifactCatalog::load($this->artifactDirectory)
        );

        self::assertSame('MirvMon-Agent-Setup.exe', $package->filename);
        self::assertSame('application/vnd.microsoft.portable-executable', $package->contentType);
        self::assertSame('MZfake-installer', $package->contents);
        self::assertSame('modern-agent', file_get_contents($this->directory . '/captured/mirvmon-agent-modern.exe'));
        self::assertSame('legacy-agent', file_get_contents($this->directory . '/captured/mirvmon-agent-legacy.exe'));

        $bootstrap = json_decode(
            (string) file_get_contents($this->directory . '/captured/bootstrap.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame([
            'base_url' => 'https://monitor.example:8443',
            'installer_credential' => $credential,
        ], $bootstrap);

        $script = (string) file_get_contents($this->directory . '/captured/mirvmon-install.ps1');
        self::assertStringContainsString("\$ExpectedVersion = 'v0.4.5'", $script);
        self::assertStringContainsString(hash('sha256', 'modern-agent'), $script);
        self::assertStringContainsString(hash('sha256', 'legacy-agent'), $script);
        self::assertStringNotContainsString($credential, $script);
        self::assertStringNotContainsString($credential, (string) file_get_contents($this->directory . '/argv.txt'));
    }

    public function testRejectsCompilerFailureWithoutLeavingPrivatePayload(): void
    {
        file_put_contents($this->compiler, "#!/bin/sh\nexit 7\n");
        chmod($this->compiler, 0700);
        try {
            $this->service()->build(
                'https://monitor.example',
                str_repeat('c', 64),
                AgentArtifactCatalog::load($this->artifactDirectory)
            );
            self::fail('Compiler failure was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString(str_repeat('c', 64), $exception->getMessage());
            self::assertStringContainsString('compile', strtolower($exception->getMessage()));
        }

        self::assertSame([], glob($this->directory . '/work/mirvmon-windows-installer-*') ?: []);
    }

    public function testRejectsNonExecutableCompilerOutput(): void
    {
        $script = (string) file_get_contents($this->compiler);
        file_put_contents($this->compiler, str_replace("printf 'MZfake-installer'", "printf 'not-an-exe'", $script));
        chmod($this->compiler, 0700);

        $this->expectException(RuntimeException::class);
        $this->service()->build(
            'https://monitor.example',
            str_repeat('d', 64),
            AgentArtifactCatalog::load($this->artifactDirectory)
        );
    }

    public function testRealNsisCompilerProducesWindowsExecutableWhenAvailable(): void
    {
        $compiler = '/usr/bin/makensis';
        if (!is_executable($compiler)) {
            self::markTestSkipped('NSIS is not installed in this development environment.');
        }

        $package = (new WindowsInstallerPackageService(
            dirname(__DIR__, 3) . '/resources/agent/windows',
            $compiler,
            $this->directory . '/real-work'
        ))->build(
            'https://monitor.example',
            str_repeat('e', 64),
            AgentArtifactCatalog::load($this->artifactDirectory)
        );

        self::assertStringStartsWith('MZ', $package->contents);
        self::assertGreaterThan(1024, strlen($package->contents));
    }

    private function service(): WindowsInstallerPackageService
    {
        return new WindowsInstallerPackageService(
            dirname(__DIR__, 3) . '/resources/agent/windows',
            $this->compiler,
            $this->directory . '/work'
        );
    }

    private function createCatalog(): void
    {
        $files = [
            'mirvmon-agent-linux-amd64' => 'linux-agent',
            'mirvmon-agent-windows-amd64.exe' => 'modern-agent',
            'mirvmon-agent-windows-legacy-amd64.exe' => 'legacy-agent',
        ];
        foreach ($files as $filename => $contents) {
            file_put_contents($this->artifactDirectory . '/' . $filename, $contents);
        }
        file_put_contents($this->artifactDirectory . '/manifest.json', json_encode([
            'version' => 'v0.4.5',
            'artifacts' => [
                'linux-amd64' => $this->artifact('mirvmon-agent-linux-amd64', 'linux-agent'),
                'windows-amd64' => $this->artifact('mirvmon-agent-windows-amd64.exe', 'modern-agent'),
                'windows-legacy-amd64' => $this->artifact('mirvmon-agent-windows-legacy-amd64.exe', 'legacy-agent'),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{filename: string, sha256: string, size: int, content_type: string} */
    private function artifact(string $filename, string $contents): array
    {
        return [
            'filename' => $filename,
            'sha256' => hash('sha256', $contents),
            'size' => strlen($contents),
            'content_type' => 'application/octet-stream',
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
