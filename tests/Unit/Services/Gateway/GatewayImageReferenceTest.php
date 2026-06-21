<?php

declare(strict_types=1);

use App\Services\Gateway\GatewayImageReference;

it('parses a registry image reference with tag and digest', function (): void {
    $reference = GatewayImageReference::fromString('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    expect($reference->registry())->toBe('ghcr.io')
        ->and($reference->repository())->toBe('hardimpactdev/orbit-gateway')
        ->and($reference->tag())->toBe('1.2.3')
        ->and($reference->digest())->toBe('sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($reference->isDigestPinned())->toBeTrue()
        ->and($reference->canonical())->toBe('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
});

it('parses a local image reference with a tag', function (): void {
    $reference = GatewayImageReference::fromString('orbit-gateway:current');

    expect($reference->registry())->toBeNull()
        ->and($reference->repository())->toBe('orbit-gateway')
        ->and($reference->tag())->toBe('current')
        ->and($reference->digest())->toBeNull()
        ->and($reference->isDigestPinned())->toBeFalse()
        ->and($reference->canonical())->toBe('orbit-gateway:current');
});

it('parses a digest-only registry image reference', function (): void {
    $reference = GatewayImageReference::fromString('ghcr.io/hardimpactdev/orbit-gateway@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

    expect($reference->registry())->toBe('ghcr.io')
        ->and($reference->repository())->toBe('hardimpactdev/orbit-gateway')
        ->and($reference->tag())->toBeNull()
        ->and($reference->digest())->toBe('sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
        ->and($reference->canonical())->toBe('ghcr.io/hardimpactdev/orbit-gateway@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
});

it('rejects invalid gateway image references', function (string $image, string $message): void {
    expect(fn () => GatewayImageReference::fromString($image))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty' => ['', 'Gateway image reference cannot be empty.'],
    'floating latest' => ['ghcr.io/hardimpactdev/orbit-gateway', 'must include a tag or digest'],
    'blank tag' => ['ghcr.io/hardimpactdev/orbit-gateway:', 'must include a non-empty tag'],
    'invalid digest' => ['ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:not-a-digest', 'must use a sha256 digest'],
    'duplicate digest separator' => ['ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'must contain at most one digest separator'],
    'whitespace' => ['ghcr.io/hardimpactdev/orbit gateway:1.2.3', 'cannot contain whitespace'],
]);
