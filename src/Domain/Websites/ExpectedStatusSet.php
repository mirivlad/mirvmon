<?php

declare(strict_types=1);

namespace App\Domain\Websites;

use InvalidArgumentException;

final readonly class ExpectedStatusSet
{
    /** @param list<array{min: int, max: int}> $ranges */
    private function __construct(private array $ranges)
    {
    }

    public static function fromString(string $expression): self
    {
        if (trim($expression) === '') {
            throw new InvalidArgumentException('Expected HTTP statuses cannot be empty.');
        }

        $ranges = [];
        foreach (explode(',', $expression) as $item) {
            $item = trim($item);
            if (!preg_match('/^(\d{3})(?:\s*-\s*(\d{3}))?$/', $item, $matches)) {
                throw new InvalidArgumentException('Invalid expected HTTP status expression.');
            }

            $min = (int) $matches[1];
            $max = isset($matches[2]) ? (int) $matches[2] : $min;
            if ($min < 100 || $max > 599 || $min > $max) {
                throw new InvalidArgumentException('Expected HTTP statuses must be between 100 and 599.');
            }

            $ranges[] = ['min' => $min, 'max' => $max];
        }

        return new self($ranges);
    }

    public function accepts(int $status): bool
    {
        foreach ($this->ranges as $range) {
            if ($status >= $range['min'] && $status <= $range['max']) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{min: int, max: int}> */
    public function ranges(): array
    {
        return $this->ranges;
    }
}
