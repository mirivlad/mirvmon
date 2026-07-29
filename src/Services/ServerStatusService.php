<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;

final class ServerStatusService
{
    /**
     * @param array<string, mixed> $server
     * @return 'online'|'warning'|'critical'|'offline'
     */
    public function status(array $server, ?DateTimeImmutable $now = null): string
    {
        if (!$this->isActive($server['is_active'] ?? true)) {
            return 'offline';
        }

        $lastMetricsAt = $this->date($server['last_metrics_at'] ?? null);
        if ($lastMetricsAt === null) {
            return 'offline';
        }

        $now ??= new DateTimeImmutable();
        $offlineTimeout = max(0, (int) ($server['offline_timeout_seconds'] ?? 300));
        if (
            $offlineTimeout > 0
            && $now->getTimestamp() - $lastMetricsAt->getTimestamp() > $offlineTimeout
        ) {
            return 'offline';
        }
        if ((int) ($server['critical_alerts'] ?? 0) > 0) {
            return 'critical';
        }
        if ((int) ($server['warning_alerts'] ?? 0) > 0) {
            return 'warning';
        }

        return 'online';
    }

    /**
     * @param list<array<string, mixed>> $servers
     * @return list<array<string, mixed>>
     */
    public function enrich(array $servers, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        return array_map(function (array $server) use ($now): array {
            $lastMetricsAt = $this->date($server['last_metrics_at'] ?? null);
            $server['seconds_since_update'] = $lastMetricsAt === null
                ? null
                : max(0, $now->getTimestamp() - $lastMetricsAt->getTimestamp());
            $server['status'] = $this->status($server, $now);

            return $server;
        }, $servers);
    }

    /**
     * @param list<array<string, mixed>> $servers
     * @return array{
     *     total_servers: int,
     *     servers_with_metrics: int,
     *     total_groups: int,
     *     alerts_count: int,
     *     warnings: int,
     *     criticals: int
     * }
     */
    public function summary(array $servers, int $totalGroups): array
    {
        $online = 0;
        $alerts = 0;
        $warnings = 0;
        $criticals = 0;

        foreach ($servers as $server) {
            if (($server['status'] ?? 'offline') !== 'offline') {
                $online++;
            }
            $alerts += (int) ($server['active_alerts'] ?? 0);
            $warnings += (int) ($server['warning_alerts'] ?? 0);
            $criticals += (int) ($server['critical_alerts'] ?? 0);
        }

        return [
            'total_servers' => count($servers),
            'servers_with_metrics' => $online,
            'total_groups' => $totalGroups,
            'alerts_count' => $alerts,
            'warnings' => $warnings,
            'criticals' => $criticals,
        ];
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }

    private function isActive(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array($value, ['1', 't', 'true'], true);
    }
}
