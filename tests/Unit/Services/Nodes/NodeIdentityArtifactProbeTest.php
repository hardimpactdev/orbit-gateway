<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Nodes\NodeIdentityArtifactProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('reads non-secret node identity facts from the selected host', function (): void {
    $gatewayNode = Node::factory()->gateway()->create([
        'name' => 'gateway',
        'orbit_path' => '/home/orbit/orbit',
    ]);
    $node = new Node([
        'name' => 'app-1',
        'orbit_path' => '/home/orbit/orbit',
    ]);

    $remoteShell = new NodeIdentityArtifactProbeRemoteShell(
        new RemoteShellResult(
            exitCode: 0,
            stdout: "interface-public-key\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'name' => 'app-1',
                'role' => 'app-dev',
                'local_role' => 'app-dev',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'wireguard_address' => '10.6.0.8',
                'registry_public_key' => 'registry-public-key',
                'interface_public_key' => 'interface-public-key',
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        ),
    );

    $artifact = (new NodeIdentityArtifactProbe($remoteShell))->read($node);

    expect($artifact->name)->toBe('app-1')
        ->and($artifact->role)->toBe('app-dev')
        ->and($artifact->localRole)->toBe('app-dev')
        ->and($artifact->status)->toBe('active')
        ->and($artifact->platform)->toBe('ubuntu_24-04')
        ->and($artifact->wireguardAddress)->toBe('10.6.0.8')
        ->and($artifact->registryPublicKey)->toBe('registry-public-key')
        ->and($artifact->interfacePublicKey)->toBe('interface-public-key')
        ->and($remoteShell->nodes)->toHaveCount(2)
        ->and($remoteShell->nodes[0])->toBe($node)
        ->and($remoteShell->nodes[1]->is($gatewayNode))->toBeTrue()
        ->and($remoteShell->options)->toBe([[
            'timeout' => 15,
        ], [
            'timeout' => 15,
            'cwd' => '/home/orbit/orbit',
        ]])
        ->and($remoteShell->scripts[0])->toContain('sudo wg show wg-orbit public-key')
        ->and($remoteShell->scripts[0])->not->toContain('php')
        ->and($remoteShell->scripts[0])->not->toContain('apps/gateway/bootstrap/app.php')
        ->and($remoteShell->scripts[0])->not->toContain('WireGuardPeer')
        ->and($remoteShell->scripts[1])->toContain('php apps/gateway/artisan tinker --execute=')
        ->and($remoteShell->scripts[1])->toContain('WireGuardPeer')
        ->and($remoteShell->scripts[1])->toContain('JSON_THROW_ON_ERROR')
        ->and($remoteShell->scripts[1])->not->toContain('php -r')
        ->and($remoteShell->scripts[0])->not->toContain('private_key');
});

it('throws when host identity artifact reading fails', function (): void {
    $node = new Node(['name' => 'app-1']);
    $remoteShell = new NodeIdentityArtifactProbeRemoteShell(new RemoteShellResult(
        exitCode: 1,
        stdout: '',
        stderr: 'missing app',
        durationMs: 1,
    ));

    expect(fn () => (new NodeIdentityArtifactProbe($remoteShell))->read($node))
        ->toThrow(RuntimeException::class, 'Failed to read node WireGuard interface public key: missing app');
});

it('throws when gateway runtime identity artifact lookup fails', function (): void {
    Node::factory()->gateway()->create([
        'name' => 'gateway',
        'orbit_path' => '/home/orbit/orbit',
    ]);
    $node = new Node(['name' => 'app-1']);
    $remoteShell = new NodeIdentityArtifactProbeRemoteShell(
        new RemoteShellResult(
            exitCode: 0,
            stdout: "interface-public-key\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: 'registry unavailable',
            durationMs: 1,
        ),
    );

    expect(fn () => (new NodeIdentityArtifactProbe($remoteShell))->read($node))
        ->toThrow(RuntimeException::class, 'Failed to resolve node identity artifact through gateway runtime: registry unavailable');
});

it('throws when identity artifact output is invalid JSON', function (): void {
    Node::factory()->gateway()->create([
        'name' => 'gateway',
        'orbit_path' => '/home/orbit/orbit',
    ]);
    $node = new Node(['name' => 'app-1']);
    $remoteShell = new NodeIdentityArtifactProbeRemoteShell(
        new RemoteShellResult(
            exitCode: 0,
            stdout: "interface-public-key\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(
            exitCode: 0,
            stdout: 'not-json',
            stderr: '',
            durationMs: 1,
        ),
    );

    expect(fn () => (new NodeIdentityArtifactProbe($remoteShell))->read($node))
        ->toThrow(RuntimeException::class, 'Failed to parse node identity artifact JSON.');
});

final class NodeIdentityArtifactProbeRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<Node>
     */
    public array $nodes = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    /**
     * @var list<RemoteShellResult>
     */
    private array $results;

    public function __construct(
        RemoteShellResult ...$results,
    ) {
        $this->results = $results;
    }

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: 'unexpected remote shell call',
            durationMs: 1,
        );
    }
}
