<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final class ServerTimingService
{
    public function __construct(private readonly int $toleranceSeconds = 300)
    {
    }

    /**
     * @return array{
     *     state: 'unknown'|'ok'|'stale'|'ahead',
     *     delta_seconds: ?int,
     *     absolute_seconds: ?int
     * }
     */
    public function diagnose(mixed $lastContactAt, mixed $lastSampleAt): array
    {
        $contact = $this->date($lastContactAt);
        $sample = $this->date($lastSampleAt);
        if ($contact === null || $sample === null) {
            return [
                'state' => 'unknown',
                'delta_seconds' => null,
                'absolute_seconds' => null,
            ];
        }

        // Positive means the sample clock is ahead of MirvMon receive time.
        // Negative means MirvMon received a sample whose timestamp is older.
        $delta = $sample->getTimestamp() - $contact->getTimestamp();
        $absolute = abs($delta);
        if ($absolute <= max(0, $this->toleranceSeconds)) {
            $state = 'ok';
        } elseif ($delta > 0) {
            $state = 'ahead';
        } else {
            $state = 'stale';
        }

        return [
            'state' => $state,
            'delta_seconds' => $delta,
            'absolute_seconds' => $absolute,
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
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
