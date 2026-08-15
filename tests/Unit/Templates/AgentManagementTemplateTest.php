<?php

declare(strict_types=1);

namespace Tests\Unit\Templates;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AgentManagementTemplateTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new FilesystemLoader(
            dirname(__DIR__, 3) . '/templates'
        ));
        TwigTranslation::register($this->twig, new Translator());
    }

    public function testAdministratorCanRequestAvailableUpdate(): void
    {
        $html = $this->render([
            'installed_version' => 'v0.4.2',
            'available_version' => 'v0.4.3',
            'state' => 'update_available',
            'can_update' => true,
            'command' => null,
        ], 'admin');

        self::assertStringContainsString('Установлена', $html);
        self::assertStringContainsString('v0.4.2', $html);
        self::assertStringContainsString('Доступна', $html);
        self::assertStringContainsString('v0.4.3', $html);
        self::assertStringContainsString('action="/servers/7/agent/update"', $html);
        self::assertStringContainsString('Обновить агент', $html);
    }

    public function testNonAdministratorSeesStateWithoutUpdateControl(): void
    {
        $html = $this->render([
            'installed_version' => 'v0.4.2',
            'available_version' => 'v0.4.3',
            'state' => 'manual_required',
            'can_update' => false,
            'command' => null,
        ], 'user');

        self::assertStringContainsString('Требуется ручное обновление', $html);
        self::assertStringNotContainsString('/agent/update', $html);
    }

    /** @param array<string, mixed> $update */
    private function render(array $update, string $role): string
    {
        return $this->twig->render('servers/partials/agent-management.twig', [
            'server' => ['id' => 7],
            'session' => ['role' => $role],
            'csrf' => [
                'name_key' => 'csrf_name',
                'name' => 'name-token',
                'value_key' => 'csrf_value',
                'value' => 'value-token',
            ],
            'has_agent_token' => true,
            'requires_token_rotation' => false,
            'agent_update' => $update,
        ]);
    }
}
