<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class DocumentationContractTest extends TestCase
{
    public function testRepositoryDeclaresTheMITLicense(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root . '/LICENSE');

        $license = (string) file_get_contents($root . '/LICENSE');
        $composer = json_decode(
            (string) file_get_contents($root . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertStringContainsString('MIT License', $license);
        self::assertStringContainsString('Copyright (c) 2026 MirvMon contributors', $license);
        self::assertSame('MIT', $composer['license']);
    }

    public function testReadmeReferencesCompleteLocalScreenshotGallery(): void
    {
        $root = dirname(__DIR__, 2);
        $readme = (string) file_get_contents($root . '/README.md');

        self::assertMatchesRegularExpression('/## Интерфейс\n\n.*?(?=\n## |\z)/s', $readme);

        foreach (['dashboard', 'server-detail', 'groups', 'notification-settings'] as $name) {
            $relativePath = 'docs/screenshots/' . $name . '.webp';
            $imagePath = $root . '/' . $relativePath;

            self::assertFileExists($imagePath);

            $image = getimagesize($imagePath);
            self::assertNotFalse($image);
            self::assertSame(IMAGETYPE_WEBP, $image[2]);
            self::assertGreaterThan(0, $image[0]);
            self::assertGreaterThan(0, $image[1]);
            self::assertStringContainsString('href="' . $relativePath . '"', $readme);
            self::assertStringContainsString('src="' . $relativePath . '"', $readme);
        }
    }

    public function testReadmeDescribesTheCurrentRuntimeAndHasNoBootstrapCredentials(): void
    {
        $root = dirname(__DIR__, 2);
        $readme = implode("\n", array_map(
            static fn (string $file): string => (string) file_get_contents($root . '/' . $file),
            [
                'README.md',
                'INSTALL.md',
                'ARCHITECTURE.md',
                'TECHNICAL_SPECIFICATION.md',
                'AGENTS.md',
                'docker/README.md',
            ]
        ));

        self::assertStringContainsString('PHP 8.5', $readme);
        self::assertStringContainsString('TimescaleDB', $readme);
        self::assertStringContainsString('FrankenPHP', $readme);
        self::assertStringContainsString('SETUP_TOKEN', $readme);
        self::assertStringContainsString('внешний nginx', $readme);
        self::assertStringNotContainsString('MariaDB', $readme);
        self::assertStringNotContainsString('mirvmon2026', $readme);
        self::assertStringNotContainsString('mon.mirv.top', $readme);
        self::assertStringNotContainsString('php8.3', $readme);
        self::assertStringNotContainsString('DATABASE_URL', $readme);
        self::assertStringNotContainsString('vendor/bin/phinx', $readme);
        self::assertStringNotContainsString('mysqldump', $readme);
    }

    public function testReleaseDocumentationMapsGitAndDockerTagsExplicitly(): void
    {
        $root = dirname(__DIR__, 2);
        $install = (string) file_get_contents($root . '/INSTALL.md');
        $dockerReadme = (string) file_get_contents($root . '/docker/README.md');

        self::assertStringContainsString(
            'Git tag `vX.Y.Z` соответствует Docker image tag `X.Y.Z`.',
            $install
        );
        self::assertStringContainsString(
            '`v0.1.0` requires `MIRVMON_IMAGE=ghcr.io/mirivlad/mirvmon:0.1.0`.',
            $dockerReadme
        );
        self::assertStringNotContainsString(
            'same immutable release tag as the repository reference',
            $dockerReadme
        );
    }

    public function testWebsiteMonitoringOperationalDocsArePresent(): void
    {
        $root = dirname(__DIR__, 2);
        $docs = implode("\n", array_map(
            static fn (string $file): string => (string) file_get_contents($root . '/' . $file),
            ['README.md', 'ARCHITECTURE.md', 'TECHNICAL_SPECIFICATION.md', 'INSTALL.md', 'docker/README.md', 'docs/use-cases.md', 'docs/troubleshooting.md']
        ));

        foreach (['website-check-worker', '30 days', '365 days', 'self-signed', 'RDAP', 'WHOIS', '390'] as $needle) {
            self::assertStringContainsString($needle, $docs);
        }
        self::assertTrue(is_executable($root . '/bin/benchmark-websites'));
    }

    public function testWebsiteWorkerIntervalIsConfiguredOnlyInBothEnvironmentExamples(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['.env.example', 'docker/.env.example'] as $file) {
            $contents = (string) file_get_contents($root . '/' . $file);
            self::assertStringContainsString('WEBSITE_CHECK_LOOP_INTERVAL', $contents);
        }
    }
}
