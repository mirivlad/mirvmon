<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class RememberMeContractTest extends TestCase
{
    public function testRememberMeIsWiredThroughLoginAndSessionSecurity(): void
    {
        $root = dirname(__DIR__, 3);
        $controller = (string) file_get_contents($root . '/src/Controllers/AuthController.php');
        $middleware = (string) file_get_contents(
            $root . '/src/Middlewares/SessionSecurityMiddleware.php'
        );
        $template = (string) file_get_contents($root . '/templates/login.twig');

        self::assertStringContainsString('name="remember_me"', $template);
        self::assertStringContainsString("t('auth.login.remember')", $template);
        self::assertStringContainsString("\$body['remember_me']", $controller);
        self::assertStringContainsString("\$_SESSION['_remember_until']", $controller);
        self::assertStringContainsString('REMEMBER_ME_SECONDS = 2592000', $middleware);
        self::assertStringContainsString("\$_SESSION['_remember_until']", $middleware);
        self::assertStringContainsString("'; Max-Age='", $middleware);
        self::assertStringContainsString("'; Expires='", $middleware);
    }

    public function testProductionSessionStorageSurvivesContainerReplacement(): void
    {
        $root = dirname(__DIR__, 3);
        $phpIni = (string) file_get_contents($root . '/docker/php.ini');
        $entrypoint = (string) file_get_contents($root . '/docker/entrypoint.sh');
        $compose = (string) file_get_contents($root . '/docker/docker-compose.yml');

        self::assertStringContainsString(
            'session.save_path = /app/var/sessions',
            $phpIni
        );
        self::assertStringContainsString('session_directory=/app/var/sessions', $entrypoint);
        self::assertStringContainsString('mkdir -p "$session_directory"', $entrypoint);
        self::assertStringContainsString('app_data:/app/var', $compose);
    }
}
