<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WebsiteUrlSanitizer;
use PHPUnit\Framework\TestCase;

final class WebsiteUrlSanitizerTest extends TestCase
{
    public function testRedactsSensitiveQueryValuesAndRemovesUserInfoAndFragment(): void
    {
        $url = 'https://user:password@example.com/health'
            . '?region=eu&access_token=hidden&signature=signed#section';

        self::assertSame(
            'https://example.com/health'
                . '?region=eu&access_token=%5Bredacted%5D&signature=%5Bredacted%5D',
            (new WebsiteUrlSanitizer())->forDisplay($url)
        );
    }

    public function testPreservesPortAndRepeatedNonSensitiveParameters(): void
    {
        self::assertSame(
            'http://localhost:8080/check?tag=a&tag=b',
            (new WebsiteUrlSanitizer())->forDisplay(
                'http://localhost:8080/check?tag=a&tag=b'
            )
        );
    }
}
