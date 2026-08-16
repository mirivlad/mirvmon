<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

final class InlineAgentUpdateContractTest extends TestCase
{
    public function testServerListProvidesInlineUpdateControlsAndFiltering(): void
    {
        $root = dirname(__DIR__, 3);
        $template = (string) file_get_contents($root . '/templates/servers/index.twig');
        $factory = (string) file_get_contents($root . '/src/Application/AppFactory.php');
        $controller = (string) file_get_contents(
            $root . '/src/Controllers/AgentUpdateController.php'
        );
        $service = (string) file_get_contents(
            $root . '/src/Services/AgentUpdateService.php'
        );

        self::assertStringContainsString('data-agent-filter', $template);
        self::assertStringContainsString('value="outdated"', $template);
        self::assertStringContainsString("t('servers.filter.agent.outdated')", $template);
        self::assertStringContainsString('data-agent-update-button', $template);
        self::assertStringContainsString('/api/agent-updates/status?ids=', $template);
        self::assertStringContainsString('trackedServerIds', $template);
        self::assertStringContainsString('data-server-search', $template);
        self::assertStringContainsString('data-server-group-filter', $template);
        self::assertStringContainsString('data-server-status-filter', $template);
        self::assertStringContainsString('applyFilters();', $template);
        self::assertStringContainsString("'/api/agent-updates/status'", $factory);
        self::assertStringContainsString('function statuses(', $controller);
        self::assertStringContainsString("'is_outdated' =>", $service);
    }
}
