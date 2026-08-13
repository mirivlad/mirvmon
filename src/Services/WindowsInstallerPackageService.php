<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;
use Throwable;

final class WindowsInstallerPackageService
{
    private const MAXIMUM_PACKAGE_BYTES = 128 * 1024 * 1024;

    public function __construct(
        private readonly string $templateDirectory,
        private readonly string $compiler = '/usr/bin/makensis',
        private readonly ?string $temporaryRoot = null
    ) {
    }

    public function build(
        string $baseUrl,
        string $installerCredential,
        AgentArtifactCatalog $catalog
    ): WindowsInstallerPackage {
        $baseUrl = rtrim(trim($baseUrl), '/');
        new PublicUrlResolver($baseUrl);
        if (preg_match('/^[a-f0-9]{64}$/', $installerCredential) !== 1) {
            throw new RuntimeException('Invalid installer credential.');
        }
        if (!is_executable($this->compiler)) {
            throw new RuntimeException('Windows installer compiler is unavailable.');
        }

        $modern = $catalog->require('windows-amd64');
        $legacy = $catalog->require('windows-legacy-amd64');
        $powerShell = $this->renderPowerShell(
            $this->readTemplate('mirvmon-install.ps1'),
            $catalog->version(),
            $modern,
            $legacy
        );
        $nsisPath = $this->templatePath('installer.nsi');
        $root = $this->temporaryRoot ?? sys_get_temp_dir();
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Cannot create Windows installer workspace.');
        }
        $workDirectory = rtrim($root, '/') . '/mirvmon-windows-installer-' . bin2hex(random_bytes(16));
        $payloadDirectory = $workDirectory . '/payload';
        $outputPath = $workDirectory . '/MirvMon-Agent-Setup.exe';
        $logPath = $workDirectory . '/compiler.log';

        if (!mkdir($payloadDirectory, 0700, true)) {
            throw new RuntimeException('Cannot create Windows installer workspace.');
        }
        try {
            $this->writePrivate($payloadDirectory . '/bootstrap.json', $this->bootstrap(
                $baseUrl,
                $installerCredential
            ));
            $this->writePrivate($payloadDirectory . '/mirvmon-install.ps1', $powerShell);
            $this->copyArtifact($modern, $payloadDirectory . '/mirvmon-agent-modern.exe');
            $this->copyArtifact($legacy, $payloadDirectory . '/mirvmon-agent-legacy.exe');

            $exitCode = $this->compile(
                $payloadDirectory,
                $outputPath,
                $nsisPath,
                $logPath
            );
            if ($exitCode !== 0) {
                throw new RuntimeException('Cannot compile Windows installer.');
            }
            $size = is_file($outputPath) ? filesize($outputPath) : false;
            if ($size === false || $size < 2 || $size > self::MAXIMUM_PACKAGE_BYTES) {
                throw new RuntimeException('Windows installer compiler produced invalid output.');
            }
            $contents = file_get_contents($outputPath);
            if ($contents === false || !str_starts_with($contents, 'MZ')) {
                throw new RuntimeException('Windows installer compiler produced invalid output.');
            }

            return new WindowsInstallerPackage(
                'MirvMon-Agent-Setup.exe',
                'application/vnd.microsoft.portable-executable',
                $contents
            );
        } finally {
            $this->removeDirectory($workDirectory);
        }
    }

    private function readTemplate(string $filename): string
    {
        $path = $this->templatePath($filename);
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            throw new RuntimeException('Windows installer template is unavailable.');
        }
        return $contents;
    }

    private function templatePath(string $filename): string
    {
        $path = rtrim($this->templateDirectory, '/') . '/' . $filename;
        if (!is_file($path)) {
            throw new RuntimeException('Windows installer template is unavailable.');
        }
        return $path;
    }

    private function renderPowerShell(
        string $template,
        string $version,
        AgentArtifact $modern,
        AgentArtifact $legacy
    ): string {
        $values = [
            '__EXPECTED_VERSION__' => $version,
            '__MODERN_SHA256__' => $modern->sha256,
            '__MODERN_SIZE__' => (string) $modern->size,
            '__LEGACY_SHA256__' => $legacy->sha256,
            '__LEGACY_SIZE__' => (string) $legacy->size,
        ];
        foreach ($values as $placeholder => $value) {
            if (substr_count($template, $placeholder) !== 1) {
                throw new RuntimeException('Windows installer template is invalid.');
            }
            $template = str_replace($placeholder, $value, $template);
        }
        return $template;
    }

    private function bootstrap(string $baseUrl, string $credential): string
    {
        try {
            return json_encode([
                'base_url' => $baseUrl,
                'installer_credential' => $credential,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode Windows installer bootstrap.', previous: $exception);
        }
    }

    private function writePrivate(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) === false || !chmod($path, 0600)) {
            throw new RuntimeException('Cannot write Windows installer payload.');
        }
    }

    private function copyArtifact(AgentArtifact $artifact, string $destination): void
    {
        if (!copy($artifact->path, $destination) || !chmod($destination, 0500)) {
            throw new RuntimeException('Cannot copy Windows agent artifact.');
        }
    }

    private function compile(
        string $payloadDirectory,
        string $outputPath,
        string $nsisPath,
        string $logPath
    ): int {
        $process = proc_open([
            $this->compiler,
            '-V2',
            '-DPAYLOAD_DIR=' . $payloadDirectory,
            '-DOUTPUT_FILE=' . $outputPath,
            $nsisPath,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logPath, 'w'],
            2 => ['file', $logPath, 'a'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start Windows installer compiler.');
        }
        return proc_close($process);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        try {
            $entries = scandir($directory);
            if ($entries === false) {
                return;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . '/' . $entry;
                if (is_dir($path) && !is_link($path)) {
                    $this->removeDirectory($path);
                } else {
                    @unlink($path);
                }
            }
            @rmdir($directory);
        } catch (Throwable) {
            // Cleanup must not hide the original build outcome.
        }
    }
}
