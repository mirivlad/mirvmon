<?php

declare(strict_types=1);

use App\Application\AppFactory;
use App\Application\Bootstrap;
use App\Database\ConnectionFactory;

$appRoot = getenv('DR_ACCEPTANCE_APP_ROOT') ?: dirname(__DIR__, 2);
require $appRoot . '/vendor/autoload.php';

$selectorPath = requiredEnvironment('DR_ACCEPTANCE_SELECTOR');
$mode = trim((string) @file_get_contents($selectorPath));
if (!in_array($mode, ['a', 'b'], true)) {
    http_response_code(503);
    echo "acceptance backend unavailable";
    return;
}

$suffix = strtoupper($mode);
$database = requiredEnvironment('DR_ACCEPTANCE_DB_' . $suffix);
$key = requiredEnvironment('DR_ACCEPTANCE_KEY_' . $suffix);
$pdo = ConnectionFactory::connect([
    'DB_HOST' => requiredEnvironment('DR_ACCEPTANCE_DB_HOST'),
    'DB_PORT' => getenv('DR_ACCEPTANCE_DB_PORT') ?: '5432',
    'DB_NAME' => $database,
    'DB_USERNAME' => requiredEnvironment('DR_ACCEPTANCE_DB_USERNAME'),
    'DB_PASSWORD' => requiredEnvironment('DR_ACCEPTANCE_DB_PASSWORD'),
    'DB_SSLMODE' => 'disable',
]);

$root = requiredEnvironment('DR_ACCEPTANCE_ROOT');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$failNextMetrics = $root . '/fail-next-metrics';
if ($requestPath === '/api/v1/metrics' && is_file($failNextMetrics)) {
    @unlink($failNextMetrics);
    $maintenanceRoot = $root . '/http-dr';
    if (!is_dir($maintenanceRoot) && !mkdir($maintenanceRoot, 0700, true) && !is_dir($maintenanceRoot)) {
        throw new RuntimeException('Cannot create acceptance maintenance directory.');
    }
    file_put_contents(
        $maintenanceRoot . '/maintenance.json',
        json_encode(['active' => true, 'started_at' => gmdate('c'), 'test' => 'agent_queue_retry'], JSON_THROW_ON_ERROR) . "\n",
        LOCK_EX
    );
}
$container = Bootstrap::createContainer([
    'app_env' => 'test',
    'app_debug' => false,
    'app_key' => $key,
    'setup_token' => str_repeat('s', 64),
    'timezone' => 'UTC',
    'trusted_proxies' => [],
    'session_name' => 'mirvmon_dr_acceptance',
    'session_secure' => false,
    'max_request_bytes' => 1048576,
    'app_version' => 'dr-acceptance',
    'public_base_url' => requiredEnvironment('DR_ACCEPTANCE_BASE_URL'),
    'templates_path' => $appRoot . '/templates',
    'translations_path' => $appRoot . '/translations',
    'twig_cache' => false,
    'dr_root' => $root . '/http-dr',
], $pdo);

AppFactory::create($container)->run();

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException($name . ' is required.');
    }
    return $value;
}
