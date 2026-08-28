<?php

declare(strict_types=1);

namespace App\Application;

use App\Controllers\AdminController;
use App\Controllers\AgentController;
use App\Controllers\AgentUpdateController;
use App\Controllers\AlertController;
use App\Controllers\Api\MetricsApiController;
use App\Controllers\Api\MetricsController;
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
use App\Http\ErrorResponder;
use App\I18n\Translator;
use App\Middlewares\AdminMiddleware;
use App\Middlewares\AuditTrailMiddleware;
use App\Middlewares\AuthMiddleware;
use App\Middlewares\CsrfMiddleware;
use App\Middlewares\LocaleMiddleware;
use App\Middlewares\RequestIdMiddleware;
use App\Middlewares\RequestSizeMiddleware;
use App\Middlewares\SecurityHeadersMiddleware;
use App\Middlewares\SessionMiddleware;
use App\Middlewares\SessionSecurityMiddleware;
use App\Middlewares\TrustedProxyMiddleware;
use Closure;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

final class AppFactory
{
    /** @return App<*> */
    public static function create(ContainerInterface $container): App
    {
        $app = SlimAppFactory::create();
        /** @var array<string, mixed> $settings */
        $settings = $container->get('settings');
        /** @var Twig $twig */
        $twig = $container->get(Twig::class);
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);
        /** @var Translator $translator */
        $translator = $container->get(Translator::class);
        $translator->refreshLocale();
        $responseFactory = $app->getResponseFactory();
        $locale = new LocaleMiddleware($translator);

        $csrf = new CsrfMiddleware($responseFactory, $twig);
        $auth = new AuthMiddleware($responseFactory, $pdo);
        $admin = new AdminMiddleware($responseFactory);
        /** @var AuditTrailMiddleware $auditTrail */
        $auditTrail = $container->get(AuditTrailMiddleware::class);

        $app->get('/login', self::controller($container, AuthController::class, 'form'))->add($csrf);
        $app->post('/login', self::controller($container, AuthController::class, 'login'))->add($csrf);
        $app->get('/setup', self::controller($container, SetupController::class, 'form'))->add($csrf);
        $app->post('/setup', self::controller($container, SetupController::class, 'create'))->add($csrf);

        $app->get('/agent/binaries/{artifact:[a-z0-9-]+}', self::controller($container, AgentController::class, 'downloadBinary'));
        $app->get('/agent/install.sh', self::controller($container, AgentController::class, 'generateInstallScript'));
        $app->get('/agent/install.exe', self::controller($container, AgentController::class, 'generateWindowsInstaller'));

