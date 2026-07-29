<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

final class PublicUrlResolver
{
    private readonly ?string $configuredUrl;

    public function __construct(string $configuredUrl)
    {
        $configuredUrl = trim($configuredUrl);
        $this->configuredUrl = $configuredUrl === ''
            ? null
            : $this->normalize($configuredUrl);
    }

    public function resolve(ServerRequestInterface $request): string
    {
        if ($this->configuredUrl !== null) {
            return $this->configuredUrl;
        }

        $uri = $request->getUri();
        if ($uri->getUserInfo() !== '') {
            throw new InvalidArgumentException('Public request origin contains user information.');
        }

        $scheme = strtolower($uri->getScheme());
        $host = $uri->getHost();
        $port = $uri->getPort();
        $origin = $scheme . '://' . $this->formatHost($host);
        if ($port !== null) {
            $origin .= ':' . $port;
        }

        return $this->normalize($origin);
    }

    private function normalize(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('Invalid public base URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        $port = $parts['port'] ?? null;
        if (
            !in_array($scheme, ['http', 'https'], true)
            || !$this->validHost($host)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ($path !== '' && $path !== '/')
            || ($port !== null && ($port < 1 || $port > 65535))
        ) {
            throw new InvalidArgumentException('Invalid public base URL.');
        }

        $normalized = $scheme . '://' . $this->formatHost($host);
        if ($port !== null) {
            $normalized .= ':' . $port;
        }

        return $normalized;
    }

    private function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*'
            . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
            $host
        ) === 1;
    }

    private function formatHost(string $host): string
    {
        return str_contains($host, ':') ? '[' . $host . ']' : strtolower($host);
    }
}
