<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalizationContractTest extends TestCase
{
    public function testRussianAndEnglishCatalogsHaveExactlyTheSameKeys(): void
    {
        $root = dirname(__DIR__, 3) . '/translations';
        $ru = $this->catalog($root, 'ru');
        $en = $this->catalog($root, 'en');

        self::assertNotEmpty($ru);
        self::assertSame(array_keys($ru), array_keys($en));
    }

    public function testRussianIsTheDefaultLocale(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/I18n/Translator.php'
        );

        self::assertStringContainsString(
            "public const DEFAULT_LOCALE = 'ru';",
            $source
        );
        self::assertStringContainsString(
            "public const SUPPORTED_LOCALES = ['ru', 'en'];",
            $source
        );
    }

    #[DataProvider('localizedTemplateProvider')]
    public function testPrimaryUiTemplatesUseTranslationLayer(string $path): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);

        self::assertStringContainsString(
            "t('",
            $content,
            $path . ' must use translation keys rather than being a language-specific island.'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function localizedTemplateProvider(): iterable
    {
        foreach ([
            'templates/layout.twig',
            'templates/login-layout.twig',
            'templates/login.twig',
            'templates/setup.twig',
            'templates/dashboard.twig',
            'templates/alerts/index.twig',
            'templates/groups/index.twig',
            'templates/groups/create.twig',
            'templates/groups/edit.twig',
            'templates/groups/show.twig',
            'templates/servers/index.twig',
            'templates/servers/create.twig',
            'templates/servers/edit.twig',
            'templates/servers/created.twig',
            'templates/servers/detail.twig',
            'templates/servers/partials/agent-management.twig',
            'templates/admin/defaults.twig',
            'templates/admin/users.twig',
            'templates/admin/notifications.twig',
            'templates/admin/notification-queue.twig',
        ] as $path) {
            yield $path => [$path];
        }
    }

    /** @return array<string, string> */
    private function catalog(string $root, string $locale): array
    {
        $paths = glob($root . '/' . $locale . '*.php');
        self::assertIsArray($paths);
        sort($paths, SORT_STRING);

        $catalog = [];
        foreach ($paths as $path) {
            $fragment = require $path;
            self::assertIsArray($fragment, basename($path));
            foreach ($fragment as $key => $value) {
                self::assertIsString($key);
                self::assertIsString($value, $key);
                self::assertArrayNotHasKey(
                    $key,
                    $catalog,
                    'Duplicate translation key: ' . $key
                );
                $catalog[$key] = $value;
            }
        }
        ksort($catalog, SORT_STRING);
        return $catalog;
    }
}
