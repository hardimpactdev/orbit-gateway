<?php

declare(strict_types=1);

it('serves the gateway API through bundled FrankenPHP and Caddy', function (): void {
    $entrypoint = file_get_contents(repo_path('docker/orbit-gateway/entrypoint.sh'));
    $caddyfile = file_get_contents(repo_path('docker/orbit-gateway/Caddyfile'));

    expect($entrypoint)
        ->toContain('run_gateway()')
        ->toContain('exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile')
        ->toContain('serve)')
        ->and($caddyfile)
        ->toContain(':8080')
        ->toContain('php_server')
        ->toContain('root * /srv/orbit/apps/gateway/public');
});

it('runs the scheduler through the gateway artisan command', function (): void {
    $entrypoint = file_get_contents(repo_path('docker/orbit-gateway/entrypoint.sh'));

    expect($entrypoint)
        ->toContain('scheduler)')
        ->toContain('run_artisan orbit-scheduler "$@"')
        ->not->toContain('PHP_CLI_SERVER_WORKERS')
        ->not->toContain('php "$artisan" serve');
});

it('supports mounted source-dev gateway app roots without a separate orbit-gateway entrypoint', function (): void {
    $entrypoint = file_get_contents(repo_path('docker/orbit-gateway/entrypoint.sh'));

    expect($entrypoint)
        ->toContain('ORBIT_GATEWAY_APP_ROOT:-/srv/orbit/apps/gateway')
        ->toContain('run_artisan "$@"')
        ->not->toContain('ORBIT_SOURCE_PATH')
        ->not->toContain('orbit-gateway');
});
