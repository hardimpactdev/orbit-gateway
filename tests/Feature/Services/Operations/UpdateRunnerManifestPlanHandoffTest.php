<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Operations\FleetUpdateVerifier;
use App\Services\Operations\GatewayServiceUpdater;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdatePlanBuilder;
use App\Services\Operations\UpdateRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('hands the manifest backed plan to gateway and workload update phases exactly once', function (): void {
    $manifest = updateRunnerManifestPlanHandoffManifest();
    $gatewayUpdater = new UpdateRunnerManifestPlanGatewayUpdater;
    $remoteShell = new UpdateRunnerManifestPlanShell;

    Http::fake([
        'github.com/*' => Http::response($manifest, 200),
    ]);
    app()->instance(GatewayServiceUpdater::class, $gatewayUpdater);
    app()->instance(RemoteShell::class, $remoteShell);
    app()->instance(FleetUpdateVerifier::class, new UpdateRunnerManifestPlanNoopVerifier);

    $run = updateRunnerManifestPlanRun();
    $snapshot = app(UpdatePlanBuilder::class)->fromRequest($run, Request::create('/api/update/all/start', 'POST'));
    app(OperationUpdatePlanStore::class)->create($run, $snapshot);

    Node::factory()->appDev()->create([
        'name' => 'app-dev-1',
        'platform' => 'linux-amd64',
        'orbit_path' => '/opt/orbit-app-dev',
    ]);

    app(UpdateRunner::class)->run($run->id);

    $updateScripts = array_values(array_filter(
        $remoteShell->calls,
        fn (array $call): bool => $call['script'] !== 'orbit --version' && ! str_contains($call['script'], 'doctor'),
    ));

    expect($gatewayUpdater->gatewayImages)->toBe([
        'ghcr.io/hardimpactdev/orbit-gateway:2.1.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
    ])
        ->and($gatewayUpdater->manifestSnapshots)->toBe([$manifest])
        ->and($updateScripts)->toHaveCount(1)
        ->and($updateScripts[0]['script'])
        ->toContain('https://github.com/hardimpactdev/orbit/releases/download/v2.1.0/orbit-linux-amd64')
        ->toContain(str_repeat('e', 64))
        ->toContain("docker pull 'caddy:2.9-alpine'");

    Http::assertSentCount(1);
});

function updateRunnerManifestPlanRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

/**
 * @return array<string, mixed>
 */
function updateRunnerManifestPlanHandoffManifest(): array
{
    return [
        'schema_version' => 1,
        'version' => '2.1.0',
        'source' => 'github-release',
        'images' => [
            'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway:2.1.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.1.0/orbit-linux-amd64',
                'sha256' => str_repeat('e', 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2.9-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:2.1.0@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        ],
    ];
}

class UpdateRunnerManifestPlanGatewayUpdater extends GatewayServiceUpdater
{
    /**
     * @var list<string>
     */
    public array $gatewayImages = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $manifestSnapshots = [];

    #[Override]
    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->gatewayImages[] = $plan->gateway_image;
        $this->manifestSnapshots[] = $plan->manifest_snapshot;
    }
}

final class UpdateRunnerManifestPlanNoopVerifier extends FleetUpdateVerifier
{
    public function __construct() {}

    #[Override]
    public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }
}

final class UpdateRunnerManifestPlanShell implements RemoteShell
{
    /**
     * @var list<array{node: string, script: string, options: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node->name,
            'script' => $script,
            'options' => $options,
        ];

        return new RemoteShellResult(exitCode: 0, stdout: "ok\n", stderr: '', durationMs: 1);
    }
}
