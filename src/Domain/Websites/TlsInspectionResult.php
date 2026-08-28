<?php

declare(strict_types=1);

namespace App\Domain\Websites;

use DateTimeImmutable;

final readonly class TlsInspectionResult
{
    /** @param list<string> $sans */
    public function __construct(
        public int $endpointId,
        public string $hostname,
        public int $port,
        public DateTimeImmutable $checkedAt,
        public bool $valid,
        public ?string $errorKind,
        public ?string $subject,
        public ?string $issuer,
        public array $sans,
        public ?string $fingerprintSha256,
        public ?DateTimeImmutable $notBefore,
        public ?DateTimeImmutable $notAfter,
        public ?int $targetId = null,
    ) {
    }
}
