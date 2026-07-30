<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TemplateSecurityContractTest extends TestCase
{
    #[DataProvider('templateProvider')]
    public function testEveryPostFormContainsServerGeneratedCsrfFields(string $path): void
    {
        $template = (string) file_get_contents($path);
        self::assertNotSame('', $template);
        preg_match_all(
            '/<form\b[^>]*method=["\']post["\'][^>]*>.*?<\/form>/is',
            $template,
            $forms
        );

        foreach ($forms[0] as $form) {
            self::assertTrue(
                str_contains($form, 'partials/csrf.twig')
                || (
                    str_contains($form, 'csrf.name_key')
                    && str_contains($form, 'csrf.value_key')
                ),
                $path . ' contains a POST form without server-generated CSRF fields.'
            );
        }
    }

    public function testTemplatesDoNotFetchCsrfTokensOrUseGetForMutations(): void
    {
        foreach (self::templateProvider() as [$path]) {
            $template = (string) file_get_contents($path);
            self::assertStringNotContainsString("fetch('/csrf-token')", $template, $path);
            self::assertDoesNotMatchRegularExpression(
                '#href=["\'][^"\']+/(?:delete|resolve|regenerate-token)(?:["\']|\?)#',
                $template,
                $path
            );
            self::assertDoesNotMatchRegularExpression(
                '#href=["\']/admin/notifications/test["\']#',
                $template,
                $path
            );
        }
    }

    public function testExecutableInlineScriptsAreNoncedAndEventAttributesAreAbsent(): void
    {
        foreach (self::templateProvider() as [$path]) {
            $template = (string) file_get_contents($path);
            self::assertDoesNotMatchRegularExpression(
                '/\son(?:click|change|submit|load|error)\s*=/i',
                $template,
                $path
            );
            preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/i', $template, $scripts);
            foreach ($scripts[1] as $attributes) {
                self::assertStringContainsString(
                    'nonce="__MIRVMON_CSP_NONCE__"',
                    $attributes,
                    $path
                );
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function templateProvider(): iterable
    {
        $root = dirname(__DIR__, 2) . '/templates';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'twig') {
                yield $file->getPathname() => [$file->getPathname()];
            }
        }
    }
}
