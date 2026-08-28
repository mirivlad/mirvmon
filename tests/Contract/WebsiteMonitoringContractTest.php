<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class WebsiteMonitoringContractTest extends TestCase
{
    public function testWebsiteUiAssetsAndTemplatesExist(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            '/src/Controllers/WebsiteController.php',
            '/templates/sites/index.twig',
            '/templates/sites/form.twig',
            '/templates/sites/create.twig',
            '/templates/sites/edit.twig',
            '/public/js/sites-form.js',
            '/translations/ru.websites.php',
            '/translations/en.websites.php',
        ] as $path) {
            self::assertFileExists($root . $path, $path);
        }
    }

    public function testWebsiteFormDoesNotRenderSecretValues(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/sites/form.twig');
        self::assertStringContainsString('auth_secret', $form);
        self::assertStringContainsString('headers_text', $form);
        self::assertStringNotContainsString('value="{{ endpoint.auth_secret', $form);
        self::assertStringNotContainsString('value="{{ endpoint.headers', $form);
    }
}
