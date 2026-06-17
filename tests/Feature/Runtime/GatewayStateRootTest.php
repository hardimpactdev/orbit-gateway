<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('resolves gateway env and sqlite under ORBIT_CONFIG_ROOT while keeping framework paths app local', function (): void {
    $configRoot = sys_get_temp_dir().'/orbit-config-root-'.bin2hex(random_bytes(4));

    $process = new Process([
        PHP_BINARY,
        '-r',
        'putenv("ORBIT_CONFIG_ROOT='.$configRoot.'"); $paths = require "apps/gateway/bootstrap/orbit_paths.php"; echo json_encode($paths, JSON_THROW_ON_ERROR);',
    ], repo_path());

    $process->mustRun();

    $paths = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($paths['config_root'])->toBe($configRoot)
        ->and($paths['env_path'])->toBe($configRoot.'/.env')
        ->and($paths['database_file'])->toBe($configRoot.'/gateway.sqlite')
        ->and($paths['database_path'])->toBe(repo_path('apps/gateway/database'))
        ->and($paths['storage_path'])->toBe(repo_path('apps/gateway/storage'));
});

it('falls back to HOME config root when ORBIT_CONFIG_ROOT is absent', function (): void {
    $home = sys_get_temp_dir().'/orbit-home-'.bin2hex(random_bytes(4));

    $process = new Process([
        PHP_BINARY,
        '-r',
        'putenv("ORBIT_CONFIG_ROOT"); putenv("HOME='.$home.'"); $paths = require "apps/gateway/bootstrap/orbit_paths.php"; echo json_encode($paths, JSON_THROW_ON_ERROR);',
    ], repo_path());

    $process->mustRun();

    $paths = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($paths['config_root'])->toBe($home.'/.config/orbit')
        ->and($paths['env_path'])->toBe($home.'/.config/orbit/.env')
        ->and($paths['database_file'])->toBe($home.'/.config/orbit/gateway.sqlite')
        ->and($paths['database_path'])->toBe(repo_path('apps/gateway/database'))
        ->and($paths['storage_path'])->toBe(repo_path('apps/gateway/storage'));
});
