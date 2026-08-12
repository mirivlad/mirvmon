<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AgentUpdateService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AgentUpdateController
{
    public function __construct(private readonly AgentUpdateService $updates)
    {
    }

    /** @param array<string, string> $args */
    public function requestUpdate(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $serverId = filter_var(
            $args['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($serverId === false) {
            return $response->withStatus(400);
        }
        try {
            $this->updates->request(
                (int) $serverId,
                isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null
            );
        } catch (InvalidArgumentException) {
            return $response
                ->withHeader('Location', '/servers/' . $serverId . '?tab=agent')
                ->withStatus(303);
        }

        return $response
            ->withHeader('Location', '/servers/' . $serverId . '?tab=agent')
            ->withStatus(303);
    }

    /** @param array<string, string> $args */
    public function reportStatus(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $token = $this->bearerToken($request);
        $command = $args['command'] ?? '';
        $body = $request->getParsedBody();
        if ($token === null) {
            return $this->json($response, ['error' => 'invalid_token'], 401);
        }
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $command
            ) !== 1
            || !is_array($body)
            || !is_string($body['state'] ?? null)
            || (isset($body['error_code']) && !is_string($body['error_code']))
        ) {
            return $this->json($response, ['error' => 'invalid_update_status'], 422);
        }
        try {
            $saved = $this->updates->report(
                $token,
                $command,
                $body['state'],
                isset($body['error_code']) ? $body['error_code'] : null
            );
        } catch (InvalidArgumentException) {
            return $this->json($response, ['error' => 'invalid_update_status'], 422);
        }
        if (!$saved) {
            return $this->json($response, ['error' => 'invalid_token'], 401);
        }

        return $this->json($response, ['saved' => true], 200);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer ([^\s]+)$/', $header, $matches) !== 1) {
            return null;
        }

        return strlen($matches[1]) >= 32 && strlen($matches[1]) <= 512
            ? $matches[1]
            : null;
    }

    /** @param array<string, mixed> $payload */
    private function json(Response $response, array $payload, int $status): Response
    {
        $response->getBody()->write((string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withStatus($status);
    }
}
