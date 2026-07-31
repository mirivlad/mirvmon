<?php

declare(strict_types=1);

namespace App\Workers;

use App\Notifications\NotificationTransportException;
use App\Repositories\NotificationOutboxRepository;
use Closure;
use Throwable;

final class NotificationWorker
{
    /** @var Closure(array<string, mixed>): bool */
    private readonly Closure $deliver;

    /** @param callable(array<string, mixed>): bool $deliver */
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
                if (($this->deliver)($job) === false) {
                    $this->outbox->markDead((int) $job['id'], 'channel_disabled');
                    continue;
                }
                $this->outbox->markSent((int) $job['id']);
            } catch (NotificationTransportException $exception) {
                // Transport messages are codes chosen by MirvMon and carry no
                // credentials, so the administrator may see them verbatim.
                $this->outbox->markFailed(
                    (int) $job['id'],
                    (int) $job['attempts'],
                    self::safeError($exception->getMessage())
                );
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

    private static function safeError(string $message): string
    {
        $safe = preg_replace('/[^\p{L}\p{N} _.:\-()]/u', ' ', $message) ?? '';

        return trim(mb_substr($safe, 0, 200));
    }
}
