<?php

declare(strict_types=1);

namespace App\Workers;

use App\Repositories\NotificationOutboxRepository;
use Closure;
use Throwable;

final class NotificationWorker
{
    /** @var Closure(array<string, mixed>): void */
    private readonly Closure $deliver;

    /** @param callable(array<string, mixed>): void $deliver */
    public function __construct(
        private readonly NotificationOutboxRepository $outbox,
        callable $deliver
    ) {
        $this->deliver = Closure::fromCallable($deliver);
    }

    public function runOnce(int $limit = 20): int
    {
        $jobs = $this->outbox->claim($limit);

        foreach ($jobs as $job) {
            try {
                ($this->deliver)($job);
                $this->outbox->markSent((int) $job['id']);
            } catch (Throwable) {
                $this->outbox->markFailed(
                    (int) $job['id'],
                    (int) $job['attempts'],
                    'delivery_failed'
                );
            }
        }

        return count($jobs);
    }
}
