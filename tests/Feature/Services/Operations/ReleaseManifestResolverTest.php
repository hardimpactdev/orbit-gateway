<?php

declare(strict_types=1);

use App\Models\OperationRun;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\ReleaseManifestResolver;
use App\Services\Operations\UpdatePlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('orbit.updates.release_manifest_url', 'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json');
});

it('downloads validates and exposes a release manifest from the configured GitHub release asset', function (): void {
    Http::fake([
        'github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json' => Http::response(releaseManifestResolverFixture(), 200),
    ]);

    $manifest = app(ReleaseManifestResolver::class)->resolve();

    expect($manifest->schemaVersion)->toBe(1)
        ->and($manifest->version)->toBe('1.2.3')
        ->and($manifest->source)->toBe('github-release')
        ->and($manifest->gatewayImage)->toBe('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($manifest->cliArtifacts['linux-amd64']['sha256'])->toBe(str_repeat('b', 64))
        ->and($manifest->roleImages['orbit-websocket'])->toBe('hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd')
        ->and($manifest->snapshot())->toBe(releaseManifestResolverFixture());
});

it('reads validates and exposes a release manifest from a file url', function (): void {
    $root = sys_get_temp_dir().'/orbit-release-manifest-resolver-'.bin2hex(random_bytes(6));
    $path = "{$root}/orbit-release-manifest.json";

    mkdir($root, 0700, true);
    file_put_contents($path, json_encode(releaseManifestResolverFixture(), JSON_THROW_ON_ERROR));

    config()->set('orbit.updates.release_manifest_url', "file://{$path}");

    try {
        $manifest = app(ReleaseManifestResolver::class)->resolve();

        expect($manifest->gatewayImage)->toBe('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    } finally {
        File::deleteDirectory($root);
    }
});

it('rejects malformed manifest json', function (): void {
    Http::fake([
        'github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json' => Http::response('{nope', 200),
    ]);

    expect(fn () => app(ReleaseManifestResolver::class)->resolve())
        ->toThrow(RuntimeException::class, 'Release manifest response must be a JSON object.');
});

it('rejects a gateway image that is missing a digest', function (): void {
    Http::fake([
        'github.com/*' => Http::response(releaseManifestResolverFixture([
            'images' => [
                'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3',
            ],
        ]), 200),
    ]);

    expect(fn () => app(ReleaseManifestResolver::class)->resolve())
        ->toThrow(RuntimeException::class, 'gateway image must be digest-pinned');
});

it('rejects manifests without cli artifacts', function (): void {
    Http::fake([
        'github.com/*' => Http::response(releaseManifestResolverFixture([
            'cli_artifacts' => [],
        ]), 200),
    ]);

    expect(fn () => app(ReleaseManifestResolver::class)->resolve())
        ->toThrow(RuntimeException::class, 'CLI artifacts cannot be empty');
});

it('rejects unsupported manifest sources', function (): void {
    Http::fake([
        'github.com/*' => Http::response(releaseManifestResolverFixture([
            'source' => 'static-url',
        ]), 200),
    ]);

    expect(fn () => app(ReleaseManifestResolver::class)->resolve())
        ->toThrow(RuntimeException::class, 'source [static-url] is not supported');
});

it('rejects unsupported manifest schema versions', function (): void {
    Http::fake([
        'github.com/*' => Http::response(releaseManifestResolverFixture([
            'schema_version' => 2,
        ]), 200),
    ]);

    expect(fn () => app(ReleaseManifestResolver::class)->resolve())
        ->toThrow(RuntimeException::class, 'schema version [2] is not supported');
});

it('feeds the manifest resolver snapshot into the immutable update plan builder', function (): void {
    Http::fake([
        'github.com/*' => Http::response(releaseManifestResolverFixture([
            'version' => '2.0.0',
            'images' => [
                'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway:2.0.0@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            ],
        ]), 200),
    ]);

    $request = Request::create('/api/update/all/start', 'POST');
    $snapshot = app(UpdatePlanBuilder::class)->fromRequest(releaseManifestResolverRun(), $request);

    expect($snapshot->targetVersion)->toBe('2.0.0')
        ->and($snapshot->gatewayImage)->toBe('ghcr.io/hardimpactdev/orbit-gateway:2.0.0@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee')
        ->and($snapshot->manifestSource)->toBe('github-release')
        ->and($snapshot->manifestVersion)->toBe('2.0.0')
        ->and($snapshot->manifestSnapshot['schema_version'])->toBe(1)
        ->and($snapshot->cliArtifacts['linux-amd64']['url'])->toBe('https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-amd64');
});

function releaseManifestResolverRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function releaseManifestResolverFixture(array $overrides = []): array
{
    return array_replace([
        'schema_version' => 1,
        'version' => '1.2.3',
        'source' => 'github-release',
        'images' => [
            'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-amd64',
                'sha256' => str_repeat('b', 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        ],
    ], $overrides);
}
