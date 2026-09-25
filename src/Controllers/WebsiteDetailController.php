<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\IncidentRepository;
use App\Repositories\MaintenanceWindowRepository;
use App\Repositories\WebsiteMetricsRepository;
use App\Repositories\WebsiteRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

final class WebsiteDetailController
{
    private readonly IncidentRepository $incidents;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly WebsiteRepository $websites,
        private readonly WebsiteMetricsRepository $metrics,
        private readonly MaintenanceWindowRepository $maintenance,
        private readonly Translator $translator = new Translator(),
        ?IncidentRepository $incidents = null,
    ) {
        $this->incidents = $incidents ?? new IncidentRepository($pdo);
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $websiteId = $this->positiveId($args['id'] ?? null);
        $website = $websiteId === null ? null : $this->websites->detail($websiteId);
        if ($website === null) {
            return $this->redirect($response, '/sites');
        }
        $query = $request->getQueryParams();
        $tab = is_string($query['tab'] ?? null) && in_array(
            $query['tab'], ['overview', 'metrics', 'events', 'settings'], true
        ) ? $query['tab'] : 'overview';
        return $this->twig->render($response, 'sites/detail.twig', [
            'title' => (string) $website['name'],
            'website' => $website,
            'active_tab' => $tab,
            'latest' => $this->metrics->latest($websiteId),
            'probe_results' => $this->probeResults($websiteId),
            'active_incidents' => $this->incidents->active(['website_id' => $websiteId]),
            'incident_history' => $this->incidents->history(['website_id' => $websiteId]),
            'state' => $this->state($websiteId),
            'tls_targets' => $this->tls($websiteId),
            'domain_state' => $this->domain($websiteId),
            'maintenance' => $this->maintenance->activeWebsite($websiteId),
        ]);
    }

    /** @param array<string, string> $args */
    public function saveSettings(Request $request, Response $response, array $args): Response
    {
        $websiteId = $this->positiveId($args['id'] ?? null);
        if ($websiteId === null) return $this->redirect($response, '/sites');
        $body = $request->getParsedBody();
        try {
            $this->websites->updateSettings($websiteId, is_array($body) ? $body : []);
            $this->flash($this->translator->trans('websites.flash.updated'), 'success');
        } catch (InvalidArgumentException $exception) {
            $this->flash($exception->getMessage(), 'error');
        } catch (Throwable) {
            $this->flash($this->translator->trans('websites.flash.save_failed'), 'error');
        }
        return $this->redirect($response, '/sites/' . $websiteId . '?tab=settings');
    }

    /** @param array<string, string> $args */
    public function startMaintenance(Request $request, Response $response, array $args): Response
    {
        $websiteId = $this->positiveId($args['id'] ?? null);
        $body = $request->getParsedBody();
        $minutes = is_array($body) ? filter_var($body['duration_minutes'] ?? null, FILTER_VALIDATE_INT) : false;
        try {
            if ($websiteId === null || $minutes === false) throw new InvalidArgumentException('Invalid maintenance window.');
            $this->maintenance->startWebsite($websiteId, (int) $minutes * 60, is_array($body) ? (string) ($body['reason'] ?? '') : '', (string) ($_SESSION['username'] ?? ''));
            $this->flash($this->translator->trans('websites.flash.maintenance_started'), 'success');
        } catch (Throwable) {
            $this->flash($this->translator->trans('websites.flash.save_failed'), 'error');
        }
        return $this->redirect($response, '/sites/' . ($websiteId ?? 0) . '?tab=settings');
    }

    /** @param array<string, string> $args */
    public function cancelMaintenance(Request $request, Response $response, array $args): Response
    {
        $websiteId = $this->positiveId($args['id'] ?? null);
        if ($websiteId !== null) {
            $this->maintenance->cancelWebsite($websiteId);
            $this->flash($this->translator->trans('websites.flash.maintenance_cancelled'), 'success');
        }
        return $this->redirect($response, '/sites/' . ($websiteId ?? 0) . '?tab=settings');
    }

    /** @return array{status:string,updated_at:?string,active_problem_count:int} */
    private function state(int $websiteId): array
    {
        $statement = $this->pdo->prepare('SELECT status, updated_at, active_problem_count FROM website_state WHERE website_id = :id');
        $statement->execute(['id' => $websiteId]);
        $row = $statement->fetch();
        return is_array($row) ? [
            'status' => (string) $row['status'],
            'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
            'active_problem_count' => (int) $row['active_problem_count'],
        ] : ['status' => 'no_data', 'updated_at' => null, 'active_problem_count' => 0];
    }

    /** @return list<array<string, mixed>> */
    private function tls(int $websiteId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT targets.hostname, targets.port, targets.source, state.status,
                    state.not_after, state.checked_at
             FROM website_tls_targets AS targets
             LEFT JOIN website_tls_state AS state ON state.tls_target_id = targets.id
             WHERE targets.website_id = :id ORDER BY targets.hostname, targets.port'
        );
        $statement->execute(['id' => $websiteId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    private function domain(int $websiteId): ?array
    {
        $statement = $this->pdo->prepare('SELECT status, expires_at, registrar, source, checked_at FROM website_domain_state WHERE website_id = :id');
        $statement->execute(['id' => $websiteId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function probeResults(int $websiteId): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            WITH central AS (
                SELECT DISTINCT ON (samples.endpoint_id)
                    samples.endpoint_id,
                    endpoints.name AS endpoint_name,
                    'app'::text AS probe_kind,
                    NULL::text AS probe_id,
                    'Central MirvMon'::text AS probe_name,
                    samples.sample_time,
                    samples.transport_available,
                    samples.status_code,
                    samples.total_ms,
                    samples.error_kind,
                    samples.diagnostics->>'safe_message' AS safe_message
                FROM website_check_samples AS samples
                JOIN website_endpoints AS endpoints ON endpoints.id = samples.endpoint_id
                WHERE samples.website_id = :website_id
                ORDER BY samples.endpoint_id, samples.sample_time DESC, samples.sample_id DESC
            ),
            remote AS (
                SELECT DISTINCT ON (samples.endpoint_id, samples.server_id)
                    samples.endpoint_id,
                    endpoints.name AS endpoint_name,
                    'agent'::text AS probe_kind,
                    samples.server_id::text AS probe_id,
                    COALESCE(servers.name, 'Agent #' || samples.server_id::text) AS probe_name,
                    samples.sample_time,
                    samples.transport_available,
                    samples.status_code,
                    samples.total_ms,
                    samples.error_kind,
                    samples.safe_message
                FROM website_probe_samples AS samples
                JOIN website_probe_agents AS assignments
                  ON assignments.website_id = samples.website_id
                 AND assignments.server_id = samples.server_id
                JOIN website_endpoints AS endpoints ON endpoints.id = samples.endpoint_id
                LEFT JOIN servers ON servers.id = samples.server_id
                WHERE samples.website_id = :website_id
                ORDER BY samples.endpoint_id, samples.server_id,
                         samples.sample_time DESC, samples.sample_id DESC
            )
            SELECT * FROM central
            UNION ALL
            SELECT * FROM remote
            ORDER BY endpoint_id, probe_kind, probe_id NULLS FIRST
            SQL
        );
        $statement->execute(['website_id' => $websiteId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['endpoint_id'] = (int) $row['endpoint_id'];
            $row['transport_available'] = $row['transport_available'] === true
                || $row['transport_available'] === 1
                || $row['transport_available'] === '1'
                || $row['transport_available'] === 't';
            $row['status_code'] = $row['status_code'] === null ? null : (int) $row['status_code'];
            $row['total_ms'] = $row['total_ms'] === null ? null : (float) $row['total_ms'];
        }
        unset($row);

        return $rows;
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
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
