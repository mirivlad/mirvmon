<?php

declare(strict_types=1);

namespace App\Domain\Websites;

use DateTimeImmutable;

final readonly class WebsiteStateDecision
{
    /**
     * @param array<string, mixed> $nextState
     * @param list<array{dimension:string,kind:string,severity:string,open:bool,close:bool,effective_at:?DateTimeImmutable,diagnostic:?string}> $dimensions
     */
    public function __construct(
        public array $nextState,
        public bool $openIncident,
        public bool $closeIncident,
        public ?string $kind,
        public ?string $severity,
        public ?DateTimeImmutable $effectiveAt,
        public ?string $safeDiagnostic,
        public array $dimensions = [],
    ) {
    }

    /** @return list<string> */
    public function openedKinds(): array
    {
        return array_values(array_map(
            static fn (array $dimension): string => $dimension['kind'],
            array_filter($this->dimensions, static fn (array $dimension): bool => $dimension['open']),
        ));
    }
}
