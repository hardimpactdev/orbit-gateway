<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RuntimeBackend\GatewayRuntimeBackendProbe;

it('reports available when the orbit-gateway container exists and is running', function (): void {
    $node = new Node(['name' => 'gateway-1']);
    $remoteShell = new GatewayRuntimeProbeRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: "available\ttrue\ttrue\n", stderr: '', durationMs: 1),
    );

    $result = (new GatewayRuntimeBackendProbe($remoteShell))->check($node);

    expect($result->runtimeStatus)->toBe('available')
        ->and($result->containerExists)->toBeTrue()
        ->and($result->containerRunning)->toBeTrue()
        ->and($result->exitCode)->toBe(0)
        ->and($result->output)->toBe("available\ttrue\ttrue")
        ->and($remoteShell->scripts)->toHaveCount(1)
        ->and($remoteShell->options[0]['timeout'])->toBe(15);

    expect($remoteShell->scripts[0])
        ->toContain('orbit-gateway-container-probe:container-inspect')
        ->toContain("container='orbit-gateway'")
        ->toContain('docker container inspect')
        ->toContain('docker info')
        ->toContain('printf')
        ->not->toContain('supervisorctl');
});

it('reports no_docker when Docker CLI is missing', function (): void {
    $node = new Node(['name' => 'gateway-1']);
    $remoteShell = new GatewayRuntimeProbeRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: "no_docker\tfalse\tfalse\n", stderr: '', durationMs: 1),
    );

    $result = (new GatewayRuntimeBackendProbe($remoteShell))->check($node);

    expect($result->runtimeStatus)->toBe('no_docker')
        ->and($result->containerExists)->toBeFalse()
        ->and($result->containerRunning)->toBeFalse();
});

it('reports daemon_unavailable when Docker daemon is unreachable', function (): void {
    $node = new Node(['name' => 'gateway-1']);
    $remoteShell = new GatewayRuntimeProbeRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: "daemon_unavailable\tfalse\tfalse\n", stderr: '', durationMs: 1),
    );

    $result = (new GatewayRuntimeBackendProbe($remoteShell))->check($node);

    expect($result->runtimeStatus)->toBe('daemon_unavailable')
        ->and($result->containerExists)->toBeFalse()
        ->and($result->containerRunning)->toBeFalse();
});

it('reports available with exists=false when the container is missing', function (): void {
    $node = new Node(['name' => 'gateway-1']);
    $remoteShell = new GatewayRuntimeProbeRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: "available\tfalse\tfalse\n", stderr: '', durationMs: 1),
    );

    $result = (new GatewayRuntimeBackendProbe($remoteShell))->check($node);

    expect($result->runtimeStatus)->toBe('available')
        ->and($result->containerExists)->toBeFalse()
        ->and($result->containerRunning)->toBeFalse();
});

it('reports available with running=false when the container is stopped', function (): void {
    $node = new Node(['name' => 'gateway-1']);
    $remoteShell = new GatewayRuntimeProbeRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: "available\ttrue\tfalse\n", stderr: '', durationMs: 1),
    );

    $result = (new GatewayRuntimeBackendProbe($remoteShell))->check($node);

    expect($result->runtimeStatus)->toBe('available')
        ->and($result->containerExists)->toBeTrue()
        ->and($result->containerRunning)->toBeFalse();
});

it('produces distinct drift entries per failure mode', function (): void {
    $node = new Node(['name' => 'gateway-1']);
    $probe = new GatewayRuntimeBackendProbe(
        new GatewayRuntimeProbeRecordingRemoteShell(
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ),
    );

    $noDocker = $probe->diff($node, new ProbeSnapshot([
        'orbit-gateway' => ['runtime_status' => 'no_docker', 'container_exists' => false, 'container_running' => false],
    ]));

    expect($noDocker)->toHaveCount(1)
        ->and($noDocker[0]->key)->toBe('node.docker_runtime_unavailable')
        ->and($noDocker[0]->kind->value)->toBe('divergent');

    $missing = $probe->diff($node, new ProbeSnapshot([
        'orbit-gateway' => ['runtime_status' => 'available', 'container_exists' => false, 'container_running' => false],
    ]));

    expect($missing)->toHaveCount(1)
        ->and($missing[0]->key)->toBe('node.runtime_container_missing')
        ->and($missing[0]->kind->value)->toBe('missing');

    $stopped = $probe->diff($node, new ProbeSnapshot([
        'orbit-gateway' => ['runtime_status' => 'available', 'container_exists' => true, 'container_running' => false],
    ]));

    expect($stopped)->toHaveCount(1)
        ->and($stopped[0]->key)->toBe('node.runtime_container_stopped')
        ->and($stopped[0]->kind->value)->toBe('divergent');
});

final class GatewayRuntimeProbeRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    public function __construct(
        private readonly RemoteShellResult $result,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return $this->result;
    }
}
