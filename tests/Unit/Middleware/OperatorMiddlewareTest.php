<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middlewares\OperatorMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class OperatorMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAllowsAdministratorAndOperatorButRejectsViewer(): void
    {
        $middleware = new OperatorMiddleware(new ResponseFactory());
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/operation');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };

        foreach (['admin', 'operator'] as $role) {
            $_SESSION = ['role' => $role];
            self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
        }

        $_SESSION = ['role' => 'user'];
        self::assertSame(403, $middleware->process($request, $handler)->getStatusCode());
    }
}
