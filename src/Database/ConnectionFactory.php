<?php

declare(strict_types=1);

namespace App\Database;

use InvalidArgumentException;
use PDO;

final class ConnectionFactory
{
    private const SSL_MODES = [
        'disable',
        'allow',
        'prefer',
        'require',
        'verify-ca',
        'verify-full',
    ];

    /**
     * @param array<string, string> $environment
     */
    public static function dsn(array $environment): string
    {
        $host = self::required($environment, 'DB_HOST');
        $database = self::required($environment, 'DB_NAME');
        $port = $environment['DB_PORT'] ?? '5432';
        $sslMode = $environment['DB_SSLMODE'] ?? 'prefer';

        if (filter_var($port, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]) === false) {
            throw new InvalidArgumentException('DB_PORT must be between 1 and 65535.');
        }

        if (!in_array($sslMode, self::SSL_MODES, true)) {
            throw new InvalidArgumentException('DB_SSLMODE is not supported.');
        }

        foreach (['DB_HOST' => $host, 'DB_NAME' => $database] as $name => $value) {
            if (str_contains($value, ';') || str_contains($value, '=')) {
                throw new InvalidArgumentException($name . ' contains invalid characters.');
            }
        }

        return sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            $host,
            $port,
            $database,
            $sslMode
        );
    }

    /**
     * @param array<string, string> $environment
     */
    public static function connect(array $environment): PDO
    {
        $username = self::required($environment, 'DB_USERNAME');
        $password = self::required($environment, 'DB_PASSWORD');

        return new PDO(self::dsn($environment), $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    public static function fromEnvironment(): PDO
    {
        $environment = [];

        foreach ([
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USERNAME',
            'DB_PASSWORD',
            'DB_SSLMODE',
        ] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }

        return self::connect($environment);
    }

    /**
     * @param array<string, string> $environment
     */
    private static function required(array $environment, string $name): string
    {
        $value = trim($environment[$name] ?? '');
        if ($value === '') {
            throw new InvalidArgumentException($name . ' is required.');
        }

        return $value;
    }
}
