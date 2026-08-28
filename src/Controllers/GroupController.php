<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\IncidentRepository;
use App\Repositories\WebsiteRepository;
use App\Services\ServerStatusService;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

final class GroupController
{
    private IncidentRepository $incidents;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly ServerStatusService $status,
        private readonly Translator $translator = new Translator(),
        ?IncidentRepository $incidents = null,
        private readonly ?WebsiteRepository $websites = null
    ) {
        $this->incidents = $incidents ?? new IncidentRepository($pdo);
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        $groups = $this->pdo->query(
            'SELECT * FROM monitoring_groups ORDER BY sort_order, name, id'
        )?->fetchAll() ?? [];
        $servers = $this->status->enrich($this->loadServers());
        $websiteGroups = $this->websites?->groupedList([]) ?? [];
        $websitesByGroup = $this->websitesByGroup($websiteGroups);

        /** @var array<int, array{total: int, online: int, warning: int, critical: int, offline: int, active_problems: int}> $summaries */
        $summaries = [];
        foreach ($servers as $server) {
            $groupId = (int) ($server['group_id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }
            if (!isset($summaries[$groupId])) {
                $summaries[$groupId] = $this->emptySummary();
            }
            $this->addServerToSummary($summaries[$groupId], $server);
        }

        /** @var array<int, int> $problemCounts */
        $problemCounts = [];
        foreach ($this->incidents->active(['source_type' => 'server']) as $incident) {
            $groupId = (int) ($incident['group_id'] ?? 0);
            if ($groupId > 0) {
                $problemCounts[$groupId] = ($problemCounts[$groupId] ?? 0) + 1;
            }
        }

        foreach ($groups as &$group) {
            $groupId = (int) $group['id'];
            $group['summary'] = $summaries[$groupId] ?? $this->emptySummary();
            $group['websites'] = $websitesByGroup[$groupId] ?? [];
            $group['summary']['website_total'] = count($group['websites']);
            $group['summary']['website_problems'] = array_sum(array_map(
                static fn (array $website): int => (int) $website['active_problem_count'],
                $group['websites']
            ));
            $group['summary']['total'] += $group['summary']['website_total'];
            $group['summary']['active_problems'] = $problemCounts[$groupId] ?? 0;
            $group['summary']['active_problems'] += $group['summary']['website_problems'];
            $group['server_count'] = $group['summary']['total'];
        }
        unset($group);

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
                'INSERT INTO monitoring_groups (name, description, icon, color)
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
                'UPDATE monitoring_groups
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
            $statement = $this->pdo->prepare('DELETE FROM monitoring_groups WHERE id = :id');
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

        $groupId = (int) $group['id'];
        $servers = $this->status->enrich($this->loadServers($groupId));
        $websiteGroups = $this->websites?->groupedList(['group_id' => $groupId]) ?? [];
        $websites = $websiteGroups[0]['websites'] ?? [];
        $summary = $this->emptySummary();
        foreach ($servers as $server) {
            $this->addServerToSummary($summary, $server);
        }
        $summary['active_problems'] = count($this->incidents->active([
            'group_id' => $groupId,
            'source_type' => 'server',
        ]));
        $summary['website_total'] = count($websites);
        $summary['website_problems'] = array_sum(array_map(
            static fn (array $website): int => (int) $website['active_problem_count'],
            $websites
        ));
        $summary['total'] += $summary['website_total'];
        $summary['active_problems'] += $summary['website_problems'];

        return $this->twig->render($response, 'groups/show.twig', [
            'title' => (string) $group['name'],
            'group' => $group,
            'servers' => $servers,
            'summary' => $summary,
            'websites' => $websites,
        ]);
    }

    /**
     * @param list<array{id: ?int, websites: list<array<string, mixed>>}> $groups
     * @return array<int, list<array<string, mixed>>>
     */
    private function websitesByGroup(array $groups): array
    {
        $result = [];
        foreach ($groups as $group) {
            if ($group['id'] !== null) {
                $result[(int) $group['id']] = $group['websites'];
            }
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function loadServers(?int $groupId = null): array
    {
        $sql = <<<'SQL'
            SELECT
                servers.id,
                servers.group_id,
                servers.name,
                servers.address,
                servers.description,
                servers.is_active,
                servers.os_version,
                servers.agent_artifact,
                servers.last_metrics_at,
                max(agent_tokens.last_used_at) AS last_contact_at,
                servers.offline_timeout_seconds,
                count(DISTINCT alerts.id) AS active_alerts,
                count(DISTINCT alerts.id) FILTER (WHERE alerts.severity = 'warning') AS warning_alerts,
                count(DISTINCT alerts.id) FILTER (WHERE alerts.severity = 'critical') AS critical_alerts
            FROM servers
            LEFT JOIN agent_tokens ON agent_tokens.server_id = servers.id
            LEFT JOIN alerts ON alerts.server_id = servers.id AND alerts.resolved = FALSE
            SQL;
        if ($groupId !== null) {
            $sql .= ' WHERE servers.group_id = :group_id';
        }
        $sql .= ' GROUP BY servers.id ORDER BY servers.name, servers.id';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($groupId === null ? [] : ['group_id' => $groupId]);
        return $statement->fetchAll();
    }

    /** @return array{total: int, online: int, warning: int, critical: int, offline: int, active_problems: int, website_total: int, website_problems: int} */
    private function emptySummary(): array
    {
        return [
            'total' => 0,
            'online' => 0,
            'warning' => 0,
            'critical' => 0,
            'offline' => 0,
            'active_problems' => 0,
            'website_total' => 0,
            'website_problems' => 0,
        ];
    }

    /**
     * @param array{total: int, online: int, warning: int, critical: int, offline: int, active_problems: int, website_total: int, website_problems: int} $summary
     * @param array<string, mixed> $server
     */
    private function addServerToSummary(array &$summary, array $server): void
    {
        $summary['total']++;
        switch ((string) ($server['status'] ?? 'offline')) {
            case 'online':
                $summary['online']++;
                break;
            case 'warning':
                $summary['warning']++;
                break;
            case 'critical':
                $summary['critical']++;
                break;
            default:
                $summary['offline']++;
                break;
        }
    }

    /** @param array<string, mixed> $args */
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
        $statement = $this->pdo->prepare('SELECT * FROM monitoring_groups WHERE id = :id');
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
