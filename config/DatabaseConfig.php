<?php

declare(strict_types=1);

namespace Config;

use App\Database\ConnectionFactory;
use PDO;

final class DatabaseConfig
{
    private static ?PDO $instance = null;

    private function __construct()
    {
    }

    public static function getInstance(): PDO
    {
        return self::$instance ??= ConnectionFactory::fromEnvironment();
    }

    public static function setInstance(PDO $pdo): void
    {
        self::$instance = $pdo;
    }
}
