<?php

declare(strict_types=1);

use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdatePlanBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->recorder = app(OperationRunRecorder::class);
    $this->run = operationUpdatePlanRun();
});

it('creates and reads an immutable operation update plan by operation run', function (): void {
    $snapshot = operationUpdatePlanSnapshot();
    $store = app(OperationUpdatePlanStore::class);

    $plan = $store->create($this->run, $snapshot);
    $read = $store->forOperationRun($this->run);

    expect($plan)->toBeInstanceOf(OperationUpdatePlan::class)
        ->and($plan->operation_run_id)->toBe($this->run->id)
        ->and($plan->target_version)->toBe('1.2.3')
        ->and($plan->gateway_image)->toBe('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($read?->id)->toBe($plan->id)
        ->and($read?->toSnapshot()->toArray())->toBe($snapshot->toArray());
});

it('enforces one update plan per operation run', function (): void {
    $store = app(OperationUpdatePlanStore::class);

    $store->create($this->run, operationUpdatePlanSnapshot());

    expect(fn () => $store->create($this->run, operationUpdatePlanSnapshot(targetVersion: '1.2.4')))
        ->toThrow(RuntimeException::class, 'already exists');
});

it('rejects mutation of an existing update plan row', function (): void {
    $plan = app(OperationUpdatePlanStore::class)->create($this->run, operationUpdatePlanSnapshot());

    $plan->target_version = '9.9.9';

    expect(fn () => $plan->save())->toThrow(RuntimeException::class, 'immutable');
});

it('serializes manifest cli artifacts and role image references exactly', function (): void {
    $snapshot = operationUpdatePlanSnapshot(
        cliArtifacts: [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-amd64',
                'sha256' => str_repeat('b', 64),
            ],
            'darwin-arm64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-darwin-arm64',
                'sha256' => str_repeat('c', 64),
            ],
        ],
        roleImages: [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        ],
    );

    $plan = app(OperationUpdatePlanStore::class)->create($this->run, $snapshot);

    expect($plan->manifest_snapshot)->toBe($snapshot->manifestSnapshot)
        ->and($plan->cli_artifacts)->toBe($snapshot->cliArtifacts)
        ->and($plan->role_images)->toBe($snapshot->roleImages)
        ->and($plan->toSnapshot()->cliArtifacts)->toBe($snapshot->cliArtifacts)
        ->and($plan->toSnapshot()->roleImages)->toBe($snapshot->roleImages);
});

it('builds a digest-pinned update plan snapshot from request manifest data', function (): void {
    $request = Request::create('/api/update/all', 'POST', [
        'target_version' => '1.2.3',
        'manifest_source' => 'github-release',
        'manifest_version' => '1.2.3',
        'manifest' => operationUpdateReleaseManifest(),
    ]);

    $snapshot = app(UpdatePlanBuilder::class)->fromRequest($this->run, $request);

    expect($snapshot)->toBeInstanceOf(OperationUpdatePlanSnapshot::class)
        ->and($snapshot->targetVersion)->toBe('1.2.3')
        ->and($snapshot->gatewayImage)->toBe('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($snapshot->manifestSource)->toBe('github-release')
        ->and($snapshot->manifestVersion)->toBe('1.2.3')
        ->and($snapshot->cliArtifacts['linux-amd64']['sha256'])->toBe(str_repeat('b', 64))
        ->and($snapshot->roleImages['orbit-caddy'])->toBe('caddy:2-alpine');
});

it('rejects raw request gateway image overrides unless explicitly allowed', function (): void {
    config()->set('orbit.updates.allow_request_image_override', false);

    $request = Request::create('/api/update/all', 'POST', [
        'target_version' => '1.2.3',
        'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'manifest' => operationUpdateReleaseManifest(),
    ]);

    expect(fn () => app(UpdatePlanBuilder::class)->fromRequest($this->run, $request))
        ->toThrow(RuntimeException::class, 'gateway image override is disabled');
});

it('allows configured local testing gateway image overrides when digest pinned', function (): void {
    config()->set('orbit.updates.allow_request_image_override', true);

    $request = Request::create('/api/update/all', 'POST', [
        'target_version' => '1.2.3',
        'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:testing@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
        'manifest' => operationUpdateReleaseManifest(),
    ]);

    $snapshot = app(UpdatePlanBuilder::class)->fromRequest($this->run, $request);

    expect($snapshot->gatewayImage)->toBe('ghcr.io/hardimpactdev/orbit-gateway:testing@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
});

it('rejects gateway images that are not digest pinned', function (): void {
    $manifest = operationUpdateReleaseManifest([
        'images' => [
            'gateway' => 'orbit-gateway:current',
        ],
    ]);

    $request = Request::create('/api/update/all', 'POST', [
        'target_version' => '1.2.3',
        'manifest' => $manifest,
    ]);

    expect(fn () => app(UpdatePlanBuilder::class)->fromRequest($this->run, $request))
        ->toThrow(RuntimeException::class, 'digest-pinned');
});

function operationUpdatePlanRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

/**
 * @param  array<string, mixed>  $manifestOverrides
 */
function operationUpdatePlanSnapshot(
    string $targetVersion = '1.2.3',
    string $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    string $manifestSource = 'github-release',
    string $manifestVersion = '1.2.3',
    array $manifestOverrides = [],
    array $cliArtifacts = [],
    array $roleImages = [],
): OperationUpdatePlanSnapshot {
    $manifestSnapshot = operationUpdateReleaseManifest($manifestOverrides);

    return new OperationUpdatePlanSnapshot(
        targetVersion: $targetVersion,
        gatewayImage: $gatewayImage,
        manifestSource: $manifestSource,
        manifestVersion: $manifestVersion,
        manifestSnapshot: $manifestSnapshot,
        cliArtifacts: $cliArtifacts === [] ? $manifestSnapshot['cli_artifacts'] : $cliArtifacts,
        roleImages: $roleImages === [] ? $manifestSnapshot['role_images'] : $roleImages,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function operationUpdateReleaseManifest(array $overrides = []): array
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
