<?php

declare(strict_types=1);

use App\Application\Bootstrap;

require dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::fromEnvironment()->app()->run();
