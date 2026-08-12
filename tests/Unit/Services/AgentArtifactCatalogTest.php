<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AgentArtifactCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentArtifactCatalogTest extends TestCase
{
    public function testLoadsOnlyExactVerifiedArtifacts(): void
    {
        $directory = $this->artifactDirectory([
            'linux-amd64' => [
                'filename' => 'mirvmon-agent-linux-amd64',
                'content' => 'linux',
            ],
        ]);

        $catalog = AgentArtifactCatalog::load($directory);
        $artifact = $catalog->require('linux-amd64');

        self::assertSame(hash('sha256', 'linux'), $artifact->sha256);
        self::assertSame($directory . '/mirvmon-agent-linux-amd64', $artifact->path);

        $this->expectException(RuntimeException::class);
        $catalog->require('../../.env');
    }

    public function testRejectsManifestIntegrityAndAllowlistViolations(): void
    {
        $directory = $this->artifactDirectory();
        $manifest = json_decode(
            (string) file_get_contents($directory . '/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $manifest['artifacts']['linux-amd64']['filename'] = '../agent';
        file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        AgentArtifactCatalog::load($directory);
    }

    /** @param array<string, array{filename: string, content: string}> $overrides */
    private function artifactDirectory(array $overrides = []): string
    {
        $directory = sys_get_temp_dir() . '/mirvmon-artifacts-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $artifacts = [
            'linux-amd64' => [
                'filename' => 'mirvmon-agent-linux-amd64',
                'content' => 'default-linux',
            ],
            'windows-amd64' => [
                'filename' => 'mirvmon-agent-windows-amd64.exe',
                'content' => 'default-windows',
            ],
            'windows-legacy-amd64' => [
                'filename' => 'mirvmon-agent-windows-legacy-amd64.exe',
                'content' => 'default-legacy-windows',
            ],
        ];
        foreach ($overrides as $key => $value) {
            $artifacts[$key] = $value;
        }
        $manifest = ['artifacts' => []];
        foreach ($artifacts as $key => $artifact) {
            file_put_contents($directory . '/' . $artifact['filename'], $artifact['content']);
            $manifest['artifacts'][$key] = [
                'filename' => $artifact['filename'],
                'sha256' => hash('sha256', $artifact['content']),
                'content_type' => 'application/octet-stream',
            ];
        }
        file_put_contents(
            $directory . '/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );

        return $directory;
    }
}
