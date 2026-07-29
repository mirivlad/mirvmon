<?php

declare(strict_types=1);

namespace App\Middlewares;

use PDO;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly PDO $pdo
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if (isset($_SESSION['user_id'])) {
            $statement = $this->pdo->prepare(
                'SELECT id, username, role, is_active FROM users WHERE id = :id'
            );
            $statement->execute([':id' => $_SESSION['user_id']]);
            $user = $statement->fetch();
            if (is_array($user) && (bool) $user['is_active']) {
                $_SESSION['username'] = (string) $user['username'];
                $_SESSION['role'] = (string) $user['role'];

                return $handler->handle($request);
            }

            unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role']);
            session_regenerate_id(true);
        }

        $response = $this->responseFactory->createResponse();
        if (
            str_starts_with($request->getUri()->getPath(), '/api/')
            || str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json')
        ) {
            $response->getBody()->write(
                '{"error":{"code":"unauthorized","message":"Authentication required."}}'
            );

            return $response
                ->withStatus(401)
                ->withHeader('Content-Type', 'application/json');
        }

        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
