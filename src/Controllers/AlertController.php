<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\IncidentRepository;
use App\Repositories\NotificationOutboxRepository;
use DateTimeImmutable;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

final class AlertController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly NotificationOutboxRepository $outbox,
        private readonly IncidentRepository $incidents,
        private readonly Translator $translator = new Translator()
    ) {
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        $query = $request->getQueryParams();
        $view = ($query['view'] ?? null) === 'history' ? 'history' : 'active';
        $filters = $this->filters($query);
        $events = $view === 'history'
            ? $this->incidents->history($filters)
            : $this->incidents->active($filters);

        return $this->twig->render($response, 'alerts/index.twig', [
            'title' => $this->translator->trans('incidents.title'),
            'view' => $view,
            'events' => $events,
            'filters' => [
                'server_id' => $filters['server_id'] ?? '',
                'group_id' => $filters['group_id'] ?? '',
                'kind' => $filters['kind'] ?? '',
                'severity' => $filters['severity'] ?? '',
                'from' => $this->displayDate($query['from'] ?? null),
                'to' => $this->displayDate($query['to'] ?? null),
            ],
            'server_options' => $this->incidents->serverOptions(),
            'group_options' => $this->incidents->groupOptions(),
        ]);
    }

    /** @param array<string, string> $args */
    public function markAsResolved(Request $request, Response $response, array $args): Response
    {
        $alertId = filter_var(
            $args['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($alertId === false) {
            $this->flashKey('alert.flash.not_found', 'error');
            return $this->redirect($response);
        }

        try {
            $statement = $this->pdo->prepare(
                'UPDATE alerts
                 SET resolved = TRUE,
                     resolved_at = CURRENT_TIMESTAMP,
                     resolved_by_user_id = :user_id,
                     resolved_by_username = :username
                 WHERE id = :id AND resolved = FALSE'
            );
            $userId = filter_var(
                $_SESSION['user_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $username = $_SESSION['username'] ?? null;
            $statement->execute([
                'id' => $alertId,
                'user_id' => $userId === false ? null : $userId,
                'username' => is_string($username) ? mb_substr($username, 0, 80) : null,
            ]);
            $resolved = $statement->rowCount() === 1;
            if ($resolved) {
                $this->announceManualResolution($alertId);
            }
            $this->flashKey(
                $resolved ? 'alert.flash.resolved' : 'alert.flash.already_resolved',
                $resolved ? 'success' : 'warning'
            );
        } catch (Throwable) {
            $this->flashKey('alert.flash.update_failed', 'error');
        }

        return $this->redirect($response);
    }

    private function announceManualResolution(int $alertId): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                alerts.server_id,
                alerts.severity,
                alerts.value,
                alerts.resolved_at,
                servers.name AS server_name,
                COALESCE(metric_names.name, alerts.subject, alerts.kind) AS subject
            FROM alerts
            INNER JOIN servers ON servers.id = alerts.server_id
            LEFT JOIN metric_names ON metric_names.id = alerts.metric_id
            WHERE alerts.id = :id
            SQL
        );
        $statement->execute(['id' => $alertId]);
        $alert = $statement->fetch();
        if (!is_array($alert)) {
            return;
        }

        $resolvedBy = $_SESSION['username'] ?? null;
        $this->outbox->enqueueConfigured(
            (int) $alert['server_id'],
            $alertId,
            'alert_resolved',
            [
                'type' => 'alert',
                'event' => 'resolved_manually',
                'server_id' => (int) $alert['server_id'],
                'server_name' => (string) $alert['server_name'],
                'subject' => (string) $alert['subject'],
                'value' => $alert['value'],
                'severity' => (string) $alert['severity'],
                'event_time' => $this->timestamp($alert['resolved_at']),
                'resolved_by' => is_string($resolvedBy) ? $resolvedBy : null,
            ],
            'alert:' . $alertId . ':resolved_manually'
        );
    }

    /**
     * @param array<string, mixed> $query
     * @return array{server_id?: int, group_id?: int, kind?: string, severity?: string, from?: string, to?: string}
     */
    private function filters(array $query): array
    {
        $filters = [];
        foreach (['server_id', 'group_id'] as $name) {
            $value = filter_var(
                $query[$name] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($value !== false) {
                $filters[$name] = $value;
            }
        }

        $kind = $query['kind'] ?? null;
        if (is_string($kind) && in_array($kind, ['metric', 'service', 'offline'], true)) {
            $filters['kind'] = $kind;
        }
        $severity = $query['severity'] ?? null;
        if (is_string($severity) && in_array($severity, ['warning', 'critical'], true)) {
            $filters['severity'] = $severity;
        }

        $from = $this->date($query['from'] ?? null);
        if ($from !== null) {
            $filters['from'] = $from->format('Y-m-d 00:00:00P');
        }
        $to = $this->date($query['to'] ?? null);
        if ($to !== null) {
            $filters['to'] = $to->modify('+1 day')->format('Y-m-d 00:00:00P');
        }

        return $filters;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    private function displayDate(mixed $value): string
    {
        $date = $this->date($value);
        return $date?->format('Y-m-d') ?? '';
    }

    private function timestamp(mixed $value): string
    {
        try {
            return (new DateTimeImmutable((string) $value))->format(DATE_ATOM);
        } catch (Throwable) {
            return (new DateTimeImmutable())->format(DATE_ATOM);
        }
    }

    private function flashKey(string $key, string $type): void
    {
        $_SESSION['flash_message'] = $this->translator->trans($key);
        $_SESSION['flash_type'] = $type;
    }

    private function redirect(Response $response): Response
    {
        return $response->withHeader('Location', '/alerts')->withStatus(302);
    }
}
