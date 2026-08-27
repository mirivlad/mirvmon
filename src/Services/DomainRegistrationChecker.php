<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Websites\DomainRegistrationResult;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class DomainRegistrationChecker
{
    private const MAX_BODY_BYTES = 1048576;

    private readonly RegistrationDomainNormalizer $normalizer;

    public function __construct(
        ?RegistrationDomainNormalizer $normalizer = null,
        private readonly string $bootstrapPath = '',
        private readonly string $whoisProfilesPath = '',
    ) {
        $this->normalizer = $normalizer ?? new RegistrationDomainNormalizer();
    }

    public function check(string $domain, DateTimeImmutable $now): DomainRegistrationResult
    {
        $domain = $this->normalizer->normalize($domain);
        $bootstrap = $this->bootstrap();
        $baseUrl = $this->rdapBaseUrl($bootstrap, $domain);
        if ($baseUrl !== null) {
            $rdap = $this->rdap($baseUrl . 'domain/' . rawurlencode($domain));
            if ($rdap['kind'] === 'rate_limited') {
                return $this->result($domain, $now, 'unknown', null, null, 'rdap', 'rate_limited', $rdap['retry_after']);
            }
            if ($rdap['kind'] === 'success') {
                $parsed = $this->rdapResult($rdap['body']);
                if ($parsed !== null) {
                    return $this->result($domain, $now, 'supported', $parsed['expires_at'], $parsed['registrar'], 'rdap', null);
                }

                return $this->result($domain, $now, 'unknown', null, null, 'rdap', 'rdap_malformed');
            }
        }

        $profile = $this->whoisProfile($domain);
        if ($profile === null) {
            return $this->result($domain, $now, 'unsupported', null, null, null, 'unsupported_zone');
        }
        $whois = $this->whois($domain, $profile);
        if ($whois === null) {
            return $this->result($domain, $now, 'unknown', null, null, 'whois', 'whois_transport');
        }
        $expiresAt = $this->whoisExpiry($whois, $profile['patterns']);
        if ($expiresAt === null) {
            return $this->result($domain, $now, 'unknown', null, null, 'whois', 'whois_malformed');
        }

        return $this->result($domain, $now, 'supported', $expiresAt, null, 'whois', null);
    }

    /** @return array<string, mixed> */
    private function bootstrap(): array
    {
        $path = $this->bootstrapPath !== ''
            ? $this->bootstrapPath
            : dirname(__DIR__, 2) . '/resources/domain/iana-rdap-bootstrap.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $bootstrap */
    private function rdapBaseUrl(array $bootstrap, string $domain): ?string
    {
        $services = $bootstrap['services'] ?? null;
        if (!is_array($services)) {
            return null;
        }
        $matches = [];
        foreach ($services as $service) {
            if (!is_array($service) || !isset($service[0], $service[1]) || !is_array($service[0]) || !is_array($service[1])) {
                continue;
            }
            foreach ($service[0] as $suffix) {
                if (is_string($suffix) && ($domain === $suffix || str_ends_with($domain, '.' . $suffix))) {
                    foreach ($service[1] as $url) {
                        if (is_string($url) && preg_match('#^https?://#i', $url) === 1) {
                            $matches[] = ['suffix' => $suffix, 'url' => rtrim($url, '/') . '/'];
                            break;
                        }
                    }
                }
            }
        }

        if ($matches === []) {
            return null;
        }
        usort($matches, static fn (array $left, array $right): int => strlen($right['suffix']) <=> strlen($left['suffix']));

        return $matches[0]['url'];
    }

    /** @return array{kind:string,body:string,retry_after:?int} */
    private function rdap(string $url): array
    {
        $body = '';
        $headers = [];
        $tooLarge = false;
        $curl = curl_init($url);
        if ($curl === false) {
            return ['kind' => 'transport', 'body' => '', 'retry_after' => null];
        }
        try {
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT_MS => 5000,
                CURLOPT_TIMEOUT_MS => 15000,
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
            ]);
            curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status === 429) {
                $retryAfter = isset($headers['retry-after']) && ctype_digit($headers['retry-after'])
                    ? min(86400, max(1, (int) $headers['retry-after']))
                    : 3600;

                return ['kind' => 'rate_limited', 'body' => '', 'retry_after' => $retryAfter];
            }
            if (!$tooLarge && curl_errno($curl) === CURLE_OK && $status >= 200 && $status < 300) {
                return ['kind' => 'success', 'body' => $body, 'retry_after' => null];
            }

            return ['kind' => 'transport', 'body' => '', 'retry_after' => null];
        } finally {
            curl_close($curl);
        }
    }

    /** @return array{expires_at:DateTimeImmutable,registrar:?string}|null */
    private function rdapResult(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['events']) || !is_array($decoded['events'])) {
            return null;
        }
        foreach ($decoded['events'] as $event) {
            if (!is_array($event) || strtolower((string) ($event['eventAction'] ?? '')) !== 'expiration') {
                continue;
            }
            try {
                $expiresAt = new DateTimeImmutable((string) ($event['eventDate'] ?? ''));
            } catch (Throwable) {
                return null;
            }
            $registrar = null;
            foreach ($decoded['entities'] ?? [] as $entity) {
                if (!is_array($entity) || !in_array('registrar', $entity['roles'] ?? [], true)) {
                    continue;
                }
                $vcard = $entity['vcardArray'][1] ?? [];
                foreach ($vcard as $field) {
                    if (is_array($field) && ($field[0] ?? null) === 'fn' && isset($field[3]) && is_string($field[3])) {
                        $registrar = mb_substr($field[3], 0, 500);
                        break 2;
                    }
                }
            }

            return ['expires_at' => $expiresAt->setTimezone(new DateTimeZone('UTC')), 'registrar' => $registrar];
        }

        return null;
    }

    /** @return array{server:string,patterns:list<string>}|null */
    private function whoisProfile(string $domain): ?array
    {
        $path = $this->whoisProfilesPath !== ''
            ? $this->whoisProfilesPath
            : dirname(__DIR__, 2) . '/resources/domain/whois-profiles.php';
        $profiles = is_file($path) ? require $path : [];
        if (!is_array($profiles) || !isset($profiles['zones']) || !is_array($profiles['zones'])) {
            return null;
        }
        $matches = [];
        foreach ($profiles['zones'] as $zone => $profile) {
            if (is_string($zone) && is_array($profile) && ($domain === $zone || str_ends_with($domain, '.' . $zone))) {
                $matches[] = ['zone' => $zone, 'profile' => $profile];
            }
        }

        if ($matches !== []) {
            usort($matches, static fn (array $left, array $right): int => strlen($right['zone']) <=> strlen($left['zone']));

            return $matches[0]['profile'];
        }

        return null;
    }

    /** @param array{server:string,patterns:list<string>} $profile */
    private function whois(string $domain, array $profile): ?string
    {
        $body = $this->whoisRequest($domain, $profile['server']);
        if ($body === null) {
            return null;
        }
        if (preg_match('/^Whois Server:\s*([A-Za-z0-9.-]+(?::[0-9]{1,5})?)\s*$/mi', $body, $matches) !== 1) {
            return $body;
        }

        return $this->whoisRequest($domain, $matches[1]);
    }

    private function whoisRequest(string $domain, string $server): ?string
    {
        [$host, $port] = array_pad(explode(':', $server, 2), 2, '43');
        if ($host === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            return null;
        }
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client('tcp://' . $host . ':' . (int) $port, $errorCode, $errorMessage, 10.0);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($socket)) {
            return null;
        }
        try {
            stream_set_timeout($socket, 10);
            fwrite($socket, $domain . "\r\n");
            $body = '';
            while (!feof($socket) && strlen($body) <= self::MAX_BODY_BYTES) {
                $chunk = fread($socket, 8192);
                if ($chunk === false) {
                    return null;
                }
                $body .= $chunk;
            }

            return strlen($body) <= self::MAX_BODY_BYTES ? $body : null;
        } finally {
            fclose($socket);
        }
    }

    /** @param list<string> $patterns */
    private function whoisExpiry(string $body, array $patterns): ?DateTimeImmutable
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches) !== 1) {
                continue;
            }
            try {
                return (new DateTimeImmutable(trim($matches[1])))->setTimezone(new DateTimeZone('UTC'));
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function result(string $domain, DateTimeImmutable $now, string $status, ?DateTimeImmutable $expiresAt, ?string $registrar, ?string $source, ?string $errorKind, ?int $retryAfter = null): DomainRegistrationResult
    {
        return new DomainRegistrationResult($domain, $now, $status, $expiresAt, $registrar, $source, $errorKind, $retryAfter);
    }
}
