<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;

if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

$app = require __DIR__.'/app.php';

if ($app instanceof Application) {
    $app->make(Kernel::class)->bootstrap();

    if (! defined('LARAVEL_VERSION')) {
        define('LARAVEL_VERSION', $app->version());
    }
}
