<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class WebsiteSecurityModelContractTest extends TestCase
{
    public function testTrustedAdminWebsiteNetworkModelIsDocumentedConsistently(): void
    {
        $root = dirname(__DIR__, 2);
        $install = (string) file_get_contents($root . '/INSTALL.md');
        $spec = (string) file_get_contents($root . '/TECHNICAL_SPECIFICATION.md');
        $routes = (string) file_get_contents($root . '/src/Application/AppFactory.php');

        self::assertStringContainsString('не является tenant/SSRF isolation boundary', $spec);
        self::assertStringContainsString('Это не SSRF-граница', $install);
        self::assertStringNotContainsString('блокирует loopback, private/link-local', $install);
        self::assertStringContainsString("/sites', self::controller(\$container, WebsiteController::class, 'store'))->add(\$admin)", $routes);
        self::assertStringContainsString("/sites/{id}', self::controller(\$container, WebsiteController::class, 'update'))->add(\$admin)", $routes);
    }
}
