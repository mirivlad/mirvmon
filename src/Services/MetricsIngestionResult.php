<?php

declare(strict_types=1);

namespace App\Services;

final readonly class MetricsIngestionResult
{
    public function __construct(
        public int $serverId,
        public bool $duplicate
    ) {
    }
}
