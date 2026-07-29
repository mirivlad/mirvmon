<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use InvalidArgumentException;

final class MetricsValidationException extends InvalidArgumentException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
