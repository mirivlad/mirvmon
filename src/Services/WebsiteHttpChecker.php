<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Websites\WebsiteCheckError;
use App\Domain\Websites\WebsiteCheckResult;
use App\Domain\Websites\WebsiteEndpointDefinition;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class WebsiteHttpChecker
{
    private const MAX_BODY_BYTES = 1048576;

    private readonly WebsiteAssertionEvaluator $assertions;

    public function __construct(?WebsiteAssertionEvaluator $assertions = null)
    {
        $this->assertions = $assertions ?? new WebsiteAssertionEvaluator();
    }

    public function check(
        WebsiteEndpointDefinition $endpoint,
        int $websiteId,
        int $endpointId,
        bool $manual = false,
    ): WebsiteCheckResult {
        return $this->checkUntil($endpoint, $websiteId, $endpointId, $manual, microtime(true) + $endpoint->timeoutSeconds);
    }

    private function checkUntil(
        WebsiteEndpointDefinition $endpoint,
        int $websiteId,
        int $endpointId,
        bool $manual,
        float $deadline,
    ): WebsiteCheckResult {
        $checkedAt = new DateTimeImmutable();
        $configuredUrl = $endpoint->url;
        $url = $configuredUrl;
        $configuredOrigin = $this->origin($configuredUrl);
        $redirectChain = [];
        $timingTotals = ['dns_ms' => 0.0, 'tcp_ms' => 0.0, 'tls_ms' => 0.0, 'ttfb_ms' => 0.0, 'total_ms' => 0.0];
        $seen = [];

        try {
            for ($hop = 0; ; $hop++) {
                $canonicalUrl = $this->canonicalUrl($url);
                if (isset($seen[$canonicalUrl])) {
                    return $this->failure(
                        $websiteId, $endpointId, $checkedAt, $configuredUrl, $url, $redirectChain,
                        $timingTotals, WebsiteCheckError::RedirectLoop, $manual
                    );
                }
                $seen[$canonicalUrl] = true;

                $response = $this->request($url, $endpoint, $configuredOrigin, $deadline);
                $this->addTimings($timingTotals, $response['timings']);
                if ($response['error'] !== null) {
                    return $this->failure(
                        $websiteId, $endpointId, $checkedAt, $configuredUrl, $url, $redirectChain,
                        $timingTotals, $response['error'], $manual
                    );
                }

                $statusCode = $response['status_code'];
                $location = $response['location'];
                if ($statusCode >= 300 && $statusCode < 400 && $location !== null && $endpoint->followRedirects) {
                    if ($hop >= $endpoint->maxRedirects) {
                        return $this->failure(
                            $websiteId, $endpointId, $checkedAt, $configuredUrl, $url, $redirectChain,
                            $timingTotals, WebsiteCheckError::RedirectLimit, $manual, $statusCode
                        );
                    }
                    $nextUrl = $this->resolveUrl($url, $location);
                    if ($nextUrl === null) {
                        return $this->failure(
                            $websiteId, $endpointId, $checkedAt, $configuredUrl, $url, $redirectChain,
                            $timingTotals, WebsiteCheckError::RedirectScheme, $manual, $statusCode
                        );
                    }
                    $redirectChain[] = [
                        'from_url' => $url,
                        'to_url' => $nextUrl,
                        'status_code' => $statusCode,
                    ];
                    $url = $nextUrl;
                    continue;
                }

                $evaluation = $this->assertions->evaluate($statusCode, $response['body'], $endpoint);
                $assertionResults = [];
                if ($evaluation['status_passed'] !== null) {
                    $assertionResults[] = [
                        'kind' => 'status',
                        'passed' => $evaluation['status_passed'],
                        'safe_message' => $evaluation['status_passed']
                            ? 'Expected status received.'
                            : 'Expected status was not received.',
                    ];
                }
                array_push($assertionResults, ...$evaluation['content_results']);

                $slow = ($endpoint->criticalTotalMs !== null && $timingTotals['total_ms'] >= $endpoint->criticalTotalMs)
                    || ($endpoint->warningTotalMs !== null && $timingTotals['total_ms'] >= $endpoint->warningTotalMs);
                if ($endpoint->warningTotalMs !== null || $endpoint->criticalTotalMs !== null) {
                    $assertionResults[] = [
                        'kind' => 'response_time',
                        'passed' => !$slow,
                        'safe_message' => $slow ? 'Response time threshold exceeded.' : 'Response time is within threshold.',
                    ];
                }
                $passed = $evaluation['passed'] && !$slow;
                $error = !$passed
                    ? ($evaluation['status_passed'] === false
                        ? WebsiteCheckError::UnexpectedStatus
                        : ($evaluation['content_results'] !== [] && !$evaluation['passed']
                            ? WebsiteCheckError::ContentMissing
                            : WebsiteCheckError::SlowResponse))
                    : null;

                return new WebsiteCheckResult(
                    websiteId: $websiteId,
                    endpointId: $endpointId,
                    sampleId: $this->sampleId(),
                    checkedAt: $checkedAt,
                    transportAvailable: true,
                    assertionsPassed: $passed,
                    statusCode: $statusCode,
                    configuredUrl: $configuredUrl,
                    finalUrl: $url,
                    redirectChain: $redirectChain,
                    timings: $timingTotals,
                    error: $error,
                    assertionResults: $assertionResults,
                    manual: $manual,
                );
            }
        } catch (Throwable) {
            return $this->failure(
                $websiteId, $endpointId, $checkedAt, $configuredUrl, null, $redirectChain,
                $timingTotals, WebsiteCheckError::Internal, $manual
            );
        }
    }

    /**
     * @param list<array{definition:WebsiteEndpointDefinition,website_id:int,endpoint_id:int,manual:bool}> $checks
     * @return list<WebsiteCheckResult>
     */
    public function checkMany(array $checks, int $concurrency): array
    {
        if ($concurrency < 1 || $concurrency > 100) {
            throw new InvalidArgumentException('Website check concurrency must be between 1 and 100.');
        }
        if ($checks === []) {
            return [];
        }

        $multi = curl_multi_init();
        $pending = [];
        foreach (array_values($checks) as $index => $check) {
            $endpoint = $check['definition'];
            $pending[] = (object) [
                'index' => $index,
                'check' => $check,
                'url' => $endpoint->url,
                'configured_origin' => $this->origin($endpoint->url),
                'checked_at' => new DateTimeImmutable(),
                'deadline' => microtime(true) + $endpoint->timeoutSeconds,
                'redirect_chain' => [],
                'timings' => $this->emptyTimings(),
                'seen' => [$this->canonicalUrl($endpoint->url) => true],
                'hop' => 0,
            ];
        }
        $active = [];
        $results = [];

        try {
            while ($pending !== [] || $active !== []) {
                while ($pending !== [] && count($active) < $concurrency) {
                    $state = array_shift($pending);
                    $check = $state->check;
                    $endpoint = $check['definition'];
                    $remainingMs = (int) floor(($state->deadline - microtime(true)) * 1000);
                    if ($remainingMs <= 0) {
                        $results[$state->index] = $this->failure(
                            $check['website_id'], $check['endpoint_id'], $state->checked_at, $endpoint->url,
                            $state->url, $state->redirect_chain, $state->timings, WebsiteCheckError::Timeout, $check['manual']
                        );
                        continue;
                    }

                    $handle = curl_init($state->url);
                    if ($handle === false) {
                        $results[$state->index] = $this->failure(
                            $check['website_id'], $check['endpoint_id'], $state->checked_at, $endpoint->url,
                            $state->url, $state->redirect_chain, $state->timings, WebsiteCheckError::Internal, $check['manual']
                        );
                        continue;
                    }
                    $state->body = '';
                    $state->headers = [];
                    $state->too_large = false;
                    $this->configureHandle(
                        $handle,
                        $endpoint,
                        $state->url,
                        $state->configured_origin,
                        $remainingMs,
                        $state->body,
                        $state->headers,
                        $state->too_large,
                    );
                    curl_multi_add_handle($multi, $handle);
                    $active[spl_object_id($handle)] = ['handle' => $handle, 'state' => $state];
                }

                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);
                if ($status !== CURLM_OK) {
                    throw new RuntimeException('Unable to execute concurrent website checks.');
                }
                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }

                while (($completed = curl_multi_info_read($multi)) !== false) {
                    $handle = $completed['handle'];
                    $key = spl_object_id($handle);
                    $entry = $active[$key];
                    unset($active[$key]);
                    curl_multi_remove_handle($multi, $handle);
                    $info = curl_getinfo($handle);
                    $state = $entry['state'];
                    $check = $state->check;
                    $endpoint = $check['definition'];
                    $timings = $this->timings($info);
                    $this->addTimings($state->timings, $timings);
                    $error = $state->too_large
                        ? WebsiteCheckError::ResponseTooLarge
                        : $this->curlError(curl_errno($handle));
                    $statusCode = (int) ($info['http_code'] ?? 0);
                    $location = $state->headers['location'] ?? null;
                    curl_close($handle);

                    if ($error !== null) {
                        $results[$state->index] = $this->failure(
                            $check['website_id'], $check['endpoint_id'], $state->checked_at, $endpoint->url,
                            $state->url, $state->redirect_chain, $state->timings, $error, $check['manual']
                        );
                        continue;
                    }
                    if ($statusCode >= 300 && $statusCode < 400 && $location !== null && $endpoint->followRedirects) {
                        if ($state->hop >= $endpoint->maxRedirects) {
                            $results[$state->index] = $this->failure(
                                $check['website_id'], $check['endpoint_id'], $state->checked_at, $endpoint->url,
                                $state->url, $state->redirect_chain, $state->timings, WebsiteCheckError::RedirectLimit,
                                $check['manual'], $statusCode
                            );
                            continue;
                        }
                        $nextUrl = $this->resolveUrl($state->url, $location);
                        if ($nextUrl === null) {
                            $results[$state->index] = $this->failure(
                                $check['website_id'], $check['endpoint_id'], $state->checked_at, $endpoint->url,
                                $state->url, $state->redirect_chain, $state->timings, WebsiteCheckError::RedirectScheme,
                                $check['manual'], $statusCode
                            );
                            continue;
                        }
                        $state->redirect_chain[] = [
                            'from_url' => $state->url,
                            'to_url' => $nextUrl,
                            'status_code' => $statusCode,
                        ];
                        $state->url = $nextUrl;
                        $canonicalUrl = $this->canonicalUrl($nextUrl);
                        if (isset($state->seen[$canonicalUrl])) {
                            $results[$state->index] = $this->failure(
                                $check['website_id'], $check['endpoint_id'], $state->checked_at, $endpoint->url,
                                $state->url, $state->redirect_chain, $state->timings, WebsiteCheckError::RedirectLoop,
                                $check['manual'], $statusCode
                            );
                            continue;
                        }
                        $state->seen[$canonicalUrl] = true;
                        $state->hop++;
                        $pending[] = $state;
                        continue;
                    }

                    $results[$state->index] = $this->finalResult(
                        $endpoint, $check['website_id'], $check['endpoint_id'], $check['manual'], $state->checked_at,
                        $state->url, $statusCode, $state->body, $state->redirect_chain, $state->timings
                    );
                }
            }
        } finally {
            foreach ($active as $entry) {
                curl_multi_remove_handle($multi, $entry['handle']);
                curl_close($entry['handle']);
            }
            curl_multi_close($multi);
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * @return array{status_code:int,body:string,location:?string,error:?WebsiteCheckError,timings:array{dns_ms:float,tcp_ms:float,tls_ms:float,ttfb_ms:float,total_ms:float}}
     */
    private function request(
        string $url,
        WebsiteEndpointDefinition $endpoint,
        string $configuredOrigin,
        float $deadline,
    ): array {
        $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);
        if ($remainingMs <= 0) {
            return $this->requestFailure(WebsiteCheckError::Timeout);
        }

        $body = '';
        $headers = [];
        $tooLarge = false;
        $handle = curl_init($url);
        if ($handle === false) {
            return $this->requestFailure(WebsiteCheckError::Internal);
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $endpoint->method,
                CURLOPT_NOBODY => $endpoint->method === 'HEAD',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, $remainingMs),
                CURLOPT_TIMEOUT_MS => $remainingMs,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HTTPHEADER => $this->outgoingHeaders($endpoint, $url, $configuredOrigin),
            ]);
            curl_exec($handle);
            $info = curl_getinfo($handle);
            $error = $tooLarge
                ? WebsiteCheckError::ResponseTooLarge
                : $this->curlError(curl_errno($handle));

            return [
                'status_code' => (int) ($info['http_code'] ?? 0),
                'body' => $body,
                'location' => $headers['location'] ?? null,
                'error' => $error,
                'timings' => [
                    'dns_ms' => 1000 * (float) ($info['namelookup_time'] ?? 0.0),
                    'tcp_ms' => 1000 * max(0.0, (float) ($info['connect_time'] ?? 0.0) - (float) ($info['namelookup_time'] ?? 0.0)),
                    'tls_ms' => 1000 * max(0.0, (float) ($info['appconnect_time'] ?? 0.0) - (float) ($info['connect_time'] ?? 0.0)),
                    'ttfb_ms' => 1000 * (float) ($info['starttransfer_time'] ?? 0.0),
                    'total_ms' => 1000 * (float) ($info['total_time'] ?? 0.0),
                ],
            ];
        } finally {
            curl_close($handle);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function configureHandle(
        \CurlHandle $handle,
        WebsiteEndpointDefinition $endpoint,
        string $url,
        string $configuredOrigin,
        int $timeoutMs,
        string &$body,
        array &$headers,
        bool &$tooLarge,
    ): void {
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $endpoint->method,
            CURLOPT_NOBODY => $endpoint->method === 'HEAD',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HTTPHEADER => $this->outgoingHeaders($endpoint, $url, $configuredOrigin),
        ]);
    }

    /** @param array<string, mixed> $info
     * @return array{dns_ms:float,tcp_ms:float,tls_ms:float,ttfb_ms:float,total_ms:float} */
    private function timings(array $info): array
    {
        return [
            'dns_ms' => 1000 * (float) ($info['namelookup_time'] ?? 0.0),
            'tcp_ms' => 1000 * max(0.0, (float) ($info['connect_time'] ?? 0.0) - (float) ($info['namelookup_time'] ?? 0.0)),
            'tls_ms' => 1000 * max(0.0, (float) ($info['appconnect_time'] ?? 0.0) - (float) ($info['connect_time'] ?? 0.0)),
            'ttfb_ms' => 1000 * (float) ($info['starttransfer_time'] ?? 0.0),
            'total_ms' => 1000 * (float) ($info['total_time'] ?? 0.0),
        ];
    }

    /** @return array{dns_ms:float,tcp_ms:float,tls_ms:float,ttfb_ms:float,total_ms:float} */
    private function emptyTimings(): array
    {
        return ['dns_ms' => 0.0, 'tcp_ms' => 0.0, 'tls_ms' => 0.0, 'ttfb_ms' => 0.0, 'total_ms' => 0.0];
    }

    /** @param list<array<string,mixed>> $redirectChain
     * @param array{dns_ms:float,tcp_ms:float,tls_ms:float,ttfb_ms:float,total_ms:float} $timings */
    private function finalResult(WebsiteEndpointDefinition $endpoint, int $websiteId, int $endpointId, bool $manual, DateTimeImmutable $checkedAt, string $finalUrl, int $statusCode, string $body, array $redirectChain, array $timings): WebsiteCheckResult
    {
        $evaluation = $this->assertions->evaluate($statusCode, $body, $endpoint);
        $assertionResults = [];
        if ($evaluation['status_passed'] !== null) {
            $assertionResults[] = ['kind' => 'status', 'passed' => $evaluation['status_passed'], 'safe_message' => $evaluation['status_passed'] ? 'Expected status received.' : 'Expected status was not received.'];
        }
        array_push($assertionResults, ...$evaluation['content_results']);
        $slow = ($endpoint->criticalTotalMs !== null && $timings['total_ms'] >= $endpoint->criticalTotalMs)
            || ($endpoint->warningTotalMs !== null && $timings['total_ms'] >= $endpoint->warningTotalMs);
        if ($endpoint->warningTotalMs !== null || $endpoint->criticalTotalMs !== null) {
            $assertionResults[] = ['kind' => 'response_time', 'passed' => !$slow, 'safe_message' => $slow ? 'Response time threshold exceeded.' : 'Response time is within threshold.'];
        }
        $passed = $evaluation['passed'] && !$slow;
        $error = !$passed ? ($evaluation['status_passed'] === false ? WebsiteCheckError::UnexpectedStatus : ($evaluation['content_results'] !== [] && !$evaluation['passed'] ? WebsiteCheckError::ContentMissing : WebsiteCheckError::SlowResponse)) : null;

        return new WebsiteCheckResult($websiteId, $endpointId, $this->sampleId(), $checkedAt, true, $passed, $statusCode, $endpoint->url, $finalUrl, $redirectChain, $timings, $error, $assertionResults, $manual);
    }

    /** @return list<string> */
    private function outgoingHeaders(WebsiteEndpointDefinition $endpoint, string $url, string $configuredOrigin): array
    {
        $trusted = $this->origin($url) === $configuredOrigin
            || in_array($this->origin($url), $endpoint->credentialRedirectHosts, true);
        if (!$trusted) {
            return [];
        }
        $headers = [];
        foreach ($endpoint->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        if ($endpoint->authType === 'bearer' && $endpoint->authSecret !== null) {
            $headers[] = 'Authorization: Bearer ' . $endpoint->authSecret;
        }
        if ($endpoint->authType === 'basic' && $endpoint->authUsername !== null && $endpoint->authSecret !== null) {
            $headers[] = 'Authorization: Basic ' . base64_encode($endpoint->authUsername . ':' . $endpoint->authSecret);
        }

        return $headers;
    }

    private function resolveUrl(string $base, string $location): ?string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $this->validHttpUrl($location) ? $location : null;
        }
        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (str_starts_with($location, '//')) {
            $location = $parts['scheme'] . ':' . $location;

            return $this->validHttpUrl($location) ? $location : null;
        }
        $origin = $this->origin($base);
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path . '/', '/'));

        return $origin . ($directory === '' ? '/' : $directory . '/') . $location;
    }

    private function validHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Website URL is invalid.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $portPart = $port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))
            ? ':' . $port
            : '';

        return $scheme . '://' . $host . $portPart;
    }

    private function canonicalUrl(string $url): string
    {
        return $this->origin($url) . (parse_url($url, PHP_URL_PATH) ?: '/')
            . (($query = parse_url($url, PHP_URL_QUERY)) === null ? '' : '?' . $query);
    }

    /** @param array{dns_ms:float,tcp_ms:float,tls_ms:float,ttfb_ms:float,total_ms:float} $totals
     * @param array{dns_ms:float,tcp_ms:float,tls_ms:float,ttfb_ms:float,total_ms:float} $hop */
    private function addTimings(array &$totals, array $hop): void
    {
        $priorTotalMs = $totals['total_ms'];
        foreach (['dns_ms', 'tcp_ms', 'tls_ms'] as $name) {
            $totals[$name] += $hop[$name];
        }
        $totals['ttfb_ms'] = $priorTotalMs + $hop['ttfb_ms'];
        $totals['total_ms'] = $priorTotalMs + $hop['total_ms'];
    }

    /** @return array{status_code:int,body:string,location:?string,error:WebsiteCheckError,timings:array{dns_ms:float,tcp_ms:float,tls_ms:float,ttfb_ms:float,total_ms:float}} */
    private function requestFailure(WebsiteCheckError $error): array
    {
        return ['status_code' => 0, 'body' => '', 'location' => null, 'error' => $error, 'timings' => ['dns_ms' => 0.0, 'tcp_ms' => 0.0, 'tls_ms' => 0.0, 'ttfb_ms' => 0.0, 'total_ms' => 0.0]];
    }

    private function curlError(int $code): ?WebsiteCheckError
    {
        return match ($code) {
            CURLE_OK => null,
            CURLE_COULDNT_RESOLVE_HOST => WebsiteCheckError::Dns,
            CURLE_COULDNT_CONNECT => WebsiteCheckError::Connect,
            CURLE_OPERATION_TIMEDOUT => WebsiteCheckError::Timeout,
            CURLE_SSL_CONNECT_ERROR, CURLE_SSL_PEER_CERTIFICATE, CURLE_SSL_CERTPROBLEM => WebsiteCheckError::Tls,
            default => WebsiteCheckError::Internal,
        };
    }

    /** @param list<array<string,mixed>> $redirectChain
     * @param array{dns_ms:float,tcp_ms:float,tls_ms:float,ttfb_ms:float,total_ms:float} $timings */
    private function failure(int $websiteId, int $endpointId, DateTimeImmutable $checkedAt, string $configuredUrl, ?string $finalUrl, array $redirectChain, array $timings, WebsiteCheckError $error, bool $manual, ?int $statusCode = null): WebsiteCheckResult
    {
        return new WebsiteCheckResult($websiteId, $endpointId, $this->sampleId(), $checkedAt, false, false, $statusCode, $configuredUrl, $finalUrl, $redirectChain, $timings, $error, [], $manual);
    }

    private function sampleId(): string
    {
        $bytes = bin2hex(random_bytes(16));

        return substr($bytes, 0, 8) . '-' . substr($bytes, 8, 4) . '-4' . substr($bytes, 13, 3)
            . '-' . dechex((hexdec($bytes[16]) & 0x3) | 0x8) . substr($bytes, 17, 3) . '-' . substr($bytes, 20);
    }
}
