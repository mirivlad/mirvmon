<?php

declare(strict_types=1);

namespace App\Services;

final readonly class AgentArtifact
{
    public function __construct(
        public string $key,
        public string $filename,
        public string $path,
        public string $sha256,
        public string $contentType
    ) {
    }
}
