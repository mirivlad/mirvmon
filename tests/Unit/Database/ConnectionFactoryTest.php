<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\ConnectionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    public function testBuildsPostgresDsnWithPortAndSslMode(): void
    {
        $dsn = ConnectionFactory::dsn([
            'DB_HOST' => 'timescaledb',
            'DB_PORT' => '5433',
            'DB_NAME' => 'mirvmon',
            'DB_SSLMODE' => 'require',
        ]);

        self::assertSame(
            'pgsql:host=timescaledb;port=5433;dbname=mirvmon;sslmode=require',
            $dsn
        );
    }

    public function testRejectsMissingCredentialsWithoutFallbacks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_USERNAME');

        ConnectionFactory::connect([
            'DB_HOST' => 'db',
            'DB_PORT' => '5432',
            'DB_NAME' => 'mirvmon',
            'DB_PASSWORD' => 'not-a-real-secret',
        ]);
    }

    public function testRejectsUnsupportedSslMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_SSLMODE');

        ConnectionFactory::dsn([
            'DB_HOST' => 'db',
            'DB_PORT' => '5432',
            'DB_NAME' => 'mirvmon',
            'DB_SSLMODE' => 'sometimes',
        ]);
    }
}
