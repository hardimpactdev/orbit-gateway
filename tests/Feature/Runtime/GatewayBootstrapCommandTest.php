<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('can generate an app key before the gateway sqlite database has been migrated', function (): void {
    $configRoot = storage_path('framework/testing/bootstrap-empty-db');

    File::deleteDirectory($configRoot);
    File::ensureDirectoryExists($configRoot);
    File::put($configRoot.'/.env', implode(PHP_EOL, [
        'APP_NAME=Orbit',
        'APP_ENV=testing',
        'APP_KEY=',
        'CACHE_STORE=database',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE='.$configRoot.'/gateway.sqlite',
        'SESSION_DRIVER=file',
        '',
    ]));
    File::put($configRoot.'/gateway.sqlite', '');

    $process = new Process(
        [PHP_BINARY, 'artisan', 'key:generate', '--no-interaction'],
        base_path(),
        [
            'APP_ENV' => false,
            'APP_KEY' => false,
            'ORBIT_CONFIG_ROOT' => $configRoot,
            'CACHE_STORE' => 'database',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $configRoot.'/gateway.sqlite',
            'SESSION_DRIVER' => 'file',
        ],
    );
    $process->run();

    expect($process->isSuccessful())
        ->toBeTrue($process->getErrorOutput() ?: $process->getOutput())
        ->and(File::get($configRoot.'/.env'))
        ->toContain('APP_KEY=base64:');
});
