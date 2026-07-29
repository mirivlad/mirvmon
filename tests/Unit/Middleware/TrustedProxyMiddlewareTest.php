<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middlewares\TrustedProxyMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class TrustedProxyMiddlewareTest extends TestCase
{
    public function testTrustedProxyCanSetExternalSchemeAndHost(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://internal:8080/path',
            ['REMOTE_ADDR' => '10.20.30.40']
        )
            ->withHeader('X-Forwarded-Proto', 'https')
            ->withHeader('X-Forwarded-Host', 'monitoring.example:8443');

        $response = (new TrustedProxyMiddleware(['10.0.0.0/8']))->process(
            $request,
            $this->uriHandler()
        );

        self::assertSame(
            'https://monitoring.example:8443/path',
            (string) $response->getBody()
        );
    }

    public function testUntrustedForwardedHeadersAreRemovedAndIgnored(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://real.example/path',
            ['REMOTE_ADDR' => '203.0.113.10']
        )
            ->withHeader('X-Forwarded-Proto', 'https')
            ->withHeader('X-Forwarded-Host', 'attacker.example');

        $response = (new TrustedProxyMiddleware(['10.0.0.0/8']))->process(
            $request,
            $this->uriHandler()
        );

        self::assertSame('http://real.example/path', (string) $response->getBody());
        self::assertSame('', $response->getHeaderLine('X-Test-Forwarded-Host'));
    }

    public function testMalformedForwardedHostIsIgnored(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://real.example/path',
            ['REMOTE_ADDR' => '127.0.0.1']
        )
            ->withHeader('X-Forwarded-Proto', 'https')
            ->withHeader('X-Forwarded-Host', 'good.example/invalid-path');

        $response = (new TrustedProxyMiddleware(['127.0.0.1/32']))->process(
            $request,
            $this->uriHandler()
        );

        self::assertSame('https://real.example/path', (string) $response->getBody());
    }

    public function testClientAddressUsesTheFirstUntrustedHopFromTheRight(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://internal/path',
            ['REMOTE_ADDR' => '10.20.30.40']
        )->withHeader(
            'X-Forwarded-For',
            '198.51.100.200, 203.0.113.25, 10.30.40.50'
        );

        $response = (new TrustedProxyMiddleware(['10.0.0.0/8']))->process(
            $request,
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $response = new Response();
                    $response->getBody()->write(
                        (string) $request->getAttribute(
                            TrustedProxyMiddleware::CLIENT_IP_ATTRIBUTE
                        )
                    );

                    return $response;
                }
            }
        );

        self::assertSame('203.0.113.25', (string) $response->getBody());
    }

    private function uriHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write((string) $request->getUri());

                return $response->withHeader(
                    'X-Test-Forwarded-Host',
                    $request->getHeaderLine('X-Forwarded-Host')
                );
            }
        };
    }
}
