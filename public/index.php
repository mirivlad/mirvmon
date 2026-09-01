<?php

declare(strict_types=1);

use App\Application\Bootstrap;
use App\Backup\DrMaintenanceLock;
use App\Middlewares\DrMaintenanceMiddleware;
use Slim\Psr7\Factory\ResponseFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = Bootstrap::fromEnvironment()->app();
$app->add(new DrMaintenanceMiddleware(
    new ResponseFactory(),
    new DrMaintenanceLock(dirname(__DIR__) . '/var/dr')
));
$app->run();
