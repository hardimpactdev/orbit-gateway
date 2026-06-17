<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Nodes\NodeWireGuardSelfRouteProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('recognizes a Linux local WireGuard self route through loopback', function (): void {
    $node = Node::factory()->make([
        'name' => 'app-1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.4',
    ]);
    $shell = new NodeWireGuardSelfRouteProbeRemoteShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: "local 10.6.0.4 dev lo src 10.6.0.4 uid 1000\n",
            stderr: '',
            durationMs: 1,
        ),
    ]);

    $result = (new NodeWireGuardSelfRouteProbe($shell))->probe($node);

    expect($result['ok'])->toBeTrue()
        ->and($shell->scripts)->toBe(["ip route get '10.6.0.4'"]);
});

it('recognizes an equivalent Linux local WireGuard self route', function (): void {
    $node = Node::factory()->make([
        'name' => 'app-1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.4',
    ]);
    $shell = new NodeWireGuardSelfRouteProbeRemoteShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: "local 10.6.0.4 dev wg-orbit table local src 10.6.0.4\n",
            stderr: '',
            durationMs: 1,
        ),
    ]);

    $result = (new NodeWireGuardSelfRouteProbe($shell))->probe($node);

    expect($result['ok'])->toBeTrue();
});

it('inspects unknown node platforms instead of treating retained Linux topologies as unsupported', function (): void {
    $node = Node::factory()->make([
        'name' => 'gateway',
        'platform' => 'unknown',
        'wireguard_address' => '10.6.0.2',
    ]);
    $shell = new NodeWireGuardSelfRouteProbeRemoteShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: "local 10.6.0.2 dev lo src 10.6.0.2 uid 1000\n",
            stderr: '',
            durationMs: 1,
        ),
    ]);

    $result = (new NodeWireGuardSelfRouteProbe($shell))->probe($node);

    expect($result)->toMatchArray([
        'ok' => true,
        'supported' => true,
        'platform' => 'unknown',
    ])
        ->and($shell->scripts)->toBe(["ip route get '10.6.0.2'"]);
});

it('reports Linux WireGuard self route misses without mutating routes', function (): void {
    $node = Node::factory()->make([
        'name' => 'app-1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.4',
    ]);
    $shell = new NodeWireGuardSelfRouteProbeRemoteShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: "10.6.0.4 dev wg-orbit src 10.6.0.2 uid 1000\n",
            stderr: '',
            durationMs: 1,
        ),
    ]);

    $result = (new NodeWireGuardSelfRouteProbe($shell))->probe($node);

    expect($result)->toMatchArray([
        'ok' => false,
        'supported' => true,
        'reason' => 'self_route_missing',
        'message' => 'Linux node does not route its own WireGuard address locally.',
    ])
        ->and($shell->scripts)->toBe(["ip route get '10.6.0.4'"])
        ->and($shell->scripts[0])->not->toContain(' route add ')
        ->and($shell->scripts[0])->not->toContain(' route replace ');
});

it('reports macOS as unsupported without running route commands', function (): void {
    $node = Node::factory()->make([
        'name' => 'operator-1',
        'platform' => 'macos_15-4',
        'wireguard_address' => '10.6.0.9',
    ]);
    $shell = new NodeWireGuardSelfRouteProbeRemoteShell([]);

    $result = (new NodeWireGuardSelfRouteProbe($shell))->probe($node);

    expect($result)->toMatchArray([
        'ok' => false,
        'supported' => false,
        'reason' => 'unsupported_platform',
        'message' => NodeWireGuardSelfRouteProbe::UnsupportedMessage,
    ])
        ->and($shell->scripts)->toBe([]);
});

final class NodeWireGuardSelfRouteProbeRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected remote shell call', 1);
    }
}
