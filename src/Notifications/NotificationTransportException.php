<?php

declare(strict_types=1);

namespace App\Notifications;

use RuntimeException;
use Throwable;

final class NotificationTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?int $retryAfterSeconds = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
