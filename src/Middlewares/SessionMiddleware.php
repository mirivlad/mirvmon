<?php

declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Views\Twig;

final class SessionMiddleware
{
    public function __construct(private readonly Twig $twig)
    {
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        if ($request->getAttribute(SessionSecurityMiddleware::STATELESS_ATTRIBUTE) === true) {
            return $handler->handle($request);
        }

        $sessionData = [
            'user_id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'role' => $_SESSION['role'] ?? null,
            'flash_message' => $_SESSION['flash_message'] ?? null,
            'flash_type' => $_SESSION['flash_type'] ?? null
        ];

        $liveFragmentRead = $request->getMethod() === 'GET'
            && $request->getHeaderLine('X-MirvMon-Live-Fragment') === '1';
        if (!$liveFragmentRead) {
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        }
        $this->twig->getEnvironment()->addGlobal('session', $sessionData);

        return $handler->handle($request);
    }
}
