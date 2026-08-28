<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WebsiteEndpointValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebsiteEndpointValidatorTest extends TestCase
{
    #[DataProvider('invalidEndpoints')]
    public function testRejectsInvalidEndpointInput(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new WebsiteEndpointValidator())->validate(array_replace(
            ['name' => 'Main', 'url' => 'https://example.com/'],
            $overrides
        ));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidEndpoints(): iterable
    {
        yield 'url credentials' => [['url' => 'https://user:pass@example.com/']];
        yield 'unsupported scheme' => [['url' => 'file:///etc/passwd']];
        yield 'url fragment' => [['url' => 'https://example.com/#section']];
        yield 'side-effect method' => [['method' => 'POST']];
        yield 'too many redirects' => [['max_redirects' => 11]];
        yield 'too short interval' => [['interval_seconds' => 9]];
        yield 'too long deadline' => [['timeout_seconds' => 61]];
        yield 'content with head' => [[
            'method' => 'HEAD',
            'content_checks' => [['kind' => 'page_text', 'needle' => 'ready']],
        ]];
        yield 'critical below warning' => [[
            'warning_total_ms' => 1000,
            'critical_total_ms' => 999,
        ]];
        yield 'forged host' => [[
            'headers' => [['name' => 'Host', 'value' => 'internal']],
        ]];
        yield 'raw authorization' => [[
            'headers' => [['name' => 'Authorization', 'value' => 'Bearer hidden']],
        ]];
        yield 'proxy header' => [[
            'headers' => [['name' => 'Proxy-Authorization', 'value' => 'hidden']],
        ]];
        yield 'header injection' => [[
            'headers' => [['name' => 'X-Trace', 'value' => "safe\r\nInjected: yes"]],
        ]];
        yield 'unknown custom header' => [[
            'headers' => [['name' => 'Unsafe-Vendor-Header', 'value' => 'value']],
        ]];
        yield 'basic without username' => [[
            'auth_type' => 'basic',
            'auth_secret' => 'password',
        ]];
        yield 'bearer without secret' => [['auth_type' => 'bearer']];
        yield 'redirect origin with path' => [[
            'credential_redirect_hosts' => ['https://api.example.com/private'],
        ]];
    }

    public function testAllowsPrivateAndInternalTargets(): void
    {
        $definition = (new WebsiteEndpointValidator())->validate([
            'name' => 'Intranet',
            'url' => 'https://10.0.0.8/health?verbose=1',
            'method' => 'GET',
            'interval_seconds' => 60,
        ]);

        self::assertSame('https://10.0.0.8/health?verbose=1', $definition->url);
        self::assertSame(60, $definition->intervalSeconds);
        self::assertFalse($definition->statusCheckEnabled);
    }

    public function testCanonicalizesDefaultsAuthHeadersAndRedirectOrigins(): void
    {
        $definition = (new WebsiteEndpointValidator())->validate([
            'name' => ' Portal ',
            'url' => 'HTTPS://EXAMPLE.COM',
            'status_check_enabled' => true,
            'expected_statuses' => '200,204-206',
            'auth_type' => 'basic',
            'auth_username' => 'monitor',
            'auth_secret' => 'secret',
            'headers' => [
                ['name' => 'Accept-Language', 'value' => 'ru'],
                ['name' => 'X-Api-Key', 'value' => 'key'],
                ['name' => 'Api-Key', 'value' => 'legacy-key'],
            ],
            'credential_redirect_hosts' => [
                'HTTPS://API.EXAMPLE.COM:8443',
                'https://api.example.com:8443',
            ],
            'allow_self_signed' => true,
            'tls_expiry_enabled' => true,
        ]);

        self::assertSame('Portal', $definition->name);
        self::assertSame('https://example.com/', $definition->url);
        self::assertSame('GET', $definition->method);
        self::assertSame(60, $definition->intervalSeconds);
        self::assertSame(15, $definition->timeoutSeconds);
        self::assertSame(10, $definition->maxRedirects);
        self::assertTrue($definition->statusCheckEnabled);
        self::assertTrue($definition->expectedStatuses->accepts(205));
        self::assertSame('monitor', $definition->authUsername);
        self::assertSame('secret', $definition->authSecret);
        self::assertSame([
            'Accept-Language' => 'ru',
            'X-Api-Key' => 'key',
            'Api-Key' => 'legacy-key',
        ], $definition->headers);
        self::assertSame(
            ['https://api.example.com:8443'],
            $definition->credentialRedirectHosts
        );
        self::assertTrue($definition->allowSelfSigned);
        self::assertTrue($definition->tlsExpiryEnabled);
    }

    public function testExistingEndpointCanKeepCredentialWhenFormSubmitsBlankFields(): void
    {
        $definition = (new WebsiteEndpointValidator())->validate([
            'id' => 42,
            'name' => 'API',
            'url' => 'https://example.com/api',
            'auth_type' => 'basic',
            'auth_username' => '',
            'auth_secret' => '',
        ]);

        self::assertSame('basic', $definition->authType);
        self::assertNull($definition->authUsername);
        self::assertNull($definition->authSecret);
    }

    public function testExistingEndpointCanKeepAnEncryptedCredentialWithoutResubmittingIt(): void
    {
        $definition = (new WebsiteEndpointValidator())->validate([
            'id' => 42,
            'is_primary' => true,
            'name' => 'API',
            'url' => 'https://example.com/api',
            'auth_type' => 'bearer',
        ]);

        self::assertSame(42, $definition->id);
        self::assertTrue($definition->isPrimary);
        self::assertSame('bearer', $definition->authType);
        self::assertNull($definition->authSecret);
    }
}
