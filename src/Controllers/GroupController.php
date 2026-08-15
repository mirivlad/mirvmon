<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\Services\ServerStatusService;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

final class GroupController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly ServerStatusService $status,
        private readonly Translator $translator
    ) {
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        $groups = $this->pdo->query(
            <<<'SQL'
            SELECT groups.*, count(servers.id) AS server_count
            FROM server_groups AS groups
            LEFT JOIN servers ON servers.group_id = groups.id
            GROUP BY groups.id
            ORDER BY groups.sort_order, groups.name, groups.id
            SQL
        )?->fetchAll() ?? [];

        return $this->twig->render($response, 'groups/index.twig', [
            'title' => $this->translator->trans('groups.title'),
            'groups' => $groups,
        ]);
    }

    /** @param array<string, string> $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        return $this->twig->render($response, 'groups/create.twig', [
            'title' => $this->translator->trans('groups.create'),
        ]);
    }

    /** @param array<string, string> $args */
    public function store(Request $request, Response $response, array $args): Response
    {
        try {
            $group = $this->validatedGroup($request->getParsedBody());
            $statement = $this->pdo->prepare(
                'INSERT INTO server_groups (name, description, icon, color)
                 VALUES (:name, :description, :icon, :color)'
            );
            $statement->execute($group);
            $this->flashKey('group.flash.created', 'success');
            return $this->redirect($response, '/groups');
        } catch (InvalidArgumentException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->flashKey('group.flash.create_failed', 'error');
        }
        return $this->redirect($response, '/groups/create');
    }

    /** @param array<string, string> $args */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $group = $this->find($this->groupId($args));
        if ($group === null) {
            $this->flashKey('group.flash.not_found', 'error');
            return $this->redirect($response, '/groups');
        }
        return $this->twig->render($response, 'groups/edit.twig', [
            'title' => $this->translator->trans('common.edit'),
            'group' => $group,
        ]);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $groupId = $this->groupId($args);
        if ($groupId === null || $this->find($groupId) === null) {
            $this->flashKey('group.flash.not_found', 'error');
            return $this->redirect($response, '/groups');
        }
        try {
            $group = $this->validatedGroup($request->getParsedBody());
            $statement = $this->pdo->prepare(
                'UPDATE server_groups
                 SET name = :name, description = :description, icon = :icon, color = :color
                 WHERE id = :id'
            );
            $statement->execute(['id' => $groupId, ...$group]);
            $this->flashKey('group.flash.updated', 'success');
            return $this->redirect($response, '/groups');
        } catch (InvalidArgumentException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->flashKey('group.flash.update_failed', 'error');
        }
        return $this->redirect($response, '/groups/' . $groupId . '/edit');
    }

    /** @param array<string, string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $groupId = $this->groupId($args);
        if ($groupId === null) {
            $this->flashKey('group.flash.not_found', 'error');
            return $this->redirect($response, '/groups');
        }
        try {
            $statement = $this->pdo->prepare('DELETE FROM server_groups WHERE id = :id');
            $statement->execute(['id' => $groupId]);
            $deleted = $statement->rowCount() === 1;
            $this->flashKey(
                $deleted ? 'group.flash.deleted' : 'group.flash.not_found',
                $deleted ? 'success' : 'error'
            );
        } catch (Throwable) {
            $this->flashKey('group.flash.delete_failed', 'error');
        }
        return $this->redirect($response, '/groups');
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $group = $this->find($this->groupId($args));
        if ($group === null) {
            $this->flashKey('group.flash.not_found', 'error');
            return $this->redirect($response, '/groups');
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                servers.id,
                servers.name,
                servers.address,
                servers.description,
                servers.is_active,
                servers.last_metrics_at,
                max(agent_tokens.last_used_at) AS last_contact_at,
                servers.offline_timeout_seconds,
                count(alerts.id) FILTER (WHERE alerts.severity = 'warning') AS warning_alerts,
                count(alerts.id) FILTER (WHERE alerts.severity = 'critical') AS critical_alerts
            FROM servers
            LEFT JOIN agent_tokens ON agent_tokens.server_id = servers.id
            LEFT JOIN alerts ON alerts.server_id = servers.id AND alerts.resolved = FALSE
            WHERE servers.group_id = :group_id
            GROUP BY servers.id
            ORDER BY servers.name, servers.id
            SQL
        );
        $statement->execute(['group_id' => $group['id']]);
        $servers = $this->status->enrich($statement->fetchAll());

        return $this->twig->render($response, 'groups/show.twig', [
            'title' => (string) $group['name'],
            'group' => $group,
            'servers' => $servers,
        ]);
    }

    /** @param array<string, string> $args */
    private function groupId(array $args): ?int
    {
        $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    /** @return array<string, mixed>|null */
    private function find(?int $groupId): ?array
    {
        if ($groupId === null) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT * FROM server_groups WHERE id = :id');
        $statement->execute(['id' => $groupId]);
        $group = $statement->fetch();
        return is_array($group) ? $group : null;
    }

    /** @return array{name: string, description: ?string, icon: string, color: string} */
    private function validatedGroup(mixed $body): array
    {
        $body = is_array($body) ? $body : [];
        $name = trim((string) ($body['name'] ?? ''));
        if (preg_match('/^.{1,100}$/us', $name) !== 1 || preg_match('/\S/u', $name) !== 1) {
            throw new InvalidArgumentException($this->translator->trans('group.validation.name'));
        }
        $description = trim((string) ($body['description'] ?? ''));
        if (preg_match('/^.{0,5000}$/us', $description) !== 1) {
            throw new InvalidArgumentException($this->translator->trans('group.validation.description'));
        }
        $icon = trim((string) ($body['icon'] ?? 'fa-server'));
        if (preg_match('/^fa-[a-z0-9-]{1,47}$/', $icon) !== 1) {
            throw new InvalidArgumentException($this->translator->trans('group.validation.icon'));
        }
        $color = trim((string) ($body['color'] ?? '#3157d5'));
        if ($color === '') {
            $color = '#3157d5';
        }
        if (preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
            throw new InvalidArgumentException($this->translator->trans('group.validation.color'));
        }
        return [
            'name' => $name,
            'description' => $description === '' ? null : $description,
            'icon' => $icon,
            'color' => strtolower($color),
        ];
    }

    private function flashKey(string $key, string $type): void
    {
        $this->flash($this->translator->trans($key), $type);
    }

    private function flash(string $message, string $type): void
    {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }

    private function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
