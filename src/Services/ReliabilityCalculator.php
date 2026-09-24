<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

final class ReliabilityCalculator
{
    /**
     * @param list<array{state:string,occurred_at:string}> $events Includes the last event before the range, if any.
     * @return array{known_seconds:int,online_seconds:int,offline_seconds:int,outages:int,availability_percent:?float}
     */
    public function serverAvailability(array $events, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $cursor = $start->getTimestamp();
        $endTime = $end->getTimestamp();
        $state = null;
        $online = 0;
        $offline = 0;
        $outages = 0;

        foreach ($events as $event) {
            $at = (new DateTimeImmutable($event['occurred_at']))->getTimestamp();
            if ($at > $endTime) {
                break;
            }
            if ($state !== null && $at > $cursor) {
                if ($state === 'online') {
                    $online += $at - $cursor;
                } else {
                    $offline += $at - $cursor;
                }
            }
            if ($event['state'] === 'offline' && $state !== 'offline' && $at >= $start->getTimestamp()) {
                $outages++;
            }
            $state = $event['state'];
            $cursor = max($cursor, $at);
        }

        if ($state !== null && $cursor < $endTime) {
            if ($state === 'online') {
                $online += $endTime - $cursor;
            } else {
                $offline += $endTime - $cursor;
            }
        }

        $known = $online + $offline;
        return [
            'known_seconds' => $known,
            'online_seconds' => $online,
            'offline_seconds' => $offline,
            'outages' => $outages,
            'availability_percent' => $known > 0 ? round(100 * $online / $known, 2) : null,
        ];
    }

    /** @return array{observed:int,expected:int,coverage_percent:float} */
    public function coverage(int $observed, DateTimeImmutable $start, DateTimeImmutable $end, int $intervalSeconds): array
    {
        $seconds = max(0, $end->getTimestamp() - $start->getTimestamp());
        $expected = $intervalSeconds > 0 ? (int) ceil($seconds / $intervalSeconds) : 0;
        return [
            'observed' => $observed,
            'expected' => $expected,
            'coverage_percent' => $expected > 0 ? round(100 * min($observed, $expected) / $expected, 2) : 0.0,
        ];
    }
}
