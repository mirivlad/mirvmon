<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middlewares\SessionMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

final class SessionMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testNormalReadConsumesFlashMessage(): void
    {
        [$middleware, $twig] = $this->middleware();
        $_SESSION = ['user_id' => 1, 'flash_message' => 'saved', 'flash_type' => 'success'];

        $middleware($this->request('GET'), $this->handler());

        self::assertArrayNotHasKey('flash_message', $_SESSION);
        self::assertSame('saved', $twig->getEnvironment()->getGlobals()['session']['flash_message']);
    }

    public function testLiveFragmentReadPreservesFlashMessage(): void
    {
        [$middleware, $twig] = $this->middleware();
        $_SESSION = ['user_id' => 1, 'flash_message' => 'saved', 'flash_type' => 'success'];

        $middleware(
            $this->request('GET')->withHeader('X-MirvMon-Live-Fragment', '1'),
            $this->handler()
        );

        self::assertSame('saved', $_SESSION['flash_message'] ?? null);
        self::assertSame('success', $_SESSION['flash_type'] ?? null);
        self::assertSame('saved', $twig->getEnvironment()->getGlobals()['session']['flash_message']);
    }

    public function testLiveHeaderDoesNotChangePostFlashSemantics(): void
    {
        [$middleware] = $this->middleware();
        $_SESSION = ['user_id' => 1, 'flash_message' => 'saved', 'flash_type' => 'success'];

        $middleware(
            $this->request('POST')->withHeader('X-MirvMon-Live-Fragment', '1'),
            $this->handler()
        );

        self::assertArrayNotHasKey('flash_message', $_SESSION);
        self::assertArrayNotHasKey('flash_type', $_SESSION);
    }

    /** @return array{SessionMiddleware,Twig} */
    private function middleware(): array
    {
        $twig = Twig::create(dirname(__DIR__, 3) . '/templates', ['cache' => false]);
        return [new SessionMiddleware($twig), $twig];
    }

    private function request(string $method): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, 'https://monitor.example/test');
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }
}
