<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Bootstrap;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

final class BootstrapTest extends TestCase
{
    public function testMakesReleaseVersionAvailableToTemplates(): void
    {
        $container = Bootstrap::createContainer([
            'app_env' => 'test',
            'app_debug' => false,
            'app_key' => base64_encode(str_repeat('k', 32)),
            'setup_token' => str_repeat('s', 32),
            'timezone' => 'UTC',
            'trusted_proxies' => [],
            'session_name' => 'mirvmon_test',
            'session_secure' => false,
            'max_request_bytes' => 1024,
            'app_version' => 'v9.9.9',
            'templates_path' => dirname(__DIR__, 3) . '/templates',
            'twig_cache' => false,
        ], new PDO('sqlite::memory:'));

        /** @var Twig $twig */
        $twig = $container->get(Twig::class);

        self::assertSame('v9.9.9', $twig->getEnvironment()->getGlobals()['app_version'] ?? null);
        $twig->getEnvironment()->addGlobal('session', [
            'username' => 'admin',
            'role' => 'admin',
        ]);
        $twig->getEnvironment()->addGlobal('csrf', [
            'name_key' => 'csrf_name',
            'name' => 'csrf-name',
            'value_key' => 'csrf_value',
            'value' => 'csrf-value',
        ]);

        self::assertStringContainsString(
            'релиз v9.9.9',
            $twig->getEnvironment()->render('layout.twig', ['title' => 'Test'])
        );
    }
}
