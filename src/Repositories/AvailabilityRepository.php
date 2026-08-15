<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use PDO;

final class AvailabilityRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Record an online/offline transition. Returns true only when the state changed.
     */
    public function mark(int $serverId, string $state, ?DateTimeImmutable $at = null): bool
    {
        if (!in_array($state, ['online', 'offline'], true)) {
            throw new \InvalidArgumentException('Availability state must be online or offline.');
        }
        $at ??= new DateTimeImmutable();

        $statement = $this->pdo->prepare(
            'SELECT state
             FROM server_availability_state
             WHERE server_id = :server_id
             FOR UPDATE'
        );
        $statement->execute(['server_id' => $serverId]);
        $previous = $statement->fetchColumn();
        if ($previous === $state) {
            return false;
        }

        $upsert = $this->pdo->prepare(
            'INSERT INTO server_availability_state (server_id, state, changed_at)
             VALUES (:server_id, :state, :changed_at)
             ON CONFLICT (server_id) DO UPDATE SET
                state = EXCLUDED.state,
                changed_at = EXCLUDED.changed_at'
        );
        $upsert->execute([
            'server_id' => $serverId,
            'state' => $state,
            'changed_at' => $this->timestamp($at),
        ]);

        $event = $this->pdo->prepare(
            'INSERT INTO server_availability_events (server_id, state, occurred_at)
             VALUES (:server_id, :state, :occurred_at)'
        );
        $event->execute([
            'server_id' => $serverId,
            'state' => $state,
            'occurred_at' => $this->timestamp($at),
        ]);

        return true;
    }

    /**
     * @return array{
     *   known: bool,
     *   points: list<array{time: string, value: int}>,
     *   availability_percent: ?float,
     *   downtime_seconds: int,
     *   outages: int
     * }
     */
    public function timeline(
        int $serverId,
        DateTimeInterface $start,
        DateTimeInterface $end
    ): array {
        if ($end->getTimestamp() <= $start->getTimestamp()) {
            throw new \InvalidArgumentException('Availability range end must be after start.');
        }

        $before = $this->pdo->prepare(
            'SELECT state, occurred_at
             FROM server_availability_events
             WHERE server_id = :server_id
               AND occurred_at <= :range_start
             ORDER BY occurred_at DESC, id DESC
             LIMIT 1'
        );
        $before->execute([
            'server_id' => $serverId,
            'range_start' => $start->format(DateTimeInterface::ATOM),
        ]);
        $initial = $before->fetch();

        $events = $this->pdo->prepare(
            'SELECT state, occurred_at
             FROM server_availability_events
             WHERE server_id = :server_id
               AND occurred_at > :range_start
               AND occurred_at <= :range_end
             ORDER BY occurred_at, id'
        );
        $events->execute([
            'server_id' => $serverId,
            'range_start' => $start->format(DateTimeInterface::ATOM),
            'range_end' => $end->format(DateTimeInterface::ATOM),
        ]);
        $rows = $events->fetchAll();

        if (!is_array($initial)) {
            if ($rows === []) {
                return [
                    'known' => false,
                    'points' => [],
                    'availability_percent' => null,
                    'downtime_seconds' => 0,
                    'outages' => 0,
                ];
            }

            // History begins with the first known transition. Do not invent the
            // state before MirvMon started recording availability.
            $first = array_shift($rows);
            $start = new DateTimeImmutable((string) $first['occurred_at']);
            $initial = $first;
        }

        $state = (string) $initial['state'];
        $cursor = DateTimeImmutable::createFromInterface($start);
        $endTime = DateTimeImmutable::createFromInterface($end);
        $points = [[
            'time' => $cursor->format(DateTimeInterface::ATOM),
            'value' => $state === 'online' ? 1 : 0,
        ]];
        $onlineSeconds = 0;
        $downtimeSeconds = 0;
        $outages = $state === 'offline' ? 1 : 0;

        foreach ($rows as $row) {
            $eventTime = new DateTimeImmutable((string) $row['occurred_at']);
            if ($eventTime < $cursor) {
                continue;
            }
            $seconds = max(0, $eventTime->getTimestamp() - $cursor->getTimestamp());
            if ($state === 'online') {
                $onlineSeconds += $seconds;
            } else {
                $downtimeSeconds += $seconds;
            }

            $nextState = (string) $row['state'];
            if ($nextState === 'offline' && $state !== 'offline') {
                $outages++;
            }
            $state = $nextState;
            $cursor = $eventTime;
            $points[] = [
                'time' => $eventTime->format(DateTimeInterface::ATOM),
                'value' => $state === 'online' ? 1 : 0,
            ];
        }

        $seconds = max(0, $endTime->getTimestamp() - $cursor->getTimestamp());
        if ($state === 'online') {
            $onlineSeconds += $seconds;
        } else {
            $downtimeSeconds += $seconds;
        }
        $points[] = [
            'time' => $endTime->format(DateTimeInterface::ATOM),
            'value' => $state === 'online' ? 1 : 0,
        ];

        $knownSeconds = $onlineSeconds + $downtimeSeconds;

        return [
            'known' => true,
            'points' => $points,
            'availability_percent' => $knownSeconds > 0
                ? round($onlineSeconds * 100 / $knownSeconds, 3)
                : ($state === 'online' ? 100.0 : 0.0),
            'downtime_seconds' => $downtimeSeconds,
            'outages' => $outages,
        ];
    }

    private function timestamp(DateTimeInterface $timestamp): string
    {
        return $timestamp->format('Y-m-d H:i:s.uP');
    }
}
