<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Php;

use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use InvalidArgumentException;

it('frankenphp creates a shared classic runtime policy with approved image and performance defaults', function (): void {
    $runtime = new PhpRuntimePolicy(new PhpRuntimeCatalog)->forVersion('8.5');

    expect($runtime->phpVersion)->toBe('8.5')
        ->and($runtime->image)->toBe('dunglas/frankenphp:1-php8.5-bookworm')
        ->and($runtime->mode)->toBe('classic')
        ->and($runtime->phpIni)->toMatchArray([
            'opcache.enable' => '1',
            'opcache.enable_cli' => '1',
            'opcache.memory_consumption' => '256',
            'opcache.max_accelerated_files' => '20000',
            'realpath_cache_size' => '4096K',
            'realpath_cache_ttl' => '600',
        ])
        ->and(array_key_exists('opcache.preload', $runtime->phpIni))->toBeFalse();
});

it('frankenphp adds preload policy only when a preload script is configured', function (): void {
    $runtime = new PhpRuntimePolicy(new PhpRuntimeCatalog)->forVersion(
        version: '8.5',
        preloadPath: '/app/bootstrap/cache/preload.php',
    );

    expect($runtime->phpIni['opcache.preload'])->toBe('/app/bootstrap/cache/preload.php');
});

it('frankenphp policy rejects unsupported runtime versions', function (): void {
    $policy = new PhpRuntimePolicy(new PhpRuntimeCatalog);

    expect(fn () => $policy->forVersion('8.2'))
        ->toThrow(InvalidArgumentException::class, "Unsupported PHP version '8.2'.");
});
