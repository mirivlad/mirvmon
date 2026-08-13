<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class WindowsReleaseContractTest extends TestCase
{
    public function testNsisCompilerIsRequiredInRuntimeAndCi(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = (string) file_get_contents($root . '/composer.json');
        $dockerfile = (string) file_get_contents($root . '/docker/Dockerfile');
        $entrypoint = (string) file_get_contents($root . '/docker/entrypoint.sh');
        $ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');

        self::assertStringNotContainsString('"ext-zip": "*"', $composer);
        self::assertMatchesRegularExpression('/apt-get install[\s\S]*?\n\s+nsis\b/', $dockerfile);
        self::assertStringContainsString('command -v makensis', $entrypoint);
        self::assertMatchesRegularExpression('/apt-get install[^\n]*\bnsis\b/', $ci);
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
