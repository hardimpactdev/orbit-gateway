<?php

declare(strict_types=1);

use App\Services\Vpn\VpnDnsSwarmManager;
use App\Services\Vpn\VpnDnsSwarmStackRenderer;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('converges dns forwarding inside the vpn Swarm task container', function (): void {
    Process::fake([
        "docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-vpn'" => Process::result(output: "vpn-container-id\n"),
        'docker exec*' => Process::result(),
    ]);

    (new VpnDnsSwarmManager(new VpnDnsSwarmStackRenderer))->convergeDnsForwarding();

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'docker ps -q --filter')
        && str_contains((string) $process->command, 'label=com.docker.swarm.service.name=orbit_orbit-vpn'));
    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "docker exec 'vpn-container-id' sh -lc")
        && str_contains((string) $process->command, 'getent hosts')
        && str_contains((string) $process->command, 'orbit-dns')
        && str_contains((string) $process->command, 'PREROUTING')
        && str_contains((string) $process->command, 'MASQUERADE'));
});

it('fails when the vpn Swarm task container is missing', function (): void {
    Process::fake([
        "docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-vpn'" => Process::result(output: ''),
    ]);

    expect(fn (): mixed => (new VpnDnsSwarmManager(new VpnDnsSwarmStackRenderer))->convergeDnsForwarding())
        ->toThrow(RuntimeException::class, 'orbit_orbit-vpn');
});

it('restarts only the dns Swarm service for config changes', function (): void {
    Process::fake([
        "docker service update --force 'orbit_orbit-dns'" => Process::result(),
    ]);

    (new VpnDnsSwarmManager(new VpnDnsSwarmStackRenderer))->restartDnsService();

    Process::assertRan("docker service update --force 'orbit_orbit-dns'");
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'orbit-vpn')
        || str_contains((string) $process->command, 'wg-easy'));
});

it('restarts dns only when the Swarm dns service exists', function (): void {
    Process::fake([
        "docker service inspect 'orbit_orbit-dns'" => Process::result(),
        "docker service update --force 'orbit_orbit-dns'" => Process::result(),
    ]);

    $restarted = (new VpnDnsSwarmManager(new VpnDnsSwarmStackRenderer))->restartDnsServiceIfPresent();

    expect($restarted)->toBeTrue();

    Process::assertRan("docker service inspect 'orbit_orbit-dns'");
    Process::assertRan("docker service update --force 'orbit_orbit-dns'");
});

it('does not restart dns when the Swarm dns service is absent', function (): void {
    Process::fake([
        "docker service inspect 'orbit_orbit-dns'" => Process::result(exitCode: 1),
    ]);

    $restarted = (new VpnDnsSwarmManager(new VpnDnsSwarmStackRenderer))->restartDnsServiceIfPresent();

    expect($restarted)->toBeFalse();

    Process::assertNotRan("docker service update --force 'orbit_orbit-dns'");
});
