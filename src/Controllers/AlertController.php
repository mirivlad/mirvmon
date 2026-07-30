<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

final class AlertController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig
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
            $this->flash(
                $statement->rowCount() === 1
                    ? 'Алерт отмечен как исправленный'
                    : 'Алерт уже исправлен или не найден',
                $statement->rowCount() === 1 ? 'success' : 'warning'
            );
        } catch (Throwable) {
            $this->flash('Не удалось обновить алерт', 'error');
        }

        return $this->redirect($response);
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
