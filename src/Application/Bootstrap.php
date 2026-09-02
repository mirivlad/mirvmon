<?php

declare(strict_types=1);

namespace App\Application;

use App\Controllers\AdminController;
use App\Controllers\AgentController;
use App\Controllers\AgentFleetController;
use App\Controllers\AgentUpdateController;
use App\Controllers\AlertController;
use App\Controllers\Api\MetricsApiController;
use App\Controllers\Api\MetricsController;
use App\Controllers\Api\WebsiteMetricsApiController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\GroupController;
use App\Controllers\LanguageController;
use App\Controllers\ServerController;
use App\Controllers\ServerDetailController;
use App\Controllers\SetupController;
use App\Controllers\SystemController;
use App\Controllers\WebsiteController;
use App\Controllers\WebsiteDetailController;
use App\Database\ConnectionFactory;
use App\Domain\Metrics\MetricsValidator;
use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Middlewares\AuditTrailMiddleware;
use App\Repositories\AgentUpdateRepository;
use App\Repositories\AppSettingsRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\MaintenanceWindowRepository;
use App\Repositories\MetricRepository;
use App\Repositories\NotificationOutboxRepository;
use App\Repositories\NotificationSettingsRepository;
use App\Repositories\ServerRepository;
use App\Repositories\WorkerHeartbeatRepository;
use App\Repositories\WebsiteCheckQueueRepository;
use App\Repositories\WebsiteRepository;
use App\Repositories\WebsiteMetricsRepository;
use App\Security\SecretCipher;
use App\Services\AgentArtifactCatalog;
use App\Services\AgentCredentialIssuer;
use App\Services\AgentFleetService;
use App\Services\AgentInstallerService;
use App\Services\AgentUpdateService;
use App\Services\AgentVersionService;
use App\Services\AuditLogger;
use App\Services\AuditRetentionService;
use App\Services\MetricsIngestionService;
use App\Services\PublicUrlResolver;
use App\Services\ServerPlatformService;
use App\Services\ServerStatusService;
use App\Services\SystemHealthService;
use App\Services\ThresholdEvaluator;
use App\Services\WindowsInstallerPackageService;
use App\Services\WebsiteEndpointValidator;
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
            'app_version' => self::environment('APP_VERSION', 'development'),
            'public_base_url' => self::environment('PUBLIC_BASE_URL'),
            'templates_path' => dirname(__DIR__, 2) . '/templates',
            'translations_path' => dirname(__DIR__, 2) . '/translations',
            'twig_cache' => self::environment('APP_ENV', 'production') === 'production'
                ? dirname(__DIR__, 2) . '/var/cache/twig'
                : false,
        ];

        return new self(self::createContainer($settings));
    }

    /** @param array<string, mixed> $settings */
    public static function createContainer(array $settings, ?PDO $pdo = null): Container
    {
        if (!is_string($settings['dr_root'] ?? null) || trim((string) $settings['dr_root']) === '') {
            $settings['dr_root'] = ($settings['app_env'] ?? 'production') === 'test'
                ? sys_get_temp_dir() . '/mirvmon-dr-test-' . getmypid()
                : dirname(__DIR__, 2) . '/var/dr';
        }

        $container = new Container();
        $pdo ??= ConnectionFactory::fromEnvironment();
        $appSettings = new AppSettingsRepository($pdo);
        $translator = new Translator(
            $appSettings,
            (string) ($settings['translations_path'] ?? dirname(__DIR__, 2) . '/translations')
        );
        $translator->refreshLocale();

        $twig = Twig::create((string) $settings['templates_path'], [
            'cache' => $settings['twig_cache'],
            'auto_reload' => ($settings['app_env'] ?? 'production') !== 'production',
            'strict_variables' => ($settings['app_env'] ?? 'production') !== 'production',
        ]);
        TwigTranslation::register($twig->getEnvironment(), $translator);
        $twig->getEnvironment()->addGlobal(
            'app_version',
            (string) ($settings['app_version'] ?? 'development')
        );

        $container->set('settings', $settings);
        $container->set(PDO::class, $pdo);
        $container->set(Twig::class, $twig);
        $container->set(AppSettingsRepository::class, $appSettings);
        $container->set(Translator::class, $translator);
        $container->set(
            AuditLogRepository::class,
            static fn (Container $container): AuditLogRepository => new AuditLogRepository(
                $container->get(PDO::class)
            )
        );
        $container->set(
            AuditLogger::class,
            static fn (Container $container): AuditLogger => new AuditLogger(
                $container->get(AuditLogRepository::class)
            )
        );
        $container->set(
            AuditRetentionService::class,
            static fn (Container $container): AuditRetentionService => new AuditRetentionService(
                $container->get(PDO::class),
                $container->get(AppSettingsRepository::class)
            )
        );
        $container->set(
            ServerRepository::class,
            static fn (Container $container): ServerRepository => new ServerRepository(
                $container->get(PDO::class)
            )
        );
        $container->set(
            WebsiteRepository::class,
            static fn (Container $container): WebsiteRepository => new WebsiteRepository(
                $container->get(PDO::class),
                $container->get(SecretCipher::class),
                $container->get(AppSettingsRepository::class),
            )
        );
        $container->set(WebsiteEndpointValidator::class, static fn (): WebsiteEndpointValidator => new WebsiteEndpointValidator());
        $container->set(
            WebsiteCheckQueueRepository::class,
            static fn (Container $container): WebsiteCheckQueueRepository => new WebsiteCheckQueueRepository(
                $container->get(PDO::class)
            )
        );
        $container->set(
            IncidentRepository::class,
            static fn (Container $container): IncidentRepository => new IncidentRepository(
                $container->get(PDO::class)
            )
        );
        $container->set(
            MaintenanceWindowRepository::class,
            static fn (Container $container): MaintenanceWindowRepository =>
                new MaintenanceWindowRepository($container->get(PDO::class))
        );
        $container->set(
            MetricRepository::class,
            static fn (Container $container): MetricRepository => new MetricRepository(
                $container->get(PDO::class)
            )
        );
        $container->set(
            ServerPlatformService::class,
            static fn (Container $container): ServerPlatformService =>
                new ServerPlatformService($container->get(Translator::class))
        );
        $container->set(
            ServerStatusService::class,
            static fn (Container $container): ServerStatusService =>
                new ServerStatusService($container->get(ServerPlatformService::class))
        );
        $container->set(MetricsValidator::class, static fn (): MetricsValidator => new MetricsValidator());
        $container->set(ThresholdEvaluator::class, static fn (): ThresholdEvaluator => new ThresholdEvaluator());
        $container->set(
            NotificationOutboxRepository::class,
            static fn (Container $container): NotificationOutboxRepository =>
                new NotificationOutboxRepository($container->get(PDO::class))
        );
        $container->set(
            AuditTrailMiddleware::class,
            static fn (Container $container): AuditTrailMiddleware => new AuditTrailMiddleware(
                $container->get(PDO::class),
                $container->get(AuditLogger::class),
                $container->get(ServerRepository::class),
                $container->get(NotificationOutboxRepository::class),
                $container->get(Translator::class)
            )
        );
        $container->set(
            WorkerHeartbeatRepository::class,
            static fn (Container $container): WorkerHeartbeatRepository =>
                new WorkerHeartbeatRepository($container->get(PDO::class))
        );
        $container->set(
            SystemHealthService::class,
            static fn (Container $container): SystemHealthService => new SystemHealthService(
                $container->get(PDO::class),
                $container->get(AppSettingsRepository::class),
                $container->get(ServerRepository::class),
                $container->get(MetricRepository::class),
                $container->get(WorkerHeartbeatRepository::class),
                $container->get(NotificationOutboxRepository::class),
                $container->get(ServerStatusService::class),
                (string) ($settings['app_version'] ?? 'development'),
                (string) ($settings['app_env'] ?? 'production')
            )
        );
        $container->set(
            MetricsIngestionService::class,
            static fn (Container $container): MetricsIngestionService =>
                new MetricsIngestionService(
                    $container->get(PDO::class),
                    $container->get(ThresholdEvaluator::class),
                    $container->get(NotificationOutboxRepository::class),
                    $container->get(AgentUpdateRepository::class),
                    $container->get(AgentVersionService::class)
                )
        );
        $container->set(
            PublicUrlResolver::class,
            static fn (): PublicUrlResolver => new PublicUrlResolver(
                (string) ($settings['public_base_url'] ?? '')
            )
        );
        $container->set(AgentInstallerService::class, static fn (): AgentInstallerService => new AgentInstallerService());
        $container->set(
            WindowsInstallerPackageService::class,
            static fn (): WindowsInstallerPackageService => new WindowsInstallerPackageService(
                dirname(__DIR__, 2) . '/resources/agent/windows'
            )
        );
        $container->set(
            AgentArtifactCatalog::class,
            static fn (): AgentArtifactCatalog => AgentArtifactCatalog::load(
                dirname(__DIR__, 2) . '/agent-dist'
            )
        );
        $container->set(
            AgentUpdateRepository::class,
            static fn (Container $container): AgentUpdateRepository =>
                new AgentUpdateRepository($container->get(PDO::class)
            )
        );
        $container->set(AgentVersionService::class, static fn (): AgentVersionService => new AgentVersionService());
        $container->set(
            AgentUpdateService::class,
            static fn (Container $container): AgentUpdateService => new AgentUpdateService(
                $container->get(PDO::class),
                $container->get(AgentUpdateRepository::class),
                $container->get(AgentVersionService::class),
                $container->get(AgentArtifactCatalog::class)
            )
        );
        $container->set(
            AgentFleetService::class,
            static fn (Container $container): AgentFleetService => new AgentFleetService(
                $container->get(PDO::class),
                $container->get(AgentUpdateService::class),
                $container->get(ServerStatusService::class)
            )
        );

        $applicationKey = base64_decode((string) $settings['app_key'], true);
        if ($applicationKey === false || strlen($applicationKey) !== 32) {
            throw new RuntimeException('APP_KEY must be a base64-encoded 32-byte key.');
        }
        $container->set(
            AgentCredentialIssuer::class,
            static fn (Container $container): AgentCredentialIssuer =>
                new AgentCredentialIssuer($container->get(PDO::class), $applicationKey)
        );
        $container->set(SecretCipher::class, static fn (): SecretCipher => new SecretCipher($applicationKey));
        $container->set(
            NotificationSettingsRepository::class,
            static fn (Container $container): NotificationSettingsRepository =>
                new NotificationSettingsRepository(
                    $container->get(PDO::class),
                    $container->get(SecretCipher::class),
                    $container->get(Translator::class)
                )
        );

        $container->set(
            AuthController::class,
            static fn (Container $container): AuthController => new AuthController(
                $container->get(PDO::class),
                $container->get(Twig::class),
                $applicationKey,
                $container->get(Translator::class)
            )
        );
        $container->set(
            SetupController::class,
            static fn (Container $container): SetupController => new SetupController(
                $container->get(PDO::class),
                $container->get(Twig::class),
                (string) $settings['setup_token'],
                $container->get(Translator::class)
            )
        );
        $container->set(
            DashboardController::class,
            static fn (Container $container): DashboardController => new DashboardController(
                $container->get(Twig::class),
                $container->get(ServerRepository::class),
                $container->get(ServerStatusService::class),
                $container->get(Translator::class),
                $container->get(SystemHealthService::class),
                $container->get(IncidentRepository::class),
                $container->get(WebsiteRepository::class)
            )
        );
        $container->set(
            GroupController::class,
            static fn (Container $container): GroupController => new GroupController(
                $container->get(PDO::class),
                $container->get(Twig::class),
                $container->get(ServerStatusService::class),
                $container->get(Translator::class),
                null,
                $container->get(WebsiteRepository::class)
            )
        );
        $container->set(
            ServerController::class,
            static fn (Container $container): ServerController => new ServerController(
                $container->get(PDO::class),
                $container->get(Twig::class),
                $container->get(AgentCredentialIssuer::class),
                $container->get(AgentUpdateService::class),
                $container->get(ServerStatusService::class),
                $container->get(Translator::class)
            )
        );
        $container->set(
            AgentFleetController::class,
            static fn (Container $container): AgentFleetController => new AgentFleetController(
                $container->get(Twig::class),
                $container->get(AgentFleetService::class),
                $container->get(Translator::class)
            )
        );
        $container->set(
            WebsiteController::class,
            static fn (Container $container): WebsiteController => new WebsiteController(
                $container->get(Twig::class),
                $container->get(WebsiteRepository::class),
                $container->get(WebsiteEndpointValidator::class),
                $container->get(WebsiteCheckQueueRepository::class),
                $container->get(Translator::class),
            )
        );
        $container->set(
            WebsiteMetricsRepository::class,
            static fn (Container $container): WebsiteMetricsRepository => new WebsiteMetricsRepository(
                $container->get(PDO::class)
            )
        );
        $container->set(
            WebsiteDetailController::class,
            static fn (Container $container): WebsiteDetailController => new WebsiteDetailController(
                $container->get(PDO::class),
                $container->get(Twig::class),
                $container->get(WebsiteRepository::class),
                $container->get(WebsiteMetricsRepository::class),
                $container->get(MaintenanceWindowRepository::class),
                $container->get(Translator::class),
                $container->get(IncidentRepository::class),
            )
        );
        $container->set(
            WebsiteMetricsApiController::class,
            static fn (Container $container): WebsiteMetricsApiController => new WebsiteMetricsApiController(
                $container->get(PDO::class),
                $container->get(WebsiteRepository::class),
                $container->get(WebsiteMetricsRepository::class),
                $container->get(IncidentRepository::class),
            )
        );
        $container->set(
            ServerDetailController::class,
            static fn (Container $container): ServerDetailController => new ServerDetailController(
                $container->get(PDO::class),
                $container->get(Twig::class),
                $container->get(ServerRepository::class),
                $container->get(MetricRepository::class),
                $container->get(MaintenanceWindowRepository::class),
                $container->get(AgentUpdateService::class),
                $container->get(ServerStatusService::class),
                $container->get(Translator::class)
            )
        );
        $container->set(
            AlertController::class,
            static fn (Container $container): AlertController => new AlertController(
                $container->get(PDO::class),
                $container->get(Twig::class),
                $container->get(NotificationOutboxRepository::class),
                $container->get(Translator::class),
                $container->get(IncidentRepository::class)
            )
        );
        $container->set(
            AdminController::class,
            static fn (Container $container): AdminController => new AdminController(
                $container->get(PDO::class),
                $container->get(Twig::class),
                $container->get(NotificationSettingsRepository::class),
                $container->get(NotificationOutboxRepository::class),
                $container->get(WorkerHeartbeatRepository::class),
                $container->get(Translator::class)
            )
        );
        $container->set(
            SystemController::class,
            static fn (Container $container): SystemController => new SystemController(
                $container->get(PDO::class),
                $container->get(Twig::class),
                $container->get(AppSettingsRepository::class),
                $container->get(SystemHealthService::class),
                $container->get(Translator::class),
                $container->get(AuditLogger::class),
                (string) $settings['dr_root']
            )
        );
        $container->set(
            LanguageController::class,
            static fn (Container $container): LanguageController => new LanguageController(
                $container->get(AppSettingsRepository::class),
                $container->get(Translator::class)
            )
        );
        $container->set(
            AgentController::class,
            static fn (Container $container): AgentController => new AgentController(
                $container->get(PDO::class),
                $container->get(PublicUrlResolver::class),
                $container->get(AgentCredentialIssuer::class),
                $container->get(AgentInstallerService::class),
                $container->get(WindowsInstallerPackageService::class),
                static fn (): AgentArtifactCatalog => $container->get(AgentArtifactCatalog::class),
                static fn (): AgentUpdateService => $container->get(AgentUpdateService::class)
            )
        );
        $container->set(
            AgentUpdateController::class,
            static fn (Container $container): AgentUpdateController =>
                new AgentUpdateController(
                    $container->get(AgentUpdateService::class),
                    $container->get(Translator::class)
                )
        );
        $container->set(
            AuditController::class,
            static fn (Container $container): AuditController => new AuditController(
                $container->get(Twig::class),
                $container->get(AuditLogRepository::class),
                $container->get(AuditRetentionService::class),
                $container->get(AuditLogger::class),
                $container->get(Translator::class)
            )
        );
        $container->set(
            MetricsController::class,
            static fn (Container $container): MetricsController => new MetricsController(
                $container->get(PDO::class),
                $container->get(MetricsValidator::class),
                $container->get(MetricsIngestionService::class)
            )
        );
        $container->set(
            MetricsApiController::class,
            static fn (Container $container): MetricsApiController => new MetricsApiController(
                $container->get(MetricRepository::class)
            )
        );

        return $container;
    }

    /** @return App<*> */
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
