<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;
use ZipArchive;

final class LegacyWindowsPackageService
{
    private const PACKAGE_ROOT = 'mirvmon-agent-windows-legacy-amd64/';

    public function __construct(private readonly string $templateDirectory)
    {
    }

    public function build(
        string $baseUrl,
        string $agentToken,
        AgentArtifactCatalog $catalog
    ): LegacyWindowsPackage {
        new PublicUrlResolver($baseUrl);
        if (preg_match('/^[a-f0-9]{64}$/', $agentToken) !== 1) {
            throw new RuntimeException('Invalid agent token.');
        }

        $artifact = $catalog->require('windows-legacy-amd64');
        $batch = $this->readTemplate('install.bat');
        $powerShell = $this->renderPowerShell(
            $this->readTemplate('mirvmon-install-legacy.ps1'),
            $catalog->version(),
            $artifact
        );
        $serverConfig = $this->serverConfig($baseUrl, $agentToken);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'mirvmon-legacy-package-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Cannot create legacy agent package.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create legacy agent package.');
            }
            try {
                $this->addString($zip, self::PACKAGE_ROOT . 'install.bat', $batch);
                $this->addFile($zip, self::PACKAGE_ROOT . 'mirvmon-agent.exe', $artifact->path);
                $this->addString(
                    $zip,
                    self::PACKAGE_ROOT . 'mirvmon-install-legacy.ps1',
                    $powerShell
                );
                $this->addString(
                    $zip,
                    self::PACKAGE_ROOT . 'server-config.json',
                    $serverConfig
                );
            } finally {
                if (!$zip->close()) {
                    throw new RuntimeException('Cannot finalize legacy agent package.');
                }
            }

            $contents = file_get_contents($temporaryPath);
            if ($contents === false || $contents === '') {
                throw new RuntimeException('Cannot read legacy agent package.');
            }

            return new LegacyWindowsPackage(
                'mirvmon-agent-windows-legacy-amd64.zip',
                'application/zip',
                $contents
            );
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function readTemplate(string $filename): string
    {
        $path = rtrim($this->templateDirectory, '/') . '/' . $filename;
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            throw new RuntimeException('Legacy Windows installer template is unavailable.');
        }

        return $contents;
    }

    private function renderPowerShell(
        string $template,
        string $version,
        AgentArtifact $artifact
    ): string {
        $replacements = [
            '__EXPECTED_VERSION__' => $version,
            '__EXPECTED_ARTIFACT__' => $artifact->key,
            '__EXPECTED_SHA256__' => $artifact->sha256,
            '__EXPECTED_SIZE__' => (string) $artifact->size,
        ];
        foreach ($replacements as $placeholder => $value) {
            if (substr_count($template, $placeholder) !== 1) {
                throw new RuntimeException('Legacy Windows installer template is invalid.');
            }
            $template = str_replace($placeholder, $value, $template);
        }

        return $template;
    }

    private function serverConfig(string $baseUrl, string $agentToken): string
    {
        try {
            $contents = json_encode([
                'api_url' => $baseUrl . '/api/v1/metrics',
                'config_url' => $baseUrl . '/api/v1/agent/config',
                'token' => $agentToken,
                'interval_seconds' => 60,
                'verify_tls' => true,
                'queue_path' => '%PROGRAMDATA%\\MirvMon\\Agent\\queue.json',
                'collect_process_commands' => false,
                'queue_limit' => 1000,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot generate agent configuration.', 0, $exception);
        }
        if (preg_match('/[^\x00-\x7F]/', $contents) === 1) {
            throw new RuntimeException('Legacy agent configuration must be ASCII.');
        }

        return $contents . "\r\n";
    }

    private function addString(ZipArchive $zip, string $name, string $contents): void
    {
        if (!$zip->addFromString($name, $contents)) {
            throw new RuntimeException('Cannot add a file to the legacy agent package.');
        }
    }

    private function addFile(ZipArchive $zip, string $name, string $path): void
    {
        if (!$zip->addFile($path, $name)) {
            throw new RuntimeException('Cannot add the native agent to the legacy package.');
        }
    }
}
