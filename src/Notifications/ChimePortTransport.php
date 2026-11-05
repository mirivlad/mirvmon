<?php

declare(strict_types=1);

namespace App\Notifications;

use Closure;
use JsonException;

final class ChimePortTransport
{
    /** @var Closure(string, array<int, mixed>): array{status:int, body:string, retry_after:?int} */
    private readonly Closure $execute;

    /** @param null|callable(string, array<int,mixed>): array{status:int,body:string,retry_after:?int} $execute */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $tokenFile,
        ?callable $execute = null,
        private readonly ?string $tokenOverride = null,
        private readonly ?string $channelOverride = null
    ) {
        $this->execute = $execute === null
            ? self::defaultExecutor(...)
            : Closure::fromCallable($execute);
    }

    public static function enabledFromEnvironment(): bool
    {
        return getenv('CHIMEPORT_ENABLED') === '1'
            && trim((string) getenv('CHIMEPORT_BASE_URL')) !== ''
            && trim((string) getenv('CHIMEPORT_TOKEN_FILE')) !== '';
    }

    /** @param array<string,mixed> $job */
    public function send(array $job, string $subject, string $message): void
    {
        $url = $this->endpoint();
        $token = $this->tokenOverride ?? @file_get_contents($this->tokenFile);
        if (!is_string($token) || !preg_match('/^cp_t_[A-Za-z0-9_-]{35,}$/', trim($token))) {
            throw new NotificationTransportException('chimeport_token_unavailable');
        }

        $eventType = (string) ($job['event_type'] ?? '');
        $id = filter_var($job['id'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            throw new NotificationTransportException('chimeport_job_id_invalid');
        }

        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $recovered = str_ends_with($eventType, '_recovered') || $eventType === 'alert_resolved';
        $priority = $recovered || $eventType === 'test'
            ? 'normal'
            : (str_starts_with($eventType, 'offline_')
                || str_starts_with($eventType, 'service_')
                || ($payload['severity'] ?? null) === 'critical'
                ? 'urgent' : 'high');
        $channel = match (true) {
            str_starts_with($eventType, 'service_') => 'monitor/services',
            str_starts_with($eventType, 'website_') => 'monitor/websites',
            $eventType === 'test', str_starts_with($eventType, 'connectivity_') => 'monitor/system',
            default => 'monitor/hosts',
        };
        // Optional single-channel routing configured in ChimePort by slug.
        $override = trim($this->channelOverride ?? (string) getenv('CHIMEPORT_CHANNEL'));
        if ($override !== '') {
            if (strlen($override) > 80
                || !preg_match('~^[a-z0-9][a-z0-9_/-]{0,79}$~D', $override)
                || str_contains($override, '//')) {
                throw new NotificationTransportException('chimeport_channel_invalid');
            }
            $channel = $override;
        }
        try {
            $json = json_encode([
                'channel' => $channel,
                'title' => mb_substr($subject, 0, 200),
                'body' => mb_strcut($message, 0, 16384),
                'priority' => $priority,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new NotificationTransportException('chimeport_payload_invalid', 0, $e);
        }
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . trim($token),
                'Idempotency-Key: mirvmon-outbox-' . $id,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $result = ($this->execute)($url, $options);
        if ($result['status'] === 201 || $result['status'] === 200) {
            $response = json_decode($result['body'], true);
            if (!is_array($response)
                || (($response['stored'] ?? false) !== true
                    && ($response['duplicate'] ?? false) !== true)) {
                throw new NotificationTransportException('chimeport_response_invalid');
            }
            return; // 200 is also the idempotent replay result.
        }
        if ($result['status'] === 429) {
            $retryAfter = $result['retry_after'] ?? null;
            throw new NotificationTransportException(
                'chimeport_rate_limited', 0, null, $retryAfter);
        }
        // Do not include HTTP response bodies or URLs in errors: they may contain secrets.
        throw new NotificationTransportException('chimeport_http_' . $result['status']);
    }

    private function endpoint(): string
    {
        $parts = parse_url($this->baseUrl);
        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || !is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['path']) && !in_array($parts['path'], ['', '/'], true)) {
            throw new NotificationTransportException('chimeport_url_invalid');
        }
        return rtrim($this->baseUrl, '/') . '/api/v1/messages';
    }

    /** @param array<int,mixed> $options
     *  @return array{status:int,body:string,retry_after:?int}
     */
    private static function defaultExecutor(string $url, array $options): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new NotificationTransportException('chimeport_client_init_failed');
        }
        $retryAfter = null;
        $options[CURLOPT_HEADERFUNCTION] = static function (mixed $handle, string $line) use (&$retryAfter): int {
            if (preg_match('/^Retry-After:\\s*(\\d+)\\s*$/i', trim($line), $m)) {
                $retryAfter = min(3600, max(1, (int) $m[1]));
            }
            return strlen($line);
        };
        try {
            if (!curl_setopt_array($curl, $options)) {
                throw new NotificationTransportException('chimeport_client_config_failed');
            }
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if (!is_string($body)) {
                throw new NotificationTransportException('chimeport_network_failed');
            }
            return ['status' => $status, 'body' => $body, 'retry_after' => $retryAfter];
        } finally {
            curl_close($curl);
        }
    }
}
