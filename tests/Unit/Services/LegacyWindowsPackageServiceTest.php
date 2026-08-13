<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AgentArtifactCatalog;
use App\Services\LegacyWindowsPackageService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class LegacyWindowsPackageServiceTest extends TestCase
{
    private string $artifactDirectory;
    private string $templateDirectory;

    protected function setUp(): void
    {
        $this->artifactDirectory = sys_get_temp_dir() . '/mirvmon-legacy-package-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->artifactDirectory, 0700, true));
        $binary = "MZ\x90\x00legacy-agent-fixture";
        file_put_contents(
            $this->artifactDirectory . '/mirvmon-agent-linux-amd64',
            'linux-agent'
        );
        file_put_contents(
            $this->artifactDirectory . '/mirvmon-agent-windows-amd64.exe',
            'windows-agent'
        );
        file_put_contents(
            $this->artifactDirectory . '/mirvmon-agent-windows-legacy-amd64.exe',
            $binary
        );
        file_put_contents(
            $this->artifactDirectory . '/manifest.json',
            json_encode([
                'version' => 'v0.4.4',
                'artifacts' => [
                    'linux-amd64' => $this->artifact('mirvmon-agent-linux-amd64', 'linux-agent'),
                    'windows-amd64' => $this->artifact('mirvmon-agent-windows-amd64.exe', 'windows-agent'),
                    'windows-legacy-amd64' => $this->artifact(
                        'mirvmon-agent-windows-legacy-amd64.exe',
                        $binary
                    ),
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
        $this->templateDirectory = dirname(__DIR__, 3) . '/resources/agent/windows-legacy';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->artifactDirectory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->artifactDirectory);
    }

    public function testBuildsSelfContainedPackageFromVerifiedArtifact(): void
    {
        $token = str_repeat('a', 64);
        $package = (new LegacyWindowsPackageService($this->templateDirectory))->build(
            'https://monitor.example:8443',
            $token,
            AgentArtifactCatalog::load($this->artifactDirectory)
        );

        self::assertSame('mirvmon-agent-windows-legacy-amd64.zip', $package->filename);
        self::assertSame('application/zip', $package->contentType);
        self::assertNotSame('', $package->contents);

        $entries = $this->readZip($package->contents);
        $root = 'mirvmon-agent-windows-legacy-amd64/';
        self::assertSame([
            $root . 'install.bat',
            $root . 'mirvmon-agent.exe',
            $root . 'mirvmon-install-legacy.ps1',
            $root . 'server-config.json',
        ], array_keys($entries));
        self::assertSame("MZ\x90\x00legacy-agent-fixture", $entries[$root . 'mirvmon-agent.exe']);

        $configBytes = $entries[$root . 'server-config.json'];
        self::assertDoesNotMatchRegularExpression('/[^\x00-\x7F]/', $configBytes);
        self::assertStringStartsNotWith("\xEF\xBB\xBF", $configBytes);
        self::assertSame([
            'api_url' => 'https://monitor.example:8443/api/v1/metrics',
            'config_url' => 'https://monitor.example:8443/api/v1/agent/config',
            'token' => $token,
            'interval_seconds' => 60,
            'verify_tls' => true,
            'queue_path' => '%PROGRAMDATA%\\MirvMon\\Agent\\queue.json',
            'collect_process_commands' => false,
            'queue_limit' => 1000,
        ], json_decode($configBytes, true, 512, JSON_THROW_ON_ERROR));

        $script = $entries[$root . 'mirvmon-install-legacy.ps1'];
        self::assertStringContainsString('v0.4.4', $script);
        self::assertStringContainsString(hash('sha256', "MZ\x90\x00legacy-agent-fixture"), $script);
        self::assertStringContainsString('windows-legacy-amd64', $script);
        self::assertStringNotContainsString($token, $script);
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

    /** @return array<string, string> */
    private function readZip(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'mirvmon-package-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            self::assertNotFalse($name);
            $value = $zip->getFromIndex($index);
            self::assertNotFalse($value);
            $entries[$name] = $value;
        }
        $zip->close();
        unlink($path);
        ksort($entries);

        return $entries;
    }
}
