<?php
// public/index.php

use App\Controllers\AgentController;
use App\Controllers\AdminController;
use App\Controllers\AlertController;
use App\Controllers\Api\MetricsController;
use App\Controllers\GroupController;
use App\Controllers\ServerController;
use App\Controllers\ServerDetailController;
use App\Controllers\DashboardController;
use App\Middlewares\AuthMiddleware;
use App\Middlewares\SessionMiddleware;
use App\Models\User;
use App\Models\Server as ServerModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Csrf\Guard;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require_once __DIR__ . '/../vendor/autoload.php';

// Start session
session_start();

// Create Slim app
$app = AppFactory::create();

// Create CSRF Guard
$csrf = new Guard($app->getResponseFactory());
$csrf->setPersistentTokenMode(true);

// Create Twig view
$twig = Twig::create(__DIR__ . '/../templates', ['cache' => false]);

// Add Twig middleware
$twigMiddleware = TwigMiddleware::create($app, $twig);
$app->add($twigMiddleware);

// Add session middleware
$sessionMiddleware = new SessionMiddleware($twig);
$app->add($sessionMiddleware);

// Add CSRF middleware (will be applied selectively)
$csrfMiddleware = $csrf;

// Add a route to get CSRF tokens via AJAX
$app->get('/csrf-token', function (Request $request, Response $response, $args) use ($csrf) {
    $data = [
        'name_key' => $csrf->getTokenNameKey(),
        'value_key' => $csrf->getTokenValueKey(),
        'name' => $csrf->getTokenName(),
        'value' => $csrf->getTokenValue()
    ];

    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

// Define /test route
$app->get('/test', function (Request $request, Response $response, $args) use ($twig) {
    $templateData = [
        'title' => 'Тест системы',
        'message' => 'Система мониторинга запущена'
    ];

    return $twig->render($response, 'test.twig', $templateData);
});

// Login routes (without auth middleware, but with CSRF)
$app->get('/login', function (Request $request, Response $response, $args) use ($twig, $csrf) {
    $templateData = [
        'title' => 'Вход в систему',
        'csrf' => [
            'name_key' => $csrf->getTokenNameKey(),
            'value_key' => $csrf->getTokenValueKey(),
            'name' => $csrf->getTokenName(),
            'value' => $csrf->getTokenValue()
        ]
    ];

    return $twig->render($response, 'login.twig', $templateData);
});

$app->post('/login', function (Request $request, Response $response, $args) use ($csrf) {
    $params = $request->getParsedBody();

    // Validate CSRF token
    $nameKey = $csrf->getTokenNameKey();
    $valueKey = $csrf->getTokenValueKey();

    if (!isset($params[$nameKey]) || !isset($params[$valueKey]) || !$csrf->validateToken($params[$nameKey], $params[$valueKey])) {
        error_log('CSRF validation failed for /login');
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    $username = $params['username'] ?? '';
    $password = $params['password'] ?? '';

    $userModel = new User();
    $user = $userModel->authenticate($username, $password);

    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        return $response->withHeader('Location', '/')->withStatus(302);
    } else {
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
});

// Logout route (without auth middleware)
$app->get('/logout', function (Request $request, Response $response, $args) {
    session_destroy();
    return $response->withHeader('Location', '/login')->withStatus(302);
});

// Dashboard route (protected with auth middleware)
$dashboardGroup = $app->group('', function ($group) use ($twig) {
    $group->get('/', function (Request $request, Response $response, $args) use ($twig) {
        $serverModel = new ServerModel();

        // Get statistics
        $stats = $serverModel->getStats();

        // Get servers with latest metrics
        $servers = $serverModel->getAll();

        $templateData = [
            'title' => 'Дашборд мониторинга',
            'stats' => $stats,
            'servers' => $servers
        ];

        return $twig->render($response, 'dashboard.twig', $templateData);
    });
})->add($csrfMiddleware)->add(AuthMiddleware::class);

// Create controllers BEFORE routes
$groupController = new GroupController($twig);
$serverController = new ServerController($twig);
$serverDetailController = new ServerDetailController($twig);
$alertController = new AlertController($twig);
$adminController = new AdminController($twig);
$metricsController = new MetricsController();
$agentController = new AgentController();

// Routes for groups (protected with auth middleware and csrf)
$groupsGroup = $app->group('/groups', function ($group) use ($groupController) {
    $group->get('', [$groupController, 'index']);
    $group->get('/create', [$groupController, 'create']);
    $group->post('', [$groupController, 'store']);
    $group->get('/{id}/edit', [$groupController, 'edit']);
    $group->post('/{id}', [$groupController, 'update']);
    $group->delete('/{id}', [$groupController, 'delete']);
    $group->get('/{id}', [$groupController, 'show']);
})->add($csrfMiddleware)->add(AuthMiddleware::class);

// Routes for servers (protected with auth middleware and csrf)
$serversGroup = $app->group('/servers', function ($group) use ($serverController, $serverDetailController) {
    $group->get('', [$serverController, 'index']);
    $group->get('/create', [$serverController, 'create']);
    $group->post('', [$serverController, 'store']);
    $group->get('/{id}/edit', [$serverController, 'edit']);
    $group->post('/{id}', [$serverController, 'update']);
    $group->delete('/{id}', [$serverController, 'delete']);
    $group->get('/{id}/regenerate-token', [$serverController, 'regenerateToken']);
    $group->post('/{id}/thresholds', [$serverDetailController, 'saveThresholds']);
    $group->post('/{id}/services', [$serverDetailController, 'saveServices']);
})->add($csrfMiddleware)->add(AuthMiddleware::class);

// Server detail route (protected with auth middleware and csrf)
$app->get('/servers/{id}', [$serverDetailController, 'show'])->add(AuthMiddleware::class);

// Alerts routes (protected with auth middleware and csrf)
$alertsGroup = $app->group('/alerts', function ($group) use ($alertController) {
    $group->get('', [$alertController, 'index']);
    $group->get('/{id}/resolve', [$alertController, 'markAsResolved']);
})->add($csrfMiddleware)->add(AuthMiddleware::class);

// Admin routes (protected with auth middleware and csrf)
$adminGroup = $app->group('/admin', function ($group) use ($adminController) {
    $group->get('/users', [$adminController, 'usersList']);
    $group->get('/notifications', [$adminController, 'notificationSettings']);
})->add($csrfMiddleware)->add(AuthMiddleware::class);

// API route for agents (public, no auth middleware, no csrf)
$app->post('/api/v1/metrics', [$metricsController, 'collectMetrics']);
$app->get("/api/v1/agent/{id}/services", [$metricsController, 'getServices'])->add(AuthMiddleware::class);

// Agent configuration routes (protected with auth middleware and csrf)
$agentGroup = $app->group('/agent', function ($group) use ($agentController) {
    $group->get("/{id}/config", [$agentController, 'getConfig']);
    $group->post("/{id}/config", [$agentController, 'updateConfig']);
    $group->get("/{id}/status", [$agentController, 'getStatus']);
})->add($csrfMiddleware)->add(AuthMiddleware::class);

// API status endpoint (public, no auth middleware, no csrf)
$app->get('/api/status', function (Request $request, Response $response, $args) {
    $data = [
        'status' => 'ok',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0.0'
    ];

    $response->getBody()->write(json_encode($data));
    return $response
        ->withHeader('Content-Type', 'application/json');
});

// Agent installation script route (public, no auth middleware, no csrf)
$app->get('/agent/install.sh', [$agentController, 'generateInstallScript']);

// Run app
$app->run();