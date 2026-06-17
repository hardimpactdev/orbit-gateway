<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$key = getenv('APP_KEY');

if (! is_string($key) || trim($key) === '') {
    $key = 'base64:'.base64_encode(str_repeat('0', 32));

    putenv("APP_KEY={$key}");
    $_ENV['APP_KEY'] = $key;
    $_SERVER['APP_KEY'] = $key;
}
