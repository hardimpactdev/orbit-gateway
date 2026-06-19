<?php

declare(strict_types=1);

use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

it('keeps command action progress streams on their canonical api routes', function (): void {
    $allowedStreamRoutes = [];

    $violations = collect(Route::getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/'))
        ->filter(fn (string $uri): bool => str_contains($uri, '/stream'))
        ->reject(fn (string $uri): bool => in_array($uri, $allowedStreamRoutes, true))
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

it('keeps gateway stream requests on canonical action endpoints', function (): void {
    $allowedRequestFiles = [];

    $violations = collect(File::allFiles(app_path('Http/Gateway/Requests')))
        ->reject(fn (SplFileInfo $file): bool => in_array($file->getPathname(), $allowedRequestFiles, true))
        ->filter(fn (SplFileInfo $file): bool => preg_match("/return ['\"]\\/api\\/[^'\"]*\\/stream['\"];/", File::get($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

it('preserves real-time streaming headers on gateway api stream routes', function (): void {
    $factory = new ProgressEventStreamResponseFactory;

    $response = $factory->make(function (): void {
        echo "data: test\n\n";
    });

    expect($response->headers->get('Content-Type'))->toBe('text/event-stream')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->get('Connection'))->toBe('keep-alive')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no');
});

it('does not use laravel http for gateway transport', function (): void {
    $gatewayTransportPaths = [
        app_path('Console/Commands'),
        app_path('Http/Gateway'),
        app_path('Services/Gateway'),
    ];

    $violations = collect($gatewayTransportPaths)
        ->flatMap(fn (string $path): array => File::allFiles($path))
        ->filter(fn (SplFileInfo $file): bool => str_contains(File::get($file->getPathname()), 'Http::'))
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($violations)->toBe([]);
});
