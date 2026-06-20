<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Platform;

use App\Services\Platform\PlatformDetector;
use RuntimeException;

it('builds macos platform identifiers', function (): void {
    $detector = new PlatformDetector;

    expect($detector->macOsIdentifier("15.4.1\n"))->toBe('macos_15-4-1');
});

it('builds linux platform identifiers from os-release', function (): void {
    $detector = new PlatformDetector;

    expect($detector->linuxIdentifier(<<<'OS'
NAME="Ubuntu"
ID=ubuntu
VERSION_ID="24.04"
PRETTY_NAME="Ubuntu 24.04 LTS"
OS))->toBe('ubuntu_24-04');
});

it('fails when linux platform metadata is incomplete', function (): void {
    $detector = new PlatformDetector;

    expect(fn (): string => $detector->linuxIdentifier('ID=ubuntu'))
        ->toThrow(RuntimeException::class, 'Linux platform metadata is incomplete.');
});
