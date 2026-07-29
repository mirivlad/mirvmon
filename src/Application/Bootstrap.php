<?php

declare(strict_types=1);

namespace App\Application;

use App\Controllers\AdminController;
use App\Controllers\AgentController;
use App\Controllers\AlertController;
use App\Controllers\Api\MetricsApiController;
use App\Controllers\Api\MetricsController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\GroupController;
use App\Controllers\ServerController;
use App\Controllers\ServerDetailController;
use App\Controllers\SetupController;
use App\Database\ConnectionFactory;
use Config\DatabaseConfig;
use DateTimeZone;
use PDO;
use RuntimeException;
use Slim\App;
use Slim\Views\Twig;

final class Bootstrap
{
    private function __construct(private readonly Container $container)
    {
    }

    public static function fromEnvironment(): self
    {
        $timezone = self::environment('APP_TIMEZONE', 'UTC');
        new DateTimeZone($timezone);
        date_default_timezone_set($timezone);

        $settings = [
            'app_env' => self::environment('APP_ENV', 'production'),
            'app_debug' => self::booleanEnvironment('APP_DEBUG', false),
            'app_key' => self::requiredEnvironment('APP_KEY'),
            'setup_token' => self::requiredEnvironment('SETUP_TOKEN'),
            'timezone' => $timezone,
            'trusted_proxies' => self::trustedProxies(
                self::environment('TRUSTED_PROXIES', 'private_ranges')
            ),
            'session_name' => self::environment('SESSION_NAME', 'mirvmon_session'),
            'session_secure' => self::booleanEnvironment('SESSION_SECURE', false),
            'max_request_bytes' => (int) self::environment('MAX_REQUEST_BYTES', '1048576'),
            'templates_path' => dirname(__DIR__, 2) . '/templates',
            'twig_cache' => self::environment('APP_ENV', 'production') === 'production'
                ? dirname(__DIR__, 2) . '/var/cache/twig'
                : false,
        ];

        return new self(self::createContainer($settings));
    }

    /** @param array<string, mixed> $settings */
    public static function createContainer(array $settings, ?PDO $pdo = null): Container
    {
        $container = new Container();
        $pdo ??= ConnectionFactory::fromEnvironment();
        DatabaseConfig::setInstance($pdo);

        $twig = Twig::create((string) $settings['templates_path'], [
            'cache' => $settings['twig_cache'],
            'auto_reload' => ($settings['app_env'] ?? 'production') !== 'production',
            'strict_variables' => ($settings['app_env'] ?? 'production') !== 'production',
        ]);

        $container->set('settings', $settings);
        $container->set(PDO::class, $pdo);
        $container->set(Twig::class, $twig);

        $applicationKey = base64_decode((string) $settings['app_key'], true);
        if ($applicationKey === false || strlen($applicationKey) !== 32) {
            throw new RuntimeException('APP_KEY must be a base64-encoded 32-byte key.');
        }

        $container->set(
            AuthController::class,
            static fn (Container $container): AuthController => new AuthController(
                $container->get(PDO::class),
                $container->get(Twig::class),
                $applicationKey
            )
        );
        $container->set(
            SetupController::class,
            static fn (Container $container): SetupController => new SetupController(
                $container->get(PDO::class),
                $container->get(Twig::class),
                (string) $settings['setup_token']
            )
        );
        $container->set(
            DashboardController::class,
            static fn (Container $container): DashboardController => new DashboardController(
                $container->get(Twig::class)
            )
        );
        $container->set(
            GroupController::class,
            static fn (Container $container): GroupController => new GroupController(
                $container->get(Twig::class)
            )
        );
        $container->set(
            ServerController::class,
            static fn (Container $container): ServerController => new ServerController(
                $container->get(Twig::class)
            )
        );
        $container->set(
            ServerDetailController::class,
            static fn (Container $container): ServerDetailController => new ServerDetailController(
                $container->get(Twig::class)
            )
        );
        $container->set(
            AlertController::class,
            static fn (Container $container): AlertController => new AlertController(
                $container->get(Twig::class)
            )
        );
        $container->set(
            AdminController::class,
            static fn (Container $container): AdminController => new AdminController(
                $container->get(Twig::class)
            )
        );
        $container->set(AgentController::class, static fn (): AgentController => new AgentController());
        $container->set(
            MetricsController::class,
            static fn (): MetricsController => new MetricsController()
        );
        $container->set(
            MetricsApiController::class,
            static fn (): MetricsApiController => new MetricsApiController()
        );

        return $container;
    }

    public function app(): App
    {
        return AppFactory::create($this->container);
    }

    private static function requiredEnvironment(string $name): string
    {
        $value = self::environment($name);
        if ($value === '') {
            throw new RuntimeException($name . ' is required.');
        }

        return $value;
    }

    private static function environment(string $name, string $default = ''): string
    {
        $value = getenv($name);

        return $value === false ? $default : trim($value);
    }

    private static function booleanEnvironment(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** @return list<string> */
    private static function trustedProxies(string $value): array
    {
        if ($value === 'private_ranges') {
            return [
                '127.0.0.0/8',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '::1/128',
                'fc00::/7',
                'fe80::/10',
            ];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
