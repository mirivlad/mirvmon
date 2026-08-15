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

        foreach (['online', 'warning', 'critical', 'offline'] as $status) {
            self::assertStringContainsString($status . ': \'var(--app-', $partial);
        }
        self::assertStringContainsString('color:#fff', $partial);
        self::assertStringContainsString('border-radius:50%', $partial);

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
        $template = (string) file_get_contents($root . '/templates/servers/detail.twig');

        self::assertMatchesRegularExpression(
            '/\{% if displayMetrics\|filter\(v => v starts with \'temp_\'\)\|length > 0 %\}.*?Температуры.*?\{% endif %\}/s',
            $template
        );
        self::assertStringContainsString(
            'По выбранным температурным метрикам за этот период нет данных.',
            $template
        );
    }
}
