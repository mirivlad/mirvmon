<?php

declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const NONCE_PLACEHOLDER = '__MIRVMON_CSP_NONCE__';

    public function __construct(private readonly StreamFactoryInterface $streams)
    {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $nonce = base64_encode(random_bytes(18));
        $response = $handler->handle($request);
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if ($contentType === '' || str_starts_with($contentType, 'text/html')) {
            $body = (string) $response->getBody();
            $response = $response
                ->withBody($this->streams->createStream(str_replace(
                    self::NONCE_PLACEHOLDER,
                    $nonce,
                    $body
                )))
                ->withoutHeader('Content-Length');
        }

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; "
                . "style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; "
                . "connect-src 'self'; object-src 'none'; frame-ancestors 'none'; "
                . "base-uri 'self'; form-action 'self'"
            );

        if ($request->getUri()->getScheme() === 'https') {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
