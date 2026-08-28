<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Websites\ExpectedStatusSet;
use App\Domain\Websites\WebsiteEndpointDefinition;
use InvalidArgumentException;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

final class WebsiteEndpointValidator
{
    /** @var list<string> */
    private const ALLOWED_HEADERS = [
        'accept',
        'accept-language',
        'cache-control',
        'pragma',
        'user-agent',
        'referer',
        'if-none-match',
        'if-modified-since',
        'api-key',
        'idempotency-key',
    ];

    /** @param array<string, mixed> $input */
    public function validate(array $input): WebsiteEndpointDefinition
    {
        $id = $this->optionalPositiveInt($input, 'id');
        $name = trim($this->string($input, 'name'));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Website name must contain between 1 and 100 characters.');
        }

        $url = $this->canonicalUrl($this->string($input, 'url'));
        $method = strtoupper($this->optionalString($input, 'method', 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            throw new InvalidArgumentException('Only GET and HEAD website checks are supported.');
        }

        $interval = $this->boundedInt($input, 'interval_seconds', 60, 10, 86400);
        $timeout = $this->boundedInt($input, 'timeout_seconds', 15, 1, 60);
        $maxRedirects = $this->boundedInt($input, 'max_redirects', 10, 0, 10);
        $statusCheckEnabled = $this->bool($input['status_check_enabled'] ?? false);
        $expectedStatuses = ExpectedStatusSet::fromString(
            $this->optionalString($input, 'expected_statuses', '200-299')
        );
        $contentChecks = $this->contentChecks($input['content_checks'] ?? []);
        if ($method === 'HEAD' && $contentChecks !== []) {
            throw new InvalidArgumentException('HEAD checks cannot contain body assertions.');
        }

        $warning = $this->optionalBoundedInt($input, 'warning_total_ms', 1, 60000);
        $critical = $this->optionalBoundedInt($input, 'critical_total_ms', 1, 60000);
        if ($warning !== null && $critical !== null && $critical < $warning) {
            throw new InvalidArgumentException('Critical response time must not be below warning response time.');
        }

        [$authType, $authUsername, $authSecret] = $this->auth($input, $id !== null);
        $clearHeaders = $this->bool($input['clear_headers'] ?? false);
        $headersSpecified = array_key_exists('headers', $input) || $clearHeaders;
        $headers = $clearHeaders ? [] : $this->headers($input['headers'] ?? []);

        return new WebsiteEndpointDefinition(
            name: $name,
            url: $url,
            method: $method,
            intervalSeconds: $interval,
            timeoutSeconds: $timeout,
            followRedirects: $this->bool($input['follow_redirects'] ?? true),
            maxRedirects: $maxRedirects,
            statusCheckEnabled: $statusCheckEnabled,
            expectedStatuses: $expectedStatuses,
            contentChecks: $contentChecks,
            warningTotalMs: $warning,
            criticalTotalMs: $critical,
            authType: $authType,
            authUsername: $authUsername,
            authSecret: $authSecret,
            headers: $headers,
            credentialRedirectHosts: $this->credentialRedirectOrigins(
                $input['credential_redirect_hosts'] ?? []
            ),
            allowSelfSigned: $this->bool($input['allow_self_signed'] ?? false),
            tlsExpiryEnabled: $this->bool($input['tls_expiry_enabled'] ?? false),
            id: $id,
            isPrimary: $this->bool($input['is_primary'] ?? false),
            headersSpecified: $headersSpecified,
        );
    }

