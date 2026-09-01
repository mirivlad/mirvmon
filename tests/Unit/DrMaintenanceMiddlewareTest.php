<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Backup\DrMaintenanceLock;
use App\Middlewares\DrMaintenanceMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DrMaintenanceMiddlewareTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mirvmon-dr-middleware-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (['maintenance.json', 'maintenance.lock'] as $file) {
            @unlink($this->directory . '/' . $file);
        }
        @rmdir($this->directory);
    }

    public function testOrdinaryRequestReturns503DuringMaintenance(): void
    {
        $lock = new DrMaintenanceLock($this->directory);
        $exclusive = $lock->beginExclusive(['operation_id' => 'restore-test']);
        $middleware = new DrMaintenanceMiddleware(new ResponseFactory(), $lock);
        $handler = new class implements RequestHandlerInterface {
            public bool $called = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;
                return (new ResponseFactory())->createResponse(200);
            }
        };

        try {
            $response = $middleware->process(
                (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/metrics'),
                $handler
            );
        } finally {
            $exclusive->release();
        }

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->getHeaderLine('Retry-After'));
        self::assertFalse($handler->called);
    }

    public function testLivezAndRestoreExecutionAreExempt(): void
    {
        $lock = new DrMaintenanceLock($this->directory);
        $exclusive = $lock->beginExclusive();
        $middleware = new DrMaintenanceMiddleware(new ResponseFactory(), $lock);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };

        try {
            $live = $middleware->process(
                (new ServerRequestFactory())->createServerRequest('GET', '/livez'),
                $handler
            );
            $restore = $middleware->process(
                (new ServerRequestFactory())->createServerRequest('POST', '/admin/system/restore/execute'),
                $handler
            );
        } finally {
            $exclusive->release();
        }

        self::assertSame(204, $live->getStatusCode());
        self::assertSame(204, $restore->getStatusCode());
    }
}
