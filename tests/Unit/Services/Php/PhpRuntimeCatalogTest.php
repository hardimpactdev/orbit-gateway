<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Php;

use App\Services\Php\PhpRuntimeCatalog;
use InvalidArgumentException;

it('frankenphp resolves supported PHP versions to approved glibc image references', function (string $version, string $image): void {
    $catalog = new PhpRuntimeCatalog;

    expect($catalog->imageFor($version))->toBe($image)
        ->and($catalog->versionForImage($image))->toBe($version)
        ->and($catalog->isApprovedImage($image))->toBeTrue();
})->with([
    'php 8.5' => ['8.5', 'dunglas/frankenphp:1-php8.5-bookworm'],
    'php 8.4' => ['8.4', 'dunglas/frankenphp:1-php8.4-bookworm'],
    'php 8.3' => ['8.3', 'dunglas/frankenphp:1-php8.3-bookworm'],
]);

it('frankenphp rejects unsupported PHP versions before image resolution', function (): void {
    $catalog = new PhpRuntimeCatalog;

    expect(fn (): string => $catalog->imageFor('8.2'))
        ->toThrow(InvalidArgumentException::class, "Unsupported PHP version '8.2'.");
});

it('frankenphp rejects host PHP FPM CLI and Alpine fallback image references', function (string $image): void {
    $catalog = new PhpRuntimeCatalog;

    expect($catalog->isApprovedImage($image))->toBeFalse()
        ->and(fn (): string => $catalog->versionForImage($image))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'host fpm package' => ['php8.5-fpm'],
    'host fpm binary' => ['/usr/sbin/php-fpm8.5'],
    'fpm docker image' => ['php:8.5-fpm-bookworm'],
    'cli docker image' => ['php:8.5-cli-bookworm'],
    'alpine frankenphp image' => ['dunglas/frankenphp:1-php8.5-alpine'],
]);
