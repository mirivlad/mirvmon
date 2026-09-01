<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class Ui19TemplateContractTest extends TestCase
{
    public function testPrimaryNavigationExposesActiveRouteMarkers(): void
    {
        $layout = $this->contents('templates/layout.twig');

        self::assertStringContainsString('data-nav-exact="/"', $layout);
        self::assertStringContainsString('data-nav-prefix="/groups"', $layout);
        self::assertStringContainsString('data-nav-prefix="/servers"', $layout);
        self::assertStringContainsString('data-nav-prefix="/alerts"', $layout);
        self::assertStringContainsString('data-nav-section="settings"', $layout);
        self::assertStringContainsString('/js/ui19.js', $layout);
    }

    public function testServerListUsesOperationalFiltersAndKeepsInlineAgentUpdates(): void
    {
        $template = $this->contents('templates/servers/index.twig');

        self::assertStringContainsString('data-server-search', $template);
        self::assertStringContainsString('data-server-group-filter', $template);
        self::assertStringContainsString('data-server-status-filter', $template);
        self::assertStringContainsString('data-agent-filter', $template);
        self::assertStringContainsString('advanced-server-filters', $template);
        self::assertStringContainsString('data-agent-update-button', $template);
        self::assertStringContainsString('data-agent-bulk-update-button', $template);
        self::assertStringContainsString('renderBulkProgress()', $template);
        self::assertStringContainsString('bulkOperationServerIds', $template);
        self::assertStringContainsString('agent.update_all_progress', $template);
        self::assertStringContainsString('/api/agent-updates/status', $template);
    }

    public function testGroupPagesUseMiniDashboardAndResponsiveListContracts(): void
    {
        $index = $this->contents('templates/groups/index.twig');
        $show = $this->contents('templates/groups/show.twig');
        $css = $this->contents('public/css/ui19.css');

        self::assertStringContainsString('group-dashboard-grid', $index);
        self::assertStringContainsString('group-status-strip', $index);
        self::assertStringContainsString('group-summary-grid', $show);
        self::assertStringContainsString('@media (max-width: 767.98px)', $css);
        self::assertStringContainsString('.server-list-table thead', $css);
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents);
        return $contents;
    }
}