    private function canonicalUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Website URL is invalid.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Website URL must use HTTP or HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Website URL must not contain credentials or a fragment.');
        }

        $host = strtolower($parts['host']);
        if (function_exists('idn_to_ascii') && !filter_var($host, FILTER_VALIDATE_IP)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new InvalidArgumentException('Website host is invalid.');
            }
            $host = strtolower($ascii);
        }
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    /** @return list<array{kind: string, selector: ?string, needle: string}> */
    private function contentChecks(mixed $value): array
    {
        if (!is_array($value) || count($value) > 20) {
            throw new InvalidArgumentException('Content checks must be an array with at most 20 items.');
        }

        $checks = [];
        $converter = new CssSelectorConverter();
        foreach ($value as $check) {
            if (!is_array($check)) {
                throw new InvalidArgumentException('Content check is invalid.');
            }
            $kind = strtolower(trim((string) ($check['kind'] ?? '')));
            $needle = (string) ($check['needle'] ?? '');
            if (mb_strlen($needle) > 2000 || ($kind === 'page_text' && $needle === '')) {
                throw new InvalidArgumentException('Content check text is invalid.');
            }

            $selector = null;
            if ($kind === 'css') {
                $selector = trim((string) ($check['selector'] ?? ''));
                if ($selector === '' || mb_strlen($selector) > 1000) {
                    throw new InvalidArgumentException('CSS selector is invalid.');
                }
                try {
                    $converter->toXPath($selector);
                } catch (Throwable) {
                    throw new InvalidArgumentException('CSS selector is invalid.');
                }
            } elseif ($kind !== 'page_text') {
                throw new InvalidArgumentException('Unsupported content check type.');
            }

            $checks[] = ['kind' => $kind, 'selector' => $selector, 'needle' => $needle];
        }

        return $checks;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{string, ?string, ?string}
     */
    private function auth(array $input, bool $existing): array
    {
        $type = strtolower($this->optionalString($input, 'auth_type', 'none'));
        $username = isset($input['auth_username']) ? trim((string) $input['auth_username']) : null;
        $secret = isset($input['auth_secret']) ? (string) $input['auth_secret'] : null;

        if ($type === 'none') {
            return ['none', null, null];
        }
        if ($existing && ($secret === null || $secret === '') && ($username === null || $username === '')
            && in_array($type, ['basic', 'bearer'], true)) {
            return [$type, null, null];
        }
        if ($type === 'basic' && $username !== null && $username !== '' && mb_strlen($username) <= 255
            && $secret !== null && $secret !== '' && strlen($secret) <= 8192) {
            return ['basic', $username, $secret];
        }
        if ($type === 'bearer' && $secret !== null && $secret !== '' && strlen($secret) <= 8192) {
            return ['bearer', null, $secret];
        }

        throw new InvalidArgumentException('Website authentication settings are invalid.');
    }

    /** @return array<string, string> */
    private function headers(mixed $value): array
    {
        if (!is_array($value) || count($value) > 20) {
            throw new InvalidArgumentException('Headers must be an array with at most 20 items.');
        }

        $headers = [];
        $seen = [];
        foreach ($value as $header) {
            if (!is_array($header)) {
                throw new InvalidArgumentException('Header is invalid.');
            }
            $name = trim((string) ($header['name'] ?? ''));
            $lower = strtolower($name);
            $headerValue = (string) ($header['value'] ?? '');
            $allowed = in_array($lower, self::ALLOWED_HEADERS, true) || str_starts_with($lower, 'x-');
            $blocked = in_array($lower, ['host', 'cookie', 'content-length', 'connection', 'transfer-encoding', 'forwarded', 'authorization'], true)
                || str_starts_with($lower, 'proxy-') || str_starts_with($lower, 'sec-');
            if ($name === '' || strlen($name) > 200
                || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1
                || !$allowed || $blocked || isset($seen[$lower])
                || strlen($headerValue) > 8192 || preg_match('/[\r\n\0]/', $headerValue) === 1) {
                throw new InvalidArgumentException('Custom header is invalid or not allowed.');
            }
            $seen[$lower] = true;
            $headers[$name] = $headerValue;
        }

        return $headers;
    }

    /** @return list<string> */
    private function credentialRedirectOrigins(mixed $value): array
    {
        if (!is_array($value) || count($value) > 20) {
            throw new InvalidArgumentException('Credential redirect origins are invalid.');
        }

        $origins = [];
        foreach ($value as $origin) {
            $parts = parse_url(trim((string) $origin));
            if ($parts === false || !isset($parts['scheme'], $parts['host'])
                || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
                throw new InvalidArgumentException('Credential redirect entry must be an HTTP origin.');
            }

            $scheme = strtolower($parts['scheme']);
            $host = strtolower($parts['host']);
            if (str_contains($host, ':') && !str_starts_with($host, '[')) {
                $host = '[' . $host . ']';
            }
            $port = $parts['port'] ?? null;
            $portPart = $port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))
                ? ':' . $port
                : '';
            $origins[$scheme . '://' . $host . $portPart] = true;
        }

        return array_keys($origins);
    }

    /** @param array<string, mixed> $input */
    private function string(array $input, string $key): string
    {
        if (!isset($input[$key]) || !is_string($input[$key])) {
            throw new InvalidArgumentException(sprintf('%s is required.', $key));
        }

        return $input[$key];
    }

    /** @param array<string, mixed> $input */
    private function optionalString(array $input, string $key, string $default): string
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }
        if (!is_string($input[$key])) {
            throw new InvalidArgumentException(sprintf('%s must be a string.', $key));
        }

        return $input[$key];
    }

    /** @param array<string, mixed> $input */
    private function boundedInt(array $input, string $key, int $default, int $min, int $max): int
    {
        $value = $input[$key] ?? $default;
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value) === 1)) {
            throw new InvalidArgumentException(sprintf('%s must be an integer.', $key));
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf('%s is out of range.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function optionalBoundedInt(array $input, string $key, int $min, int $max): ?int
    {
        if (!isset($input[$key]) || $input[$key] === '') {
            return null;
        }

        return $this->boundedInt($input, $key, $min, $min, $max);
    }

    /** @param array<string, mixed> $input */
    private function optionalPositiveInt(array $input, string $key): ?int
    {
        if (!isset($input[$key]) || $input[$key] === '') {
            return null;
        }
        $value = filter_var($input[$key], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($value === false) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $key));
        }

        return $value;
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'on') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'off' || $value === '') {
            return false;
        }

        throw new InvalidArgumentException('Boolean setting is invalid.');
    }
}