        $protected = $app->group('', function (RouteCollectorProxyInterface $group) use ($container, $admin): void {
            $group->get('/', self::controller($container, DashboardController::class, 'index'));
            $group->post('/logout', self::controller($container, AuthController::class, 'logout'));
            $group->get('/api/dashboard/stats', self::controller($container, DashboardController::class, 'getDashboardData'));
            $group->get('/api/agent-updates/status', self::controller($container, AgentUpdateController::class, 'statuses'));
            $group->get('/api/servers/{id}/metrics', self::controller($container, MetricsApiController::class, 'getServerMetrics'));
            $group->get('/api/v1/agent/{id}/services', self::controller($container, MetricsController::class, 'getServices'));
            $group->get('/api/v1/agent/{id}/processes', self::controller($container, MetricsController::class, 'getProcesses'));

            $group->get('/groups', self::controller($container, GroupController::class, 'index'));
            $group->get('/groups/create', self::controller($container, GroupController::class, 'create'));
            $group->post('/groups', self::controller($container, GroupController::class, 'store'));
            $group->get('/groups/{id}/edit', self::controller($container, GroupController::class, 'edit'));
            $group->post('/groups/{id}', self::controller($container, GroupController::class, 'update'));
            $group->post('/groups/{id}/delete', self::controller($container, GroupController::class, 'delete'));
            $group->get('/groups/{id}', self::controller($container, GroupController::class, 'show'));

            $group->get('/server/{id}', static function (ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface {
                return $response->withHeader('Location', '/servers/' . $arguments['id'])->withStatus(301);
            });
            $group->get('/servers', self::controller($container, ServerController::class, 'index'));
            $group->get('/servers/create', self::controller($container, ServerController::class, 'create'));
            $group->post('/servers', self::controller($container, ServerController::class, 'store'));
            $group->get('/servers/{id}/edit', self::controller($container, ServerController::class, 'edit'));
            $group->post('/servers/{id}', self::controller($container, ServerController::class, 'update'));
            $group->post('/servers/{id}/delete', self::controller($container, ServerController::class, 'delete'));
            $group->post('/servers/{id}/installers', self::controller($container, ServerController::class, 'installers'));
            $group->post('/servers/{id}/agent/update', self::controller($container, AgentUpdateController::class, 'requestUpdate'))->add($admin);
            $group->post('/servers/{id}/regenerate-token', self::controller($container, ServerController::class, 'regenerateToken'))->add($admin);
            $group->post('/servers/{id}/maintenance', self::controller($container, ServerDetailController::class, 'startMaintenance'));
            $group->post('/servers/{id}/maintenance/cancel', self::controller($container, ServerDetailController::class, 'cancelMaintenance'));
            $group->post('/servers/{id}/thresholds', self::controller($container, ServerDetailController::class, 'saveThresholds'));
            $group->post('/servers/{id}/services', self::controller($container, ServerDetailController::class, 'saveServices'));
            $group->get('/servers/{id}', self::controller($container, ServerDetailController::class, 'show'));

            $group->get('/sites', self::controller($container, WebsiteController::class, 'index'));
            $group->get('/sites/create', self::controller($container, WebsiteController::class, 'create'))->add($admin);
            $group->post('/sites', self::controller($container, WebsiteController::class, 'store'))->add($admin);
            $group->get('/sites/{id}/edit', self::controller($container, WebsiteController::class, 'edit'))->add($admin);
            $group->post('/sites/{id}', self::controller($container, WebsiteController::class, 'update'))->add($admin);
            $group->post('/sites/{id}/delete', self::controller($container, WebsiteController::class, 'delete'))->add($admin);
            $group->post('/sites/{id}/pause', self::controller($container, WebsiteController::class, 'pause'))->add($admin);
            $group->post('/sites/{id}/resume', self::controller($container, WebsiteController::class, 'resume'))->add($admin);
            $group->post('/sites/{id}/check', self::controller($container, WebsiteController::class, 'check'))->add($admin);

            $group->get('/alerts', self::controller($container, AlertController::class, 'index'));
            $group->post('/alerts/{id}/resolve', self::controller($container, AlertController::class, 'markAsResolved'));
            $group->get('/agent/{id}/config', self::controller($container, AgentController::class, 'getConfig'));
            $group->post('/agent/{id}/config', self::controller($container, AgentController::class, 'updateConfig'));
            $group->get('/agent/{id}/status', self::controller($container, AgentController::class, 'getStatus'));
        });
        $protected->add($auditTrail)->add($csrf)->add($auth);

        $administration = $app->group('/admin', function (RouteCollectorProxyInterface $group) use ($container): void {
            $group->get('/users', self::controller($container, AdminController::class, 'usersList'));
            $group->post('/users/save', self::controller($container, AdminController::class, 'saveUser'));
            $group->post('/users/{id}/delete', self::controller($container, AdminController::class, 'deleteUser'));
            $group->get('/notifications', self::controller($container, AdminController::class, 'notificationSettings'));
            $group->post('/notifications/save', self::controller($container, AdminController::class, 'saveNotificationSettings'));
            $group->post('/notifications/test', self::controller($container, AdminController::class, 'testNotification'));
            $group->post('/notifications/queue/retry', self::controller($container, AdminController::class, 'retryNotificationQueue'));
            $group->get('/notifications/queue', self::controller($container, AdminController::class, 'notificationQueue'));
            $group->post('/notifications/queue/{id}/retry', self::controller($container, AdminController::class, 'retryNotificationJob'));
            $group->post('/notifications/queue/{id}/delete', self::controller($container, AdminController::class, 'deleteNotificationJob'));
            $group->post('/notifications/queue/delete', self::controller($container, AdminController::class, 'deleteNotificationQueue'));
            $group->get('/defaults', self::controller($container, AdminController::class, 'defaultSettings'));
            $group->post('/defaults/save', self::controller($container, AdminController::class, 'saveDefaultSettings'));
            $group->post('/language', self::controller($container, LanguageController::class, 'save'));
            $group->get('/system', self::controller($container, SystemController::class, 'index'));
            $group->post('/system/host', self::controller($container, SystemController::class, 'saveHost'));
            $group->get('/audit', self::controller($container, AuditController::class, 'index'));
            $group->post('/audit/retention', self::controller($container, AuditController::class, 'saveRetention'));
        });
        $administration->add($auditTrail)->add($csrf)->add($admin)->add($auth);

        $app->post('/api/v1/metrics', self::controller($container, MetricsController::class, 'collectMetrics'));
        $app->get('/api/v1/agent/config', self::controller($container, AgentController::class, 'getAgentConfig'));
        $app->post('/api/v1/agent/install', self::controller($container, AgentController::class, 'activateInstaller'));
        $app->post('/api/v1/agent/update/{command}/status', self::controller($container, AgentUpdateController::class, 'reportStatus'));
        $app->get('/livez', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write('alive');
            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        });
        $app->get('/readyz', static function (ServerRequestInterface $request, ResponseInterface $response) use ($container): ResponseInterface {
            $container->get(PDO::class)->query('SELECT 1');
            $response->getBody()->write('ready');
            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        });

        $app->addBodyParsingMiddleware();
        $app->add(TwigMiddleware::create($app, $twig));
        $app->add(new SessionMiddleware($twig));
        $app->add($locale);
        $app->add(new SessionSecurityMiddleware(
            (string) $settings['session_name'],
            (bool) $settings['session_secure'],
            statelessPaths: [
                '/livez',
                '/readyz',
                '/api/v1/metrics',
                '/agent/binaries/*',
                '/agent/install.sh',
                '/agent/install.exe',
                '/api/v1/agent/config',
                '/api/v1/agent/install',
                '/api/v1/agent/update/*/status',
            ]
        ));
        $app->add(new RequestSizeMiddleware($responseFactory, (int) $settings['max_request_bytes']));
        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware(false, false, false);
        $errorMiddleware->setDefaultErrorHandler(new ErrorResponder(
            $responseFactory,
            (bool) $settings['app_debug'],
            ($settings['app_env'] ?? 'production') !== 'test',
            $translator
        ));
        $app->add(new SecurityHeadersMiddleware(new StreamFactory()));
        $app->add(new RequestIdMiddleware());
        /** @var list<string> $trustedProxies */
        $trustedProxies = $settings['trusted_proxies'];
        $app->add(new TrustedProxyMiddleware($trustedProxies));

        return $app;
    }

    /** @return Closure(ServerRequestInterface, ResponseInterface, array<string, string>): ResponseInterface */
    private static function controller(ContainerInterface $container, string $class, string $method): Closure
    {
        return static function (ServerRequestInterface $request, ResponseInterface $response, array $arguments) use ($container, $class, $method): ResponseInterface {
            return $container->get($class)->{$method}($request, $response, $arguments);
        };
    }
}
