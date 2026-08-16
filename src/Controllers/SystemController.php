<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\AppSettingsRepository;
use App\Repositories\AuditLogRepository;
use App\Services\AuditLogger;
use App\Services\SystemHealthService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

final class SystemController
{
    private readonly AuditLogger $audit;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly AppSettingsRepository $settings,
        private readonly SystemHealthService $health,
        private readonly Translator $translator = new Translator(),
        ?AuditLogger $audit = null
    ) {
        $this->audit = $audit ?? new AuditLogger(new AuditLogRepository($pdo));
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

        $before = $this->health->selectedHostId();
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['server_id'] ?? null) : null;
        if ($value === null || $value === '') {
            try {
                $this->settings->set(SystemHealthService::HOST_SETTING, null);
                if ($before !== null) {
                    $this->recordAudit(
                        'system.host.clear',
                        null,
                        null,
                        $this->translator->trans('audit.event.system.host_cleared'),
                        ['server_id' => null]
                    );
                }
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
                'SELECT name, is_active FROM servers WHERE id = :id'
            );
            $statement->execute(['id' => $serverId]);
            $server = $statement->fetch();
            if (!is_array($server)) {
                $this->flash('system.host.not_found', 'error');
                return $this->redirect($response, '/admin/system');
            }
            if (!$this->toBool($server['is_active'] ?? false)) {
                $this->flash('system.host.inactive', 'error');
                return $this->redirect($response, '/admin/system');
            }

            $this->settings->set(SystemHealthService::HOST_SETTING, $serverId);
            if ($before !== (int) $serverId) {
                $this->recordAudit(
                    'system.host.save',
                    (int) $serverId,
                    (string) $server['name'],
                    $this->translator->trans('audit.event.system.host_saved'),
                    ['server_id' => (int) $serverId]
                );
            }
            $this->flash('system.host.saved', 'success');
        } catch (Throwable) {
            $this->flash('system.host.save_failed', 'error');
        }

        return $this->redirect($response, '/admin/system');
    }

    /** @param array<string, mixed> $metadata */
    private function recordAudit(
        string $action,
        ?int $objectId,
        ?string $objectLabel,
        string $description,
        array $metadata
    ): void {
        try {
            $this->audit->record(
                $action,
                'system',
                $objectId,
                $objectLabel,
                $description,
                $metadata
            );
        } catch (Throwable $exception) {
            error_log('[mirvmon][audit][system-host] ' . $exception->getMessage());
        }
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
