<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Nodes\NodeIdentityArtifactProbe;

it('reads non-secret node identity facts from the selected host', function (): void {
    $node = new Node([
        'name' => 'app-1',
        'orbit_path' => '/home/orbit/orbit',
    ]);

    $remoteShell = new NodeIdentityArtifactProbeRemoteShell(new RemoteShellResult(
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
    ));

    $artifact = (new NodeIdentityArtifactProbe($remoteShell))->read($node);

    expect($artifact->name)->toBe('app-1')
        ->and($artifact->role)->toBe('app-dev')
        ->and($artifact->localRole)->toBe('app-dev')
        ->and($artifact->status)->toBe('active')
        ->and($artifact->platform)->toBe('ubuntu_24-04')
        ->and($artifact->wireguardAddress)->toBe('10.6.0.8')
        ->and($artifact->registryPublicKey)->toBe('registry-public-key')
        ->and($artifact->interfacePublicKey)->toBe('interface-public-key')
        ->and($remoteShell->options)->toBe([[
            'timeout' => 15,
            'cwd' => '/home/orbit/orbit',
        ]])
        ->and($remoteShell->scripts[0])->toContain('sudo wg show wg-orbit public-key')
        ->and($remoteShell->scripts[0])->toContain('JSON_THROW_ON_ERROR')
        ->and($remoteShell->scripts[0])->not->toContain('private_key');
});

it('throws when identity artifact reading fails', function (): void {
    $node = new Node(['name' => 'app-1']);
    $remoteShell = new NodeIdentityArtifactProbeRemoteShell(new RemoteShellResult(
        exitCode: 1,
        stdout: '',
        stderr: 'missing app',
        durationMs: 1,
    ));

    expect(fn () => (new NodeIdentityArtifactProbe($remoteShell))->read($node))
        ->toThrow(RuntimeException::class, 'Failed to read node identity artifact: missing app');
});

it('throws when identity artifact output is invalid JSON', function (): void {
    $node = new Node(['name' => 'app-1']);
    $remoteShell = new NodeIdentityArtifactProbeRemoteShell(new RemoteShellResult(
        exitCode: 0,
        stdout: 'not-json',
        stderr: '',
        durationMs: 1,
    ));

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
