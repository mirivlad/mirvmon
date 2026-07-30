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
}
