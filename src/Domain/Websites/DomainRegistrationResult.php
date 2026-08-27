<?php

declare(strict_types=1);

namespace App\Domain\Websites;

use DateTimeImmutable;

final readonly class DomainRegistrationResult
{
    public function __construct(
        public string $domain,
        public DateTimeImmutable $checkedAt,
        public string $status,
        public ?DateTimeImmutable $expiresAt,
        public ?string $registrar,
        public ?string $source,
        public ?string $errorKind,
        public ?int $retryAfterSeconds = null,
    ) {
    }
}
