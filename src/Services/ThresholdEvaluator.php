<?php

declare(strict_types=1);

namespace App\Services;

final class ThresholdEvaluator
{
    public function severity(
        float $value,
        ?float $warningThreshold,
        ?float $criticalThreshold
    ): ?string {
        if ($criticalThreshold !== null && $value >= $criticalThreshold) {
            return 'critical';
        }

        if ($warningThreshold !== null && $value >= $warningThreshold) {
            return 'warning';
        }

        return null;
    }
}
