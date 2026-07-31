<?php

declare(strict_types=1);

namespace App\Controllers;

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
        private readonly NotificationOutboxRepository $outbox
    ) {
    }

    /** @param array<string, string> $args */
    public function index(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $alerts = $this->pdo->query(
            <<<'SQL'
            SELECT
                alerts.id,
                alerts.server_id,
                alerts.kind,
                alerts.subject,
                alerts.value,
                alerts.severity,
                alerts.created_at,
                servers.name AS server_name,
                COALESCE(
                    metric_names.name,
                    alerts.subject,
                    alerts.kind
                ) AS metric_name,
                metric_names.unit
            FROM alerts
            INNER JOIN servers ON servers.id = alerts.server_id
            LEFT JOIN metric_names ON metric_names.id = alerts.metric_id
            WHERE alerts.resolved = FALSE
            ORDER BY alerts.created_at DESC, alerts.id DESC
            SQL
        )?->fetchAll() ?? [];

        return $this->twig->render($response, 'alerts/index.twig', [
            'title' => 'Алерты',
            'alerts' => $alerts,
        ]);
    }

    /** @param array<string, string> $args */
    public function markAsResolved(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $alertId = filter_var(
            $args['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($alertId === false) {
            $this->flash('Алерт не найден', 'error');

            return $this->redirect($response);
        }

        try {
            $statement = $this->pdo->prepare(
                <<<'SQL'
                UPDATE alerts
                SET resolved = TRUE, resolved_at = CURRENT_TIMESTAMP
                WHERE id = :id AND resolved = FALSE
                SQL
            );
            $statement->execute(['id' => $alertId]);
            $resolved = $statement->rowCount() === 1;
            if ($resolved) {
                $this->announceManualResolution($alertId);
            }
            $this->flash(
                $resolved
                    ? 'Алерт отмечен как исправленный'
                    : 'Алерт уже исправлен или не найден',
                $resolved ? 'success' : 'warning'
            );
        } catch (Throwable) {
            $this->flash('Не удалось обновить алерт', 'error');
        }

        return $this->redirect($response);
    }

    /**
     * A manually cleared alert is as much a state change as an automatic
     * recovery, so it reaches the same channels.
     */
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
                COALESCE(
                    metric_names.name,
                    alerts.subject,
                    alerts.kind
                ) AS subject
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

    /** Matches the ISO-8601 event time the ingestion payloads carry. */
    private function timestamp(mixed $value): string
    {
        try {
            return (new DateTimeImmutable((string) $value))->format(DATE_ATOM);
        } catch (Throwable) {
            return (new DateTimeImmutable())->format(DATE_ATOM);
        }
    }

    private function flash(string $message, string $type): void
    {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }

    private function redirect(Response $response): Response
    {
        return $response->withHeader('Location', '/alerts')->withStatus(302);
    }
}
