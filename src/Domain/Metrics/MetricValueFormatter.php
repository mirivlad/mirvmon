<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

final class MetricValueFormatter
{
    private const RATE_UNITS = ['B/s', 'KB/s', 'MB/s', 'GB/s', 'TB/s'];
    private const RATE_STEP = 1024;

    /**
     * Renders a sample the way an operator reads it: a throughput of
     * 12500000 B/s becomes 11.92 MB/s instead of eight digits.
     */
    public function format(float $value, ?string $unit): string
    {
        if ($unit === 'B/s') {
            return $this->rate($value);
        }
        if ($unit === null || $unit === '') {
            return $this->number($value);
        }

        return $this->number($value) . $unit;
    }

    private function rate(float $value): string
    {
        $magnitude = abs($value);
        $index = 0;
        $lastIndex = count(self::RATE_UNITS) - 1;
        while ($magnitude >= self::RATE_STEP && $index < $lastIndex) {
            $magnitude /= self::RATE_STEP;
            $value /= self::RATE_STEP;
            $index++;
        }

        return $this->number($value) . ' ' . self::RATE_UNITS[$index];
    }

    private function number(float $value): string
    {
        $rounded = round($value, 2);
        if ($rounded === floor($rounded) && abs($rounded) < 1000) {
            return (string) (int) $rounded;
        }

        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }
}
