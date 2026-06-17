<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$gatewayRoot = dirname(__DIR__);

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $gatewayRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $gatewayRoot.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $gatewayRoot.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
