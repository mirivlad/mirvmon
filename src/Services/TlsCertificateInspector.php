<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Websites\TlsInspectionResult;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class TlsCertificateInspector
{
    public function inspect(
        ?int $targetId,
        int $endpointId,
        string $hostname,
        int $port,
        bool $allowSelfSigned,
        DateTimeImmutable $now,
    ): TlsInspectionResult {
        if ($endpointId <= 0 || $hostname === '' || $port < 1 || $port > 65535) {
            return $this->failure($targetId, $endpointId, $hostname, $port, $now, 'invalid_target');
        }

        try {
            $context = stream_context_create(['ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => !$allowSelfSigned,
                'verify_peer_name' => !$allowSelfSigned,
                'peer_name' => $hostname,
                'SNI_enabled' => true,
                'SNI_server_name' => $hostname,
            ]]);
            set_error_handler(static fn (): bool => true);
            try {
                $socket = stream_socket_client(
                    'tls://' . $this->socketHostname($hostname) . ':' . $port,
                    $errorCode,
                    $errorMessage,
                    10.0,
                    STREAM_CLIENT_CONNECT,
                    $context,
                );
            } finally {
                restore_error_handler();
            }
            if (!is_resource($socket)) {
                return $this->failure(
                    $targetId,
                    $endpointId,
                    $hostname,
                    $port,
                    $now,
                    $allowSelfSigned ? 'handshake_failed' : 'untrusted_chain',
                );
            }
            fclose($socket);

            $parameters = stream_context_get_params($context);
            $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;
            if ($certificate === null) {
                return $this->failure($targetId, $endpointId, $hostname, $port, $now, 'certificate_missing');
            }
            $parsed = openssl_x509_parse($certificate, false);
            if (!is_array($parsed)) {
                return $this->failure($targetId, $endpointId, $hostname, $port, $now, 'certificate_invalid');
            }
            $notBefore = $this->timestamp($parsed['validFrom_time_t'] ?? null);
            $notAfter = $this->timestamp($parsed['validTo_time_t'] ?? null);
            $sans = $this->sans($parsed['extensions']['subjectAltName'] ?? null);
            $subject = $this->distinguishedName($parsed['subject'] ?? null);
            $issuer = $this->distinguishedName($parsed['issuer'] ?? null);
            $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

            if ($notBefore === null || $notAfter === null || $fingerprint === false) {
                return $this->failure($targetId, $endpointId, $hostname, $port, $now, 'certificate_invalid');
            }
            if ($now < $notBefore) {
                return $this->result($targetId, $endpointId, $hostname, $port, $now, false, 'certificate_not_yet_valid', $subject, $issuer, $sans, $fingerprint, $notBefore, $notAfter);
            }
            if ($now >= $notAfter) {
                return $this->result($targetId, $endpointId, $hostname, $port, $now, false, 'certificate_expired', $subject, $issuer, $sans, $fingerprint, $notBefore, $notAfter);
            }
            if ($allowSelfSigned && !$this->hostnameMatches($hostname, $sans, $parsed['subject']['CN'] ?? null)) {
                return $this->result($targetId, $endpointId, $hostname, $port, $now, false, 'hostname_mismatch', $subject, $issuer, $sans, $fingerprint, $notBefore, $notAfter);
            }

            return $this->result($targetId, $endpointId, $hostname, $port, $now, true, null, $subject, $issuer, $sans, $fingerprint, $notBefore, $notAfter);
        } catch (Throwable) {
            return $this->failure($targetId, $endpointId, $hostname, $port, $now, 'internal');
        }
    }

    private function socketHostname(string $hostname): string
    {
        return str_contains($hostname, ':') ? '[' . $hostname . ']' : $hostname;
    }

    /** @param array<string, mixed>|null $name */
    private function distinguishedName(?array $name): ?string
    {
        if ($name === null || $name === []) {
            return null;
        }
        $parts = [];
        foreach ($name as $key => $value) {
            $parts[] = $key . '=' . (is_array($value) ? implode('+', $value) : (string) $value);
        }

        return implode(', ', $parts);
    }

    /** @return list<string> */
    private function sans(mixed $subjectAltName): array
    {
        if (!is_string($subjectAltName) || $subjectAltName === '') {
            return [];
        }
        preg_match_all('/(?:^|,)\\s*DNS:([^,]+)/i', $subjectAltName, $matches);

        return array_values(array_map(
            static fn (string $name): string => strtolower(rtrim(trim($name), '.')),
            $matches[1],
        ));
    }

    /** @param list<string> $sans */
    private function hostnameMatches(string $hostname, array $sans, mixed $commonName): bool
    {
        $names = $sans === [] && is_string($commonName) ? [$commonName] : $sans;
        $hostname = strtolower(rtrim($hostname, '.'));
        foreach ($names as $name) {
            $name = strtolower(rtrim($name, '.'));
            if ($name === $hostname) {
                return true;
            }
            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1);
                if (str_ends_with($hostname, $suffix)
                    && substr_count($hostname, '.') === substr_count($suffix, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function timestamp(mixed $timestamp): ?DateTimeImmutable
    {
        if (!is_int($timestamp) && !is_numeric($timestamp)) {
            return null;
        }

        return (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    /** @param list<string> $sans */
    private function result(?int $targetId, int $endpointId, string $hostname, int $port, DateTimeImmutable $checkedAt, bool $valid, ?string $errorKind, ?string $subject, ?string $issuer, array $sans, string $fingerprint, DateTimeImmutable $notBefore, DateTimeImmutable $notAfter): TlsInspectionResult
    {
        return new TlsInspectionResult($endpointId, $hostname, $port, $checkedAt, $valid, $errorKind, $subject, $issuer, $sans, $fingerprint, $notBefore, $notAfter, $targetId);
    }

    private function failure(?int $targetId, int $endpointId, string $hostname, int $port, DateTimeImmutable $checkedAt, string $errorKind): TlsInspectionResult
    {
        return new TlsInspectionResult($endpointId, $hostname, $port, $checkedAt, false, $errorKind, null, null, [], null, null, null, $targetId);
    }
}
