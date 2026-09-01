<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Services\AgentFleetService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AgentFleetController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly AgentFleetService $fleet,
        private readonly Translator $translator = new Translator()
    ) {
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        [$view, $search] = $this->filters($request);
        return $this->twig->render($response, 'agents/index.twig', array_merge(
            ['title' => $this->translator->trans('fleet.title')],
            $this->fleet->overview($view, $search)
        ));
    }

    /** @param array<string, string> $args */
    public function status(Request $request, Response $response, array $args): Response
    {
        [$view, $search] = $this->filters($request);
        $overview = $this->fleet->overview($view, $search);
        $servers = [];
        foreach ($overview['servers'] as $server) {
            if (!is_array($server)) {
                continue;
            }
            $update = is_array($server['agent_update'] ?? null) ? $server['agent_update'] : [];
            $command = is_array($update['command'] ?? null) ? $update['command'] : [];
            $platform = is_array($server['platform'] ?? null) ? $server['platform'] : [];
            $servers[] = [
                'id' => (int) ($server['id'] ?? 0),
                'connection_state' => (string) ($server['connection_state'] ?? 'never'),
                'seconds_since_update' => isset($server['seconds_since_update'])
                    ? (int) $server['seconds_since_update']
                    : null,
                'status' => (string) ($server['status'] ?? 'offline'),
                'platform' => [
                    'icon_class' => (string) ($platform['icon_class'] ?? 'fas fa-server'),
                    'tooltip' => (string) ($platform['tooltip'] ?? ''),
                ],
                'update' => [
                    'installed_version' => is_string($update['installed_version'] ?? null) ? $update['installed_version'] : null,
                    'available_version' => is_string($update['available_version'] ?? null) ? $update['available_version'] : null,
                    'artifact' => is_string($update['artifact'] ?? null) ? $update['artifact'] : null,
                    'is_outdated' => $update['is_outdated'] ?? null,
                    'requires_compatible_updater' => ($update['requires_compatible_updater'] ?? false) === true,
                    'state' => (string) ($update['state'] ?? 'unknown'),
                    'error_code' => is_string($command['error_code'] ?? null) ? $command['error_code'] : null,
                ],
            ];
        }

        return $this->json($response, [
            'summary' => $overview['summary'],
            'target_version' => $overview['target_version'],
            'versions' => $overview['versions'],
            'servers' => $servers,
        ]);
    }

    /** @return array{string, string} */
    private function filters(Request $request): array
    {
        $query = $request->getQueryParams();
        return [
            is_string($query['view'] ?? null) ? $query['view'] : 'all',
            is_string($query['q'] ?? null) ? $query['q'] : '',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function json(Response $response, array $payload): Response
    {
        $response->getBody()->write((string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
