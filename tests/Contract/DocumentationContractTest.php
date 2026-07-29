<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class DocumentationContractTest extends TestCase
{
    public function testReadmeDescribesTheCurrentRuntimeAndHasNoBootstrapCredentials(): void
    {
        $root = dirname(__DIR__, 2);
        $readme = implode("\n", array_map(
            static fn (string $file): string => (string) file_get_contents($root . '/' . $file),
            ['README.md', 'INSTALL.md', 'ARCHITECTURE.md', 'TECHNICAL_SPECIFICATION.md']
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
    }
}
