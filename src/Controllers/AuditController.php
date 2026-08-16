<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\AuditLogRepository;
use JsonException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AuditController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly AuditLogRepository $audit,
        private readonly Translator $translator = new Translator()
    ) {
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        if (($_SESSION['role'] ?? null) !== 'admin') {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $query = $request->getQueryParams();
        $filters = $this->audit->filters($query);
        $page = filter_var(
            $query['page'] ?? 1,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $result = $this->audit->page($filters, $page === false ? 1 : (int) $page);

        foreach ($result['items'] as &$item) {
            $item['object_url'] = $this->objectUrl(
                (string) $item['object_type'],
                is_string($item['object_id'] ?? null) ? $item['object_id'] : null
            );
            $item['metadata_text'] = $this->metadataText($item['metadata'] ?? null);
        }
        unset($item);

        $displayFilters = [
            ...$filters,
            'from' => $this->displayDate($query['from'] ?? null),
            'to' => $this->displayDate($query['to'] ?? null),
        ];

        return $this->twig->render($response, 'admin/audit.twig', [
            'title' => $this->translator->trans('audit.title'),
            'filters' => $displayFilters,
            'audit' => $result,
            'options' => $this->audit->filterOptions(),
            'previous_url' => $this->pageUrl($displayFilters, max(1, $result['page'] - 1)),
            'next_url' => $this->pageUrl($displayFilters, min($result['pages'], $result['page'] + 1)),
        ]);
    }

    private function objectUrl(string $type, ?string $id): ?string
    {
        if ($id === null || preg_match('/^[1-9][0-9]*$/', $id) !== 1) {
            return match ($type) {
                'notification_settings' => '/admin/notifications',
                'notification_queue' => '/admin/notifications/queue',
                'defaults', 'language' => '/admin/defaults',
                'system' => '/admin/system',
                'user' => '/admin/users',
                default => null,
            };
        }

        return match ($type) {
            'server' => '/servers/' . $id,
            'group' => '/groups/' . $id,
            'user' => '/admin/users',
            'alert' => '/alerts?view=history',
            'notification_job', 'notification_queue' => '/admin/notifications/queue',
            default => null,
        };
    }

    private function metadataText(mixed $metadata): ?string
    {
        try {
            $decoded = is_string($metadata)
                ? json_decode($metadata, true, 32, JSON_THROW_ON_ERROR)
                : $metadata;
            if (!is_array($decoded) || $decoded === []) {
                return null;
            }
            return json_encode(
                $decoded,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            return null;
        }
    }

    private function displayDate(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }
        return $value;
    }

    /** @param array<string, mixed> $filters */
    private function pageUrl(array $filters, int $page): string
    {
        $query = [];
        foreach (['actor', 'action', 'object_type', 'object_id', 'from', 'to', 'q'] as $key) {
            $value = $filters[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $query[$key] = $value;
            }
        }
        if ($page > 1) {
            $query['page'] = $page;
        }
        return '/admin/audit' . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
