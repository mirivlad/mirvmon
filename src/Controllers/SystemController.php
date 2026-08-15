<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\AppSettingsRepository;
use App\Services\SystemHealthService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

final class SystemController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly AppSettingsRepository $settings,
        private readonly SystemHealthService $health,
        private readonly Translator $translator = new Translator()
    ) {
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }

        $servers = $this->pdo->query(
            'SELECT id, name, address, is_active FROM servers ORDER BY name, id'
        )?->fetchAll() ?: [];
        foreach ($servers as &$server) {
            $server['id'] = (int) $server['id'];
            $server['is_active'] = $this->toBool($server['is_active'] ?? false);
        }
        unset($server);

        return $this->twig->render($response, 'admin/system.twig', [
            'title' => $this->translator->trans('system.title'),
            'system' => $this->health->details(),
            'servers' => $servers,
            'selected_host_id' => $this->health->selectedHostId(),
        ]);
    }

    /** @param array<string, string> $args */
    public function saveHost(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->redirect($response, '/');
        }

        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['server_id'] ?? null) : null;
        if ($value === null || $value === '') {
            try {
                $this->settings->set(SystemHealthService::HOST_SETTING, null);
                $this->flash('system.host.cleared', 'success');
            } catch (Throwable) {
                $this->flash('system.host.save_failed', 'error');
            }
            return $this->redirect($response, '/admin/system');
        }

        $serverId = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($serverId === false) {
            $this->flash('system.host.invalid', 'error');
            return $this->redirect($response, '/admin/system');
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT is_active FROM servers WHERE id = :id'
            );
            $statement->execute(['id' => $serverId]);
            $active = $statement->fetchColumn();
            if ($active === false) {
                $this->flash('system.host.not_found', 'error');
                return $this->redirect($response, '/admin/system');
            }
            if (!$this->toBool($active)) {
                $this->flash('system.host.inactive', 'error');
                return $this->redirect($response, '/admin/system');
            }

            $this->settings->set(SystemHealthService::HOST_SETTING, $serverId);
            $this->flash('system.host.saved', 'success');
        } catch (Throwable) {
            $this->flash('system.host.save_failed', 'error');
        }

        return $this->redirect($response, '/admin/system');
    }

    private function isAdmin(): bool
    {
        return ($_SESSION['role'] ?? null) === 'admin';
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    private function flash(string $key, string $type): void
    {
        $_SESSION['flash_message'] = $this->translator->trans($key);
        $_SESSION['flash_type'] = $type;
    }

    private function toBool(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array($value, ['1', 't', 'true'], true);
    }
}
