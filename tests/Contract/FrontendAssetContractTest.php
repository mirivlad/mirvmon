<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class FrontendAssetContractTest extends TestCase
{
    public function testTemplatesDoNotLoadRuntimeAssetsFromThirdPartyOrigins(): void
    {
        $templates = dirname(__DIR__, 2) . '/templates';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($templates)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $template = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression(
                '/(?:src|href)=["\']https?:\/\//i',
                $template,
                $file->getPathname()
            );
        }
    }

    public function testPinnedFrontendBundlesArePresent(): void
    {
        $root = dirname(__DIR__, 2);
        $package = json_decode(
            (string) file_get_contents($root . '/package.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('5.3.8', $package['devDependencies']['bootstrap']);
        self::assertSame('4.5.1', $package['devDependencies']['chart.js']);
        self::assertSame(
            '7.3.1',
            $package['devDependencies']['@fortawesome/fontawesome-free']
        );
        self::assertSame(
            '2.2.0',
            $package['devDependencies']['chartjs-plugin-zoom']
        );
        self::assertSame('2.0.8', $package['devDependencies']['hammerjs']);
        foreach ([
            'public/vendor/bootstrap/bootstrap.min.css',
            'public/vendor/bootstrap/bootstrap.bundle.min.js',
            'public/vendor/fontawesome/css/all.min.css',
            'public/vendor/fontawesome/webfonts/fa-solid-900.woff2',
            'public/vendor/chart.js/chart.umd.js',
            'public/vendor/chartjs-plugin-zoom/chartjs-plugin-zoom.min.js',
            'public/vendor/hammerjs/hammer.min.js',
        ] as $path) {
            self::assertFileExists($root . '/' . $path);
        }
    }

    public function testComposerVendorIgnoreDoesNotHideBrowserBundles(): void
    {
        $gitignore = (string) file_get_contents(
            dirname(__DIR__, 2) . '/.gitignore'
        );

        self::assertMatchesRegularExpression('/^\/vendor\/$/m', $gitignore);
        self::assertDoesNotMatchRegularExpression('/^vendor\/$/m', $gitignore);
    }
}
