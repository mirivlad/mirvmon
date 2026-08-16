<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class ServerMetricScriptSafetyContractTest extends TestCase
{
    public function testProcessTooltipTreatsAgentStringsAsText(): void
    {
        $script = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/servers/partials/detail-scripts.twig'
        );

        self::assertStringContainsString('tooltipEl.textContent', $script);
        self::assertStringNotContainsString('tooltipEl.innerHTML', $script);
        self::assertStringContainsString('proc.command || proc.name', $script);
    }

    public function testUptimeChartUsesLocalizedUnits(): void
    {
        $script = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/servers/partials/detail-scripts.twig'
        );

        foreach (['common.days', 'common.hours', 'common.minutes'] as $key) {
            self::assertStringContainsString("t('{$key}')", $script);
        }
        self::assertStringContainsString('detailText.days', $script);
        self::assertStringContainsString('detailText.hours', $script);
        self::assertStringContainsString('detailText.minutes', $script);
    }
}
