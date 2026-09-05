<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppSettingsRepository;
use InvalidArgumentException;

final class ConnectivitySettingsService
{
    public const SETTING_KEY = 'mirvmon_connectivity_settings';

    /** @var list<string> */
    private const DEFAULT_TARGETS = [
        'one.one.one.one:443',
        'dns.google:443',
        'dns.quad9.net:443',
    ];

    public function __construct(private readonly AppSettingsRepository $settings)
    {
    }

    /** @return array{targets:list<string>,quorum:int,timeout_seconds:float,interval_seconds:int,source:string} */
    public function current(): array
    {
        $stored = $this->settings->get(self::SETTING_KEY);
        if (is_array($stored)) {
            try {
                return $this->normalize($stored) + ['source' => 'database'];
            } catch (InvalidArgumentException) {
                // Fall back to deployment defaults if an old/manual value is malformed.
            }
        }

        return $this->environmentDefaults() + ['source' => 'environment'];
    }

    /**
     * @param array<string, mixed> $value
     * @return array{targets:list<string>,quorum:int,timeout_seconds:float,interval_seconds:int,source:string}
     */
    public function save(array $value): array
    {
        $normalized = $this->normalize($value);
        $this->settings->set(self::SETTING_KEY, $normalized);

        return $normalized + ['source' => 'database'];
    }

    /** @return array{targets:list<string>,quorum:int,timeout_seconds:float,interval_seconds:int} */
    private function environmentDefaults(): array
    {
        $targets = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) (getenv('CONNECTIVITY_PROBE_TARGETS') ?: implode(',', self::DEFAULT_TARGETS)))
        )));
        $candidate = [
            'targets' => $targets,
            'quorum' => getenv('CONNECTIVITY_PROBE_QUORUM') ?: 2,
            'timeout_seconds' => getenv('CONNECTIVITY_PROBE_TIMEOUT') ?: 1.0,
            'interval_seconds' => getenv('CONNECTIVITY_CHECK_INTERVAL') ?: 15,
        ];

        try {
            return $this->normalize($candidate);
        } catch (InvalidArgumentException) {
            return [
                'targets' => self::DEFAULT_TARGETS,
                'quorum' => 2,
                'timeout_seconds' => 1.0,
                'interval_seconds' => 15,
            ];
        }
    }

    /**
     * @param array<string, mixed> $value
     * @return array{targets:list<string>,quorum:int,timeout_seconds:float,interval_seconds:int}
     */
    private function normalize(array $value): array
    {
        $rawTargets = $value['targets'] ?? null;
        if (!is_array($rawTargets)) {
            throw new InvalidArgumentException('Connectivity targets must be a list.');
        }

        $targets = [];
        foreach ($rawTargets as $rawTarget) {
            if (!is_string($rawTarget) || trim($rawTarget) === '') {
                continue;
            }
            $target = $this->normalizeTarget($rawTarget);
            if (!in_array($target, $targets, true)) {
                $targets[] = $target;
            }
        }
        if ($targets === [] || count($targets) > 10) {
            throw new InvalidArgumentException('Connectivity target count must be between 1 and 10.');
        }

        $quorum = filter_var($value['quorum'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => count($targets)],
        ]);
        $interval = filter_var($value['interval_seconds'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 5, 'max_range' => 300],
        ]);
        $timeout = filter_var($value['timeout_seconds'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($quorum === false || $interval === false || $timeout === false || $timeout < 0.1 || $timeout > 10.0) {
            throw new InvalidArgumentException('Connectivity timing or quorum is invalid.');
        }

        return [
            'targets' => $targets,
            'quorum' => $quorum,
            'timeout_seconds' => (float) $timeout,
            'interval_seconds' => $interval,
        ];
    }

    private function normalizeTarget(string $value): string
    {
        $value = trim($value);
        $host = '';
        $portText = '';
        $ipv6 = false;

        if (preg_match('/^\[([^]]+)]:(\d{1,5})$/', $value, $matches) === 1) {
            $host = $matches[1];
            $portText = $matches[2];
            $ipv6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (!$ipv6) {
                throw new InvalidArgumentException('Invalid IPv6 connectivity target.');
            }
        } elseif (substr_count($value, ':') === 1) {
            [$host, $portText] = explode(':', $value, 2);
            $host = rtrim(strtolower(trim($host)), '.');
            if (!$this->validHost($host)) {
                throw new InvalidArgumentException('Invalid connectivity target host.');
            }
        } else {
            throw new InvalidArgumentException('Connectivity target must use host:port.');
        }

        $port = filter_var($portText, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new InvalidArgumentException('Invalid connectivity target port.');
        }

        return $ipv6 ? '[' . strtolower($host) . ']:' . $port : $host . ':' . $port;
    }

    private function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        return preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/',
            $host
        ) === 1;
    }
}
