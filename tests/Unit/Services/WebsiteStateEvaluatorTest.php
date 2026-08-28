<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Domain\Websites\WebsiteCheckError;
use App\Domain\Websites\WebsiteCheckResult;
use App\Services\WebsiteStateEvaluator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WebsiteStateEvaluatorTest extends TestCase
{
    private WebsiteStateEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new WebsiteStateEvaluator();
    }

    public function testThirdFailureOpensAtFirstFailureAndSecondSuccessClosesAtFirstSuccess(): void
    {
        $state = $this->noDataState();
        $state = $this->evaluator->evaluate($state, $this->makeResult(false, false, '00:00:00'))->nextState;
        self::assertSame('possible_problem', $state['transport_state']);
        $state = $this->evaluator->evaluate($state, $this->makeResult(false, false, '00:01:00'))->nextState;
        $opened = $this->evaluator->evaluate($state, $this->makeResult(false, false, '00:02:00'));

        self::assertTrue($opened->openIncident);
        self::assertSame('2026-08-27T00:00:00+00:00', $opened->effectiveAt?->format(DATE_ATOM));
        self::assertSame('problem', $opened->nextState['transport_state']);

        $recovering = $this->evaluator->evaluate($opened->nextState, $this->makeResult(true, true, '00:03:00'));
        self::assertFalse($recovering->closeIncident);
        $closed = $this->evaluator->evaluate($recovering->nextState, $this->makeResult(true, true, '00:04:00'));

        self::assertTrue($closed->closeIncident);
        self::assertSame('2026-08-27T00:03:00+00:00', $closed->effectiveAt?->format(DATE_ATOM));
        self::assertSame('healthy', $closed->nextState['transport_state']);
    }

    public function testAssertionAndPerformanceDimensionsAreEvaluatedIndependently(): void
    {
        $state = $this->noDataState();
        $state['assertion_failures'] = 2;
        $state['assertion_series_started_at'] = '2026-08-26T23:58:00+00:00';
        $state['performance_failures'] = 2;
        $state['performance_series_started_at'] = '2026-08-26T23:58:00+00:00';
        $result = $this->makeResult(
            transport: true,
            assertions: false,
            time: '00:00:00',
            error: WebsiteCheckError::ContentMissing,
            slow: true,
        );

        $decision = $this->evaluator->evaluate($state, $result);

        self::assertSame('healthy', $decision->nextState['transport_state']);
        self::assertSame('problem', $decision->nextState['assertion_state']);
        self::assertSame('problem', $decision->nextState['performance_state']);
        self::assertSame(['website_assertion', 'website_performance'], $decision->openedKinds());
    }

    public function testOneManualSuccessCannotCloseAnActiveIncident(): void
    {
        $state = $this->noDataState();
        $state['transport_state'] = 'problem';
        $state['transport_failures'] = 3;
        $state['transport_series_started_at'] = '2026-08-27T00:00:00+00:00';

        $decision = $this->evaluator->evaluate(
            $state,
            $this->makeResult(true, true, '01:00:00', manual: true),
        );

        self::assertFalse($decision->closeIncident);
        self::assertSame('recovering', $decision->nextState['transport_state']);
        self::assertSame(1, $decision->nextState['transport_successes']);
    }

    /** @return array<string, mixed> */
    private function noDataState(): array
    {
        return [
            'transport_state' => 'no_data',
            'assertion_state' => 'no_data',
            'performance_state' => 'no_data',
            'transport_failures' => 0,
            'transport_successes' => 0,
            'assertion_failures' => 0,
            'assertion_successes' => 0,
            'performance_failures' => 0,
            'performance_successes' => 0,
            'transport_series_started_at' => null,
            'assertion_series_started_at' => null,
            'performance_series_started_at' => null,
        ];
    }

    private function makeResult(
        bool $transport,
        bool $assertions,
        string $time,
        ?WebsiteCheckError $error = null,
        bool $slow = false,
        bool $manual = false,
    ): WebsiteCheckResult {
        $checkedAt = new DateTimeImmutable('2026-08-27T' . $time . '+00:00');
        $assertionResults = [];
        if (!$assertions) {
            $assertionResults[] = ['kind' => 'content', 'passed' => false, 'safe_message' => 'Expected content was not found.'];
        }
        if ($slow) {
            $assertionResults[] = ['kind' => 'response_time', 'passed' => false, 'safe_message' => 'Response time threshold exceeded.'];
        }

        return new WebsiteCheckResult(
            websiteId: 7,
            endpointId: 9,
            sampleId: '10000000-0000-4000-8000-' . str_pad((string) $checkedAt->getTimestamp(), 12, '0', STR_PAD_LEFT),
            checkedAt: $checkedAt,
            transportAvailable: $transport,
            assertionsPassed: $assertions,
            statusCode: $transport ? 200 : null,
            configuredUrl: 'https://example.com/',
            finalUrl: 'https://example.com/',
            redirectChain: [],
            timings: ['dns_ms' => 1.0, 'tcp_ms' => 1.0, 'tls_ms' => 1.0, 'ttfb_ms' => 1.0, 'total_ms' => $slow ? 200.0 : 10.0],
            error: $error,
            assertionResults: $assertionResults,
            manual: $manual,
        );
    }
}
