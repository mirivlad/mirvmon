<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;

final class ConnectivityStateRepository
{
    public const SETTING_KEY = 'mirvmon_external_connectivity';

    public function __construct(private readonly AppSettingsRepository $settings)
    {
    }

    /**
     * @param array{available:bool,successes:int,failures:int,successful_targets:list<string>,failed_targets:list<string>} $probe
     */
    public function record(array $probe, DateTimeImmutable $now): void
    {
        $current = $this->current();
        $state = $probe['available'] ? 'online' : 'offline';
        $changedAt = $current !== null && $current['state'] === $state
            ? $current['changed_at']
            : $now->format(DATE_ATOM);

        $this->settings->set(self::SETTING_KEY, [
            'state' => $state,
            'checked_at' => $now->format(DATE_ATOM),
            'changed_at' => $changedAt,
            'successes' => $probe['successes'],
            'failures' => $probe['failures'],
            'successful_targets' => $probe['successful_targets'],
            'failed_targets' => $probe['failed_targets'],
        ]);
    }

    /**
     * @return null|array{state:string,checked_at:string,changed_at:string,successes:int,failures:int,successful_targets:list<string>,failed_targets:list<string>}
     */
    public function current(): ?array
    {
        $value = $this->settings->get(self::SETTING_KEY);
        if (!is_array($value)) {
            return null;
        }
        $state = $value['state'] ?? null;
        $checkedAt = $value['checked_at'] ?? null;
        $changedAt = $value['changed_at'] ?? null;
        if (!in_array($state, ['online', 'offline'], true)
            || !is_string($checkedAt) || !is_string($changedAt)) {
            return null;
        }

        return [
            'state' => $state,
            'checked_at' => $checkedAt,
            'changed_at' => $changedAt,
            'successes' => (int) ($value['successes'] ?? 0),
            'failures' => (int) ($value['failures'] ?? 0),
            'successful_targets' => $this->stringList($value['successful_targets'] ?? []),
            'failed_targets' => $this->stringList($value['failed_targets'] ?? []),
        ];
    }

    public function freshState(DateTimeImmutable $now, int $maxAgeSeconds): string
    {
        $current = $this->current();
        if ($current === null) {
            return 'unknown';
        }

        try {
            $checkedAt = new DateTimeImmutable($current['checked_at']);
        } catch (\Throwable) {
            return 'unknown';
        }

        return $now->getTimestamp() - $checkedAt->getTimestamp() <= $maxAgeSeconds
            ? $current['state']
            : 'unknown';
    }

    public function offlineAndFresh(DateTimeImmutable $now, int $maxAgeSeconds): bool
    {
        return $this->freshState($now, $maxAgeSeconds) === 'offline';
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)));
    }
}
