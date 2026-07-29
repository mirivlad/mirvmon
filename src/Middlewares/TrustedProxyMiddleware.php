<?php

declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TrustedProxyMiddleware implements MiddlewareInterface
{
    public const CLIENT_IP_ATTRIBUTE = 'mirvmon.client_ip';

    /** @param list<string> $trustedNetworks */
    public function __construct(private readonly array $trustedNetworks)
    {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $remoteAddress = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $trusted = $this->addressIsTrusted($remoteAddress);

        if (!$trusted) {
            return $handler->handle(
                $this->withoutForwardingHeaders($request)
                    ->withAttribute(self::CLIENT_IP_ATTRIBUTE, $remoteAddress)
            );
        }

        $uri = $request->getUri();
        $proto = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0]));
        if (in_array($proto, ['http', 'https'], true)) {
            $uri = $uri->withScheme($proto);
            if ($uri->getPort() === 80 && $proto === 'https') {
                $uri = $uri->withPort(null);
            }
        }

        $forwardedHost = trim(explode(',', $request->getHeaderLine('X-Forwarded-Host'))[0]);
        if ($this->isValidHost($forwardedHost)) {
            $host = $forwardedHost;
            $port = null;
            if (str_starts_with($host, '[')) {
                $closingBracket = strpos($host, ']');
                if ($closingBracket !== false) {
                    $address = substr($host, 1, $closingBracket - 1);
                    $suffix = substr($host, $closingBracket + 1);
                    $host = $address;
                    $port = str_starts_with($suffix, ':') ? (int) substr($suffix, 1) : null;
                }
            } elseif (substr_count($host, ':') === 1) {
                [$host, $rawPort] = explode(':', $host, 2);
                $port = (int) $rawPort;
            }
            $uri = $uri->withHost($host)->withPort($port);
        }

        return $handler->handle(
            $request
                ->withUri($uri)
                ->withAttribute(
                    self::CLIENT_IP_ATTRIBUTE,
                    $this->resolveClientAddress($request, $remoteAddress)
                )
        );
    }

    private function withoutForwardingHeaders(
        ServerRequestInterface $request
    ): ServerRequestInterface {
        foreach ([
            'Forwarded',
            'X-Forwarded-For',
            'X-Forwarded-Host',
            'X-Forwarded-Port',
            'X-Forwarded-Proto',
        ] as $header) {
            $request = $request->withoutHeader($header);
        }

        return $request;
    }

    private function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 255) {
            return false;
        }

        return preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|\\[[0-9a-f:]+\\])(?::[1-9][0-9]{0,4})?$/i',
            $host
        ) === 1
            && (!preg_match('/:(\\d+)$/', $host, $match) || (int) $match[1] <= 65535);
    }

    private function addressIsTrusted(string $address): bool
    {
        $packedAddress = @inet_pton($address);
        if ($packedAddress === false) {
            return false;
        }

        foreach ($this->trustedNetworks as $network) {
            [$subnet, $prefix] = array_pad(explode('/', $network, 2), 2, null);
            $packedSubnet = @inet_pton($subnet);
            if ($packedSubnet === false || strlen($packedSubnet) !== strlen($packedAddress)) {
                continue;
            }

            $bits = $prefix === null ? strlen($packedSubnet) * 8 : (int) $prefix;
            if ($bits < 0 || $bits > strlen($packedSubnet) * 8) {
                continue;
            }

            $bytes = intdiv($bits, 8);
            $remainder = $bits % 8;
            if (substr($packedAddress, 0, $bytes) !== substr($packedSubnet, 0, $bytes)) {
                continue;
            }
            if ($remainder === 0) {
                return true;
            }

            $mask = 0xFF << (8 - $remainder);
            if ((ord($packedAddress[$bytes]) & $mask) === (ord($packedSubnet[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    private function resolveClientAddress(
        ServerRequestInterface $request,
        string $remoteAddress
    ): string {
        $forwarded = array_values(array_filter(array_map(
            'trim',
            explode(',', $request->getHeaderLine('X-Forwarded-For'))
        ), static fn (string $address): bool => @inet_pton($address) !== false));
        if ($forwarded === []) {
            return $remoteAddress;
        }

        for ($index = count($forwarded) - 1; $index >= 0; --$index) {
            if (!$this->addressIsTrusted($forwarded[$index])) {
                return $forwarded[$index];
            }
        }

        return $forwarded[0];
    }
}
