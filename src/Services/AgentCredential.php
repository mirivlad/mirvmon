<?php

declare(strict_types=1);

namespace App\Services;

final readonly class AgentCredential
{
    public function __construct(
        public int $serverId,
        public string $token
    ) {
    }
}
