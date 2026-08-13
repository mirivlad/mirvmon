<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class LegacyWindowsReleaseContractTest extends TestCase
{
    public function testZipExtensionIsRequiredInEveryPhpRuntime(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = (string) file_get_contents($root . '/composer.json');
        $dockerfile = (string) file_get_contents($root . '/docker/Dockerfile');
        $entrypoint = (string) file_get_contents($root . '/docker/entrypoint.sh');
        $ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');

        self::assertStringContainsString('"ext-zip": "*"', $composer);
        self::assertMatchesRegularExpression('/install-php-extensions[\s\S]*?\n\s+zip\b/', $dockerfile);
        self::assertStringContainsString('"zip"', $entrypoint);
        self::assertMatchesRegularExpression('/extensions: [^\n]*\bzip\b/', $ci);
    }

    public function testLegacyBuildKeepsItsSupportedGoBoundary(): void
    {
        $root = dirname(__DIR__, 3);
        $dockerfile = (string) file_get_contents($root . '/docker/Dockerfile');
        $ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');

        self::assertStringContainsString('FROM golang:1.20.14-bookworm@sha256:', $dockerfile);
        self::assertStringContainsString('FROM golang:1.26.5-bookworm@sha256:', $dockerfile);
        self::assertMatchesRegularExpression(
            '/FROM golang:1\.20\.14[\s\S]*?CGO_ENABLED=0 GOOS=windows GOARCH=amd64 GOAMD64=v1[\s\S]*?windows-legacy-amd64/',
            $dockerfile
        );
        self::assertStringContainsString('go: "1.20.14"', $ci);
        self::assertStringContainsString('go: "1.26.5"', $ci);
    }
}
