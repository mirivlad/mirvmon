<?php

declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class SessionSecurityMiddleware implements MiddlewareInterface
{
    public const STATELESS_ATTRIBUTE = 'mirvmon.stateless';

    /** @param list<string> $statelessPaths */
    public function __construct(
        private readonly string $name,
        private readonly bool $forceSecure,
        private readonly int $idleTimeout = 1800,
        private readonly int $absoluteTimeout = 43200,
        private readonly array $statelessPaths = []
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $this->name) !== 1) {
            throw new RuntimeException('Invalid session name.');
        }
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if (in_array($request->getUri()->getPath(), $this->statelessPaths, true)) {
            return $handler->handle(
                $request->withAttribute(self::STATELESS_ATTRIBUTE, true)
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name($this->name);
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', (string) max(
            $this->idleTimeout,
            $this->absoluteTimeout
        ));

        $cookieSessionId = (string) ($request->getCookieParams()[$this->name] ?? '');
        if (preg_match('/^[A-Za-z0-9,-]{16,128}$/', $cookieSessionId) === 1) {
            session_id($cookieSessionId);
        } else {
            session_id('');
        }

        if (!session_start()) {
            throw new RuntimeException('Unable to start session.');
        }

        $now = time();
        $createdAt = (int) ($_SESSION['_created_at'] ?? $now);
        $lastSeenAt = (int) ($_SESSION['_last_seen_at'] ?? $now);
        if ($now - $lastSeenAt > $this->idleTimeout || $now - $createdAt > $this->absoluteTimeout) {
            $_SESSION = [];
            session_regenerate_id(true);
            $createdAt = $now;
        }
        $_SESSION['_created_at'] = $createdAt;
        $_SESSION['_last_seen_at'] = $now;

        try {
            $response = $handler->handle($request);
            $destroyed = (bool) ($_SESSION['_destroyed'] ?? false);
            $sessionId = session_id();

            if ($destroyed) {
                $_SESSION = [];
                session_destroy();

                return $this->expireCookie($response, $request);
            }

            session_write_close();

            if ($cookieSessionId === '' || $sessionId !== $cookieSessionId) {
                return $this->withSessionCookie($response, $request, $sessionId);
            }

            return $response;
        } catch (\Throwable $exception) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            throw $exception;
        }
    }

    private function withSessionCookie(
        ResponseInterface $response,
        ServerRequestInterface $request,
        string $sessionId
    ): ResponseInterface {
        $cookie = rawurlencode($this->name) . '=' . rawurlencode($sessionId)
            . '; Path=/; HttpOnly; SameSite=Lax';
        if ($this->isSecure($request)) {
            $cookie .= '; Secure';
        }

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    private function expireCookie(
        ResponseInterface $response,
        ServerRequestInterface $request
    ): ResponseInterface {
        $cookie = rawurlencode($this->name)
            . '=deleted; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; HttpOnly; SameSite=Lax';
        if ($this->isSecure($request)) {
            $cookie .= '; Secure';
        }

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    private function isSecure(ServerRequestInterface $request): bool
    {
        return $this->forceSecure || $request->getUri()->getScheme() === 'https';
    }
}
