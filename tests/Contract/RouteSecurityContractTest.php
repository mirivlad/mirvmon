<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Application\AppFactory;
use App\Application\Bootstrap;
use PDO;
use PHPUnit\Framework\TestCase;

final class RouteSecurityContractTest extends TestCase
{
    public function testMutationRoutesArePostOnlyAndLegacyUtilitiesAreAbsent(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $container = Bootstrap::createContainer([
            'app_env' => 'test',
            'app_debug' => false,
            'app_key' => base64_encode(str_repeat('k', 32)),
            'setup_token' => str_repeat('s', 32),
            'timezone' => 'UTC',
            'trusted_proxies' => [],
            'session_name' => 'mirvmon_routes',
            'session_secure' => false,
            'max_request_bytes' => 1024,
            'templates_path' => dirname(__DIR__, 2) . '/templates',
            'twig_cache' => false,
        ], $pdo);

        $routes = [];
        foreach (AppFactory::create($container)->getRouteCollector()->getRoutes() as $route) {
            $routes[$route->getPattern()] = array_values(array_unique([
                ...($routes[$route->getPattern()] ?? []),
                ...$route->getMethods(),
            ]));
        }

        foreach ([
            '/logout',
            '/groups/{id}/delete',
            '/servers/{id}/delete',
            '/servers/{id}/regenerate-token',
            '/alerts/{id}/resolve',
            '/admin/users/{id}/delete',
            '/admin/notifications/test',
            '/admin/notifications/queue/retry',
            '/admin/notifications/queue/{id}/retry',
            '/admin/notifications/queue/{id}/delete',
            '/admin/notifications/queue/delete',
            '/servers/{id}/maintenance',
            '/servers/{id}/maintenance/cancel',
            '/sites/{id}/delete',
            '/sites/{id}/pause',
            '/sites/{id}/resume',
            '/sites/{id}/check',
            '/sites/{id}/settings',
            '/sites/{id}/maintenance',
            '/sites/{id}/maintenance/cancel',
        ] as $pattern) {
            self::assertSame(['POST'], $routes[$pattern] ?? null, $pattern);
        }
        self::assertContains('GET', $routes['/sites/{id}'] ?? []);
        self::assertContains('POST', $routes['/sites/{id}'] ?? []);
        self::assertSame(['GET'], $routes['/api/sites/{id}/metrics'] ?? null);
        self::assertSame(['GET'], $routes['/api/sites/{id}/status'] ?? null);

        self::assertContains('GET', $routes['/sites'] ?? []);
        self::assertContains('POST', $routes['/sites'] ?? []);
        self::assertSame(['GET'], $routes['/sites/create'] ?? null);
        self::assertSame(['GET'], $routes['/sites/{id}/edit'] ?? null);

        self::assertSame(
            ['GET'],
            $routes['/admin/notifications/queue'] ?? null
        );

        self::assertArrayNotHasKey('/csrf-token', $routes);
        self::assertArrayNotHasKey('/test', $routes);
        self::assertArrayNotHasKey('/api/status', $routes);
        self::assertSame(['GET'], $routes['/api/v1/agent/{id}/processes'] ?? null);
        self::assertSame(['POST'], $routes['/api/v1/agent/install'] ?? null);
        self::assertSame(['GET'], $routes['/agent/install.exe'] ?? null);
        foreach ([
            '/agent/install.ps1',
            '/agent/install.bat',
            '/agent/install-legacy.zip',
            '/agent/install-legacy.ps1',
            '/agent/install-legacy.bat',
        ] as $removedInstaller) {
            self::assertArrayNotHasKey($removedInstaller, $routes);
        }
    }
}
