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

    public function testLiveMetricRefreshPreservesTimestampViewport(): void
    {
        $script = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/servers/partials/detail-scripts.twig'
        );
        $metrics = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/servers/partials/detail-metrics.twig'
        );

        self::assertStringContainsString('data-live-fragment="server-metrics"', $metrics);
        self::assertStringContainsString('data-server-metrics-payload', $metrics);
        self::assertStringContainsString('captureMetricViewports()', $script);
        self::assertStringContainsString('metricViewportState', $script);
        self::assertStringContainsString('onZoomComplete', $script);
        self::assertStringContainsString('onPanComplete', $script);
        self::assertStringContainsString('rememberMetricViewport', $script);
        self::assertStringContainsString("metricZoomTriggers[canvasId] = trigger || 'zoom'", $script);
        self::assertStringContainsString("trigger === 'drag'", $script);
        self::assertStringContainsString("'fixed', true", $script);
        self::assertStringContainsString("viewport.mode === 'live'", $script);
        self::assertStringContainsString('lastIndex - Math.max(0, Math.round(viewport.minOffset))', $script);
        self::assertStringContainsString('timestamps[minIndex]', $script);
        self::assertStringContainsString("chart.zoomScale('x', { min, max }, 'none')", $script);
        self::assertStringContainsString("chart.update('none')", $script);
        self::assertGreaterThanOrEqual(3, substr_count($script, 'animation: false'));
        self::assertStringContainsString('metricInteractionActive()', $script);
        self::assertStringContainsString('mirvmon:live-fragment-before-update', $script);
        self::assertStringContainsString('mirvmon:live-fragment-updated', $script);
        self::assertStringNotContainsString('window.location.reload()', $script);
    }

    public function testThresholdSettingsKeepCanonicalDiskPaths(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/servers/edit.twig'
        );

        self::assertStringContainsString("disk_suffix == 'root' ? '/'", $template);
        self::assertStringContainsString("'/' ~ (disk_suffix|replace({'_': '/'}))", $template);
    }
}
