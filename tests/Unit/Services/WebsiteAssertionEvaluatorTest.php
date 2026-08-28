<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WebsiteAssertionEvaluator;
use App\Services\WebsiteEndpointValidator;
use PHPUnit\Framework\TestCase;

final class WebsiteAssertionEvaluatorTest extends TestCase
{
    public function testChecksWholePageTextAndSelectorTextWithoutRegex(): void
    {
        $definition = (new WebsiteEndpointValidator())->validate([
            'name' => 'Health',
            'url' => 'https://example.com/health',
            'status_check_enabled' => true,
            'expected_statuses' => '200',
            'content_checks' => [
                ['kind' => 'page_text', 'needle' => 'Service ready'],
                ['kind' => 'css', 'selector' => '#health strong', 'needle' => 'OK'],
            ],
        ]);

        $result = (new WebsiteAssertionEvaluator())->evaluate(
            200,
            '<main id="health">Service ready <strong>OK</strong></main>',
            $definition
        );

        self::assertTrue($result['passed']);
        self::assertTrue($result['status_passed']);
        self::assertSame([true, true], array_column($result['content_results'], 'passed'));
    }

    public function testStatusIsNotAnAssertionWhenDisabled(): void
    {
        $definition = (new WebsiteEndpointValidator())->validate([
            'name' => 'Alive',
            'url' => 'https://example.com/',
            'status_check_enabled' => false,
        ]);

        $result = (new WebsiteAssertionEvaluator())->evaluate(503, 'alive', $definition);

        self::assertTrue($result['passed']);
        self::assertNull($result['status_passed']);
    }

    public function testMissingContentReturnsOnlySafeDiagnostics(): void
    {
        $secretBody = '<main>customer-private-body</main>';
        $definition = (new WebsiteEndpointValidator())->validate([
            'name' => 'Health',
            'url' => 'https://example.com/',
            'content_checks' => [
                ['kind' => 'page_text', 'needle' => 'Service ready'],
                ['kind' => 'css', 'selector' => '#health', 'needle' => 'OK'],
            ],
        ]);

        $result = (new WebsiteAssertionEvaluator())->evaluate(200, $secretBody, $definition);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        self::assertFalse($result['passed']);
        self::assertStringNotContainsString('customer-private-body', $encoded);
        self::assertSame([false, false], array_column($result['content_results'], 'passed'));
    }
}
