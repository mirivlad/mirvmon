<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\ErrorResponder;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ErrorResponderTest extends TestCase
{
    public function testNotFoundIsNotReportedAsInternalError(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/missing');
        $response = (new ErrorResponder(new ResponseFactory(), false))->respond(
            $request,
            new HttpNotFoundException($request)
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Страница не найдена', (string) $response->getBody());
    }

    public function testApiErrorsAreJsonAndHideExceptionDetails(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/private');
        $response = (new ErrorResponder(new ResponseFactory(), false))->respond(
            $request,
            new RuntimeException('database-password-leaked')
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('database-password-leaked', (string) $response->getBody());
        self::assertSame(
            ['error' => ['code' => 'internal_error', 'message' => 'Internal server error.']],
            json_decode((string) $response->getBody(), true)
        );
    }
}
