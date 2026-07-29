<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PublicUrlResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PublicUrlResolverTest extends TestCase
{
    public function testConfiguredUrlTakesPrecedenceAndIsNormalized(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://untrusted.example/agent/install.sh'
        );

        self::assertSame(
            'https://monitor.example',
            (new PublicUrlResolver('https://monitor.example/'))->resolve($request)
        );
    }

    public function testRequestOriginIsUsedWhenConfiguredUrlIsEmpty(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://metrics.example:8443/agent/install.sh'
        );

        self::assertSame(
            'https://metrics.example:8443',
            (new PublicUrlResolver(''))->resolve($request)
        );
    }

    public function testUnsafeConfiguredUrlAndRequestOriginAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PublicUrlResolver('https://user@monitor.example');
    }

    public function testUnsupportedConfiguredSchemeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PublicUrlResolver('ftp://monitor.example');
    }
}
