<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class ServerPresentationContractTest extends TestCase
{
    public function testSharedStatusIconUsesHighContrastStateCircleEverywhere(): void
    {
        $root = dirname(__DIR__, 3);
        $partial = (string) file_get_contents(
            $root . '/templates/partials/server-status-icon.twig'
        );
        $layout = (string) file_get_contents($root . '/templates/layout.twig');

        self::assertStringContainsString('server-status-emblem', $partial);
        self::assertStringContainsString('color: #fff !important', $layout);
        self::assertStringContainsString('border-radius: 50% !important', $layout);
        foreach (['online', 'warning', 'critical', 'offline'] as $status) {
            $expected = $status === 'online'
                ? 'background: var(--app-online) !important'
                : '.server-status-' . $status . ' .server-status-emblem';
            self::assertStringContainsString($expected, $layout);
        }

        foreach ([
            'templates/dashboard.twig',
            'templates/servers/index.twig',
            'templates/servers/detail.twig',
        ] as $template) {
            self::assertStringContainsString(
                'partials/server-status-icon.twig',
                (string) file_get_contents($root . '/' . $template),
                $template . ' must keep using the shared status icon partial.'
            );
        }
    }

    public function testTemperaturePanelRequiresSelectedTemperatureMetric(): void
    {
        $root = dirname(__DIR__, 3);
        $template = (string) file_get_contents(
            $root . '/templates/servers/partials/detail-metrics.twig'
        );

        self::assertMatchesRegularExpression(
            '/\{% if displayMetrics\|filter\(v => v starts with \'temp_\'\)\|length > 0 %\}.*?t\(\'server\.temperature\.title\'\).*?\{% endif %\}/s',
            $template
        );
        self::assertStringContainsString(
            "t('server.temperature.no_data')",
            $template
        );
    }
}
