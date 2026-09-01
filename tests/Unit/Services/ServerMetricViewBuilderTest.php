<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\I18n\Translator;
use App\Services\ServerMetricViewBuilder;
use PHPUnit\Framework\TestCase;

final class ServerMetricViewBuilderTest extends TestCase
{
    public function testAvailabilityChartKeepsSourceTimestampsForViewportRestore(): void
    {
        $builder = new ServerMetricViewBuilder(
            new Translator(null, dirname(__DIR__, 3) . '/translations')
        );
        $chart = $builder->availabilityChart([
            'known' => true,
            'points' => [
                ['time' => '2026-09-01T10:00:00+00:00', 'value' => 1],
                ['time' => '2026-09-01T10:05:00+00:00', 'value' => 0],
            ],
            'availability_percent' => 50,
            'downtime_seconds' => 300,
            'outages' => 1,
        ]);

        self::assertSame([
            '2026-09-01T10:00:00+00:00',
            '2026-09-01T10:05:00+00:00',
        ], $chart['timestamps']);
        self::assertSame([1, 0], $chart['values']);
    }
}
