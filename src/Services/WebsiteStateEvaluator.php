<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Websites\WebsiteCheckResult;
use App\Domain\Websites\WebsiteStateDecision;
use DateTimeImmutable;

final class WebsiteStateEvaluator
{
    /** @param array<string, mixed> $current */
    public function evaluate(array $current, WebsiteCheckResult $result): WebsiteStateDecision
    {
        $next = $current;
        $dimensions = [];

        $this->evaluateDimension(
            $next,
            $dimensions,
            'transport',
            'website_http',
            !$result->transportAvailable,
            $result->checkedAt,
            $result->error?->value,
        );

        $assertionObserved = $result->transportAvailable && $this->hasAssertion($result, false);
        if ($assertionObserved) {
            $this->evaluateDimension(
                $next,
                $dimensions,
                'assertion',
                'website_assertion',
                $this->hasFailedAssertion($result, false),
                $result->checkedAt,
                $this->firstFailedMessage($result, false),
            );
        }

        $performanceObserved = $result->transportAvailable && $this->hasAssertion($result, true);
        if ($performanceObserved) {
            $this->evaluateDimension(
                $next,
                $dimensions,
                'performance',
                'website_performance',
                $this->hasFailedAssertion($result, true),
                $result->checkedAt,
                $this->firstFailedMessage($result, true),
            );
        }

        $opened = array_values(array_filter($dimensions, static fn (array $item): bool => $item['open']));
        $closed = array_values(array_filter($dimensions, static fn (array $item): bool => $item['close']));
        $firstTransition = $opened[0] ?? $closed[0] ?? null;

        return new WebsiteStateDecision(
            nextState: $next,
            openIncident: $opened !== [],
            closeIncident: $closed !== [],
            kind: $firstTransition['kind'] ?? null,
            severity: $firstTransition['severity'] ?? null,
            effectiveAt: $firstTransition['effective_at'] ?? null,
            safeDiagnostic: $firstTransition['diagnostic'] ?? null,
            dimensions: $dimensions,
        );
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array{dimension:string,kind:string,severity:string,open:bool,close:bool,effective_at:?DateTimeImmutable,diagnostic:?string}> $dimensions
     */
    private function evaluateDimension(
        array &$state,
        array &$dimensions,
        string $dimension,
        string $kind,
        bool $failed,
        DateTimeImmutable $checkedAt,
        ?string $diagnostic,
    ): void {
        $stateKey = $dimension . '_state';
        $failuresKey = $dimension . '_failures';
        $successesKey = $dimension . '_successes';
        $seriesKey = $dimension . '_series_started_at';
        $previous = (string) ($state[$stateKey] ?? 'no_data');
        $failures = $failed ? (int) ($state[$failuresKey] ?? 0) + 1 : 0;
        $successes = $failed ? 0 : (int) ($state[$successesKey] ?? 0) + 1;
        $seriesStarted = $state[$seriesKey] ?? null;

        if ($failed) {
            $seriesStarted ??= $checkedAt->format(DATE_ATOM);
            $nextState = $failures >= 3 ? 'problem' : 'possible_problem';
            $effectiveAt = $failures >= 3 && $previous !== 'problem'
                ? new DateTimeImmutable((string) $seriesStarted)
                : null;
            $open = $effectiveAt !== null;
            $close = false;
        } else {
            $wasProblem = in_array($previous, ['problem', 'recovering'], true);
            $nextState = $wasProblem && $successes < 2 ? 'recovering' : 'healthy';
            $effectiveAt = $wasProblem && $successes >= 2
                ? new DateTimeImmutable((string) ($state[$seriesKey] ?? $checkedAt->format(DATE_ATOM)))
                : null;
            $open = false;
            $close = $effectiveAt !== null;
            if ($wasProblem && $successes === 1) {
                $seriesStarted = $checkedAt->format(DATE_ATOM);
            } else {
                $seriesStarted = null;
            }
        }

        $state[$stateKey] = $nextState;
        $state[$failuresKey] = $failures;
        $state[$successesKey] = $successes;
        $state[$seriesKey] = $seriesStarted;
        $dimensions[] = [
            'dimension' => $dimension,
            'kind' => $kind,
            'severity' => 'critical',
            'open' => $open,
            'close' => $close,
            'effective_at' => $effectiveAt,
            'diagnostic' => $diagnostic,
        ];
    }

    private function hasAssertion(WebsiteCheckResult $result, bool $performance): bool
    {
        foreach ($result->assertionResults as $assertion) {
            if ($performance === ($assertion['kind'] === 'response_time')) {
                return true;
            }
        }

        return false;
    }

    private function hasFailedAssertion(WebsiteCheckResult $result, bool $performance): bool
    {
        foreach ($result->assertionResults as $assertion) {
            if ($performance === ($assertion['kind'] === 'response_time') && !$assertion['passed']) {
                return true;
            }
        }

        return false;
    }

    private function firstFailedMessage(WebsiteCheckResult $result, bool $performance): ?string
    {
        foreach ($result->assertionResults as $assertion) {
            if ($performance === ($assertion['kind'] === 'response_time') && !$assertion['passed']) {
                return $assertion['safe_message'];
            }
        }

        return $result->error?->value;
    }
}
