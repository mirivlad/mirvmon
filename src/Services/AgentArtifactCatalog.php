<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class AgentArtifactCatalog
{
    /** @var array<string, string> */
    private const FILENAMES = [
        'linux-amd64' => 'mirvmon-agent-linux-amd64',
        'windows-amd64' => 'mirvmon-agent-windows-amd64.exe',
        'windows-legacy-amd64' => 'mirvmon-agent-windows-legacy-amd64.exe',
    ];

    /** @param array<string, AgentArtifact> $artifacts */
    private function __construct(
        private readonly string $version,
        private readonly array $artifacts
    )
    {
    }

    public static function load(string $directory): self
    {
        $manifestPath = rtrim($directory, '/') . '/manifest.json';
        try {
            $manifest = json_decode(
                (string) file_get_contents($manifestPath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $exception) {
            throw new RuntimeException('Native agent artifact manifest is unavailable.', previous: $exception);
        }
        if (
            !is_array($manifest)
            || array_keys($manifest) !== ['version', 'artifacts']
            || !is_string($manifest['version'])
            || preg_match('/^v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/', $manifest['version']) !== 1
            || !is_array($manifest['artifacts'])
            || array_keys($manifest['artifacts']) !== array_keys(self::FILENAMES)
        ) {
            throw new RuntimeException('Native agent artifact manifest is invalid.');
        }

        $artifacts = [];
        foreach (self::FILENAMES as $key => $expectedFilename) {
            $value = $manifest['artifacts'][$key] ?? null;
            if (!is_array($value) || array_keys($value) !== ['filename', 'sha256', 'size', 'content_type']) {
                throw new RuntimeException('Native agent artifact manifest is invalid.');
            }
            $filename = $value['filename'] ?? null;
            $checksum = $value['sha256'] ?? null;
            $size = $value['size'] ?? null;
            $contentType = $value['content_type'] ?? null;
            if (
                $filename !== $expectedFilename
                || !is_string($checksum)
                || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1
                || !is_int($size)
                || $size < 1
                || $contentType !== 'application/octet-stream'
            ) {
                throw new RuntimeException('Native agent artifact manifest is invalid.');
            }
            $path = rtrim($directory, '/') . '/' . $filename;
            if (!is_file($path) || filesize($path) !== $size || !hash_equals($checksum, hash_file('sha256', $path) ?: '')) {
                throw new RuntimeException('Native agent artifact checksum is invalid.');
            }
            $artifacts[$key] = new AgentArtifact($key, $filename, $path, $checksum, $size, $contentType);
        }

        return new self($manifest['version'], $artifacts);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function require(string $key): AgentArtifact
    {
        if (!isset($this->artifacts[$key])) {
            throw new RuntimeException('Native agent artifact was not found.');
        }

        return $this->artifacts[$key];
    }
}
