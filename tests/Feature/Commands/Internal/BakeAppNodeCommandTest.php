<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Data\Security\PinnedHostKey;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

describe('orbit:internal:bake-app-node', function (): void {
    beforeEach(function (): void {
        $this->hostKeyPinner = new class
        {
            /** @var list<array{host: string, expected: ?string}> */
            public array $calls = [];

            public function pin(string $host, ?string $expectedFingerprint = null): PinnedHostKey
            {
                $this->calls[] = ['host' => $host, 'expected' => $expectedFingerprint];

                return new PinnedHostKey(
                    host: $host,
                    type: 'ssh-ed25519',
                    publicKey: 'AAAAC3NzaC1lZDI1NTE5AAAAIBakeAppNodeHostKey',
                    fingerprint: 'SHA256:bake-app-node-host-key',
                    pinMode: 'tofu',
                );
            }
        };

        app()->instance(SshHostKeyPinner::class, $this->hostKeyPinner);
        app()->instance(RemoteShell::class, new BakeAppNodeRemoteShell);
        bindDevelopmentDnsMappingTestDoubles('bake-app-node-dns');
    });

    afterEach(function (): void {
        File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
    });

    it('writes an app node row with the same shape as node:new produces', function (): void {
        $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-dev-1',
            '--role' => 'app-dev',
            '--host' => '10.6.0.4',
            '--wireguard-address' => '10.6.0.4',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
            '--tld' => 'test',
        ])->assertSuccessful();

        $node = Node::query()->where('name', 'app-dev-1')->firstOrFail();
        $shell = app(RemoteShell::class);

        assert($shell instanceof BakeAppNodeRemoteShell);

        expect($node->getAttributes())->not->toHaveKeys(['role', 'environment'])
            ->and($node->host)->toBe('10.6.0.4')
            ->and($node->wireguard_address)->toBe('10.6.0.4')
            ->and($node->gateway_endpoint)->toBe('10.6.0.2')
            ->and($node->user)->toBe('orbit')
            ->and($node->orbit_path)->toBe('/home/orbit/orbit')
            ->and($node->tld)->toBe('test')
            ->and($node->status)->toBe(NodeStatus::Active)
            ->and($node->host_key_type)->toBe('ssh-ed25519')
            ->and($node->host_key_public)->toBe('AAAAC3NzaC1lZDI1NTE5AAAAIBakeAppNodeHostKey')
            ->and($node->host_key_fingerprint)->toBe('SHA256:bake-app-node-host-key')
            ->and($node->host_key_pin_mode)->toBe('tofu')
            ->and($node->host_key_pinned_at)->not->toBeNull()
            ->and($this->hostKeyPinner->calls)->toBe([
                ['host' => '10.6.0.4', 'expected' => null],
            ])
            ->and(NodeTool::query()->where('node_id', $node->id)->pluck('name')->sort()->values()->all())->toBe([
                'caddy',
                'composer',
                'gh',
                'laravel-installer',
                'php-cli',
            ])
            ->and(File::exists(app(DevelopmentDnsMappingEnactor::class)->configDir().'/test.conf'))->toBeTrue()
            ->and($shell->probeScripts())->toHaveCount(2)
            ->and($shell->repairScripts())->toHaveCount(5);
    });

    it('uses setup convergence when baking app-dev role intent', function (): void {
        $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-dev-1',
            '--role' => 'app-dev',
            '--host' => 'dev',
            '--wireguard-address' => '10.6.0.4',
            '--gateway-endpoint' => 'gateway',
            '--user' => 'orbit',
            '--tld' => 'test',
        ])->assertSuccessful();

        $node = Node::query()->where('name', 'app-dev-1')->firstOrFail();
        $shell = app(RemoteShell::class);

        assert($shell instanceof BakeAppNodeRemoteShell);

        $scripts = implode("\n", $shell->scripts);

        expect($scripts)->not->toContain('doctor --restore')
            ->and($scripts)->not->toContain(' orbit doctor ')
            ->and(NodeTool::query()->where('node_id', $node->id)->pluck('expected_state', 'name')->all())->toMatchArray([
                'caddy' => 'installed',
                'composer' => 'installed',
                'gh' => 'installed',
                'laravel-installer' => 'installed',
                'php-cli' => 'installed',
            ]);
    });

    it('emits app-dev bake phase timings', function (): void {
        $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-dev-1',
            '--role' => 'app-dev',
            '--host' => 'dev',
            '--wireguard-address' => '10.6.0.4',
            '--gateway-endpoint' => 'gateway',
            '--user' => 'orbit',
            '--tld' => 'test',
        ])
            ->expectsOutputToContain('__orbit_bake_timing dev host-key')
            ->expectsOutputToContain('__orbit_bake_timing dev registry')
            ->expectsOutputToContain('__orbit_bake_timing dev role-assignment')
            ->expectsOutputToContain('__orbit_bake_timing dev setup-node')
            ->expectsOutputToContain('__orbit_bake_timing dev setup-tool')
            ->expectsOutputToContain('__orbit_bake_timing dev setup-converge')
            ->assertSuccessful();
    });

    it('writes the matching active composable role assignment', function (): void {
        $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-dev-1',
            '--role' => 'app-dev',
            '--host' => 'dev',
            '--wireguard-address' => '10.6.0.4',
            '--gateway-endpoint' => 'gateway',
            '--user' => 'orbit',
            '--tld' => 'test',
        ])->assertSuccessful();

        $node = Node::query()->where('name', 'app-dev-1')->firstOrFail();
        $assignment = NodeRoleAssignment::query()
            ->where('node_id', $node->id)
            ->where('role', NodeRoleName::AppDevelopment->value)
            ->first();

        expect($assignment)->not->toBeNull()
            ->and($assignment?->status)->toBe(NodeRoleStatus::Active)
            ->and($assignment?->settings)->toBe(['tld' => 'test']);
    });

    it('is idempotent across repeated runs', function (): void {
        $args = [
            'name' => 'app-prod-1',
            '--role' => 'app-prod',
            '--host' => '10.6.0.5',
            '--wireguard-address' => '10.6.0.5',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
        ];

        $this->artisan('orbit:internal:bake-app-node', $args)->assertSuccessful();
        $this->artisan('orbit:internal:bake-app-node', $args)->assertSuccessful();

        $node = Node::query()->where('name', 'app-prod-1')->firstOrFail();

        expect(Node::query()->where('name', 'app-prod-1')->count())->toBe(1)
            ->and($node->tld)->toBeNull()
            ->and(NodeRoleAssignment::query()
                ->where('node_id', $node->id)
                ->where('role', NodeRoleName::AppProduction->value)
                ->count())->toBe(1);
    });

    it('stores the selected ingress node for production placement', function (): void {
        $edge = Node::factory()->create([
            'name' => 'edge-1',
            'host' => '10.6.0.7',
            'wireguard_address' => '10.6.0.7',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $edge->id,
            'role' => NodeRoleName::Ingress->value,
            'status' => NodeRoleStatus::Active->value,
        ]);

        $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-prod-1',
            '--role' => 'app-prod',
            '--host' => '10.6.0.5',
            '--wireguard-address' => '10.6.0.5',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
            '--ingress-node' => 'edge-1',
        ])->assertSuccessful();

        $node = Node::query()->where('name', 'app-prod-1')->firstOrFail();
        $assignment = NodeRoleAssignment::query()
            ->where('node_id', $node->id)
            ->where('role', NodeRoleName::AppProduction->value)
            ->first();

        expect($assignment)->not->toBeNull()
            ->and($assignment?->settings)->toBe(['ingress_node_id' => $edge->id]);
    });

    it('preserves colocated ingress when production placement selects the same node', function (): void {
        $appProd = Node::factory()->create([
            'name' => 'app-prod-1',
            'host' => '10.6.0.5',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $appProd->id,
            'role' => NodeRoleName::Ingress->value,
            'status' => NodeRoleStatus::Active->value,
        ]);

        $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-prod-1',
            '--role' => 'app-prod',
            '--host' => '10.6.0.5',
            '--wireguard-address' => '10.6.0.5',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
            '--ingress-node' => 'app-prod-1',
        ])->assertSuccessful();

        $assignments = NodeRoleAssignment::query()
            ->where('node_id', $appProd->id)
            ->whereIn('role', [NodeRoleName::AppProduction->value, NodeRoleName::Ingress->value])
            ->orderBy('role')
            ->get()
            ->map(fn (NodeRoleAssignment $assignment): array => [
                'role' => $assignment->role,
                'status' => $assignment->status->value,
                'settings' => $assignment->settings,
            ])
            ->all();

        expect($assignments)->toBe([
            [
                'role' => NodeRoleName::AppProduction->value,
                'status' => NodeRoleStatus::Active->value,
                'settings' => ['ingress_node_id' => $appProd->id],
            ],
            [
                'role' => NodeRoleName::Ingress->value,
                'status' => NodeRoleStatus::Active->value,
                'settings' => [],
            ],
        ]);
    });

    it('removes stale colocated ingress from production app nodes when dedicated ingress is selected', function (): void {
        $edge = Node::factory()->create([
            'name' => 'edge-1',
            'host' => '10.6.0.7',
            'wireguard_address' => '10.6.0.7',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $edge->id,
            'role' => NodeRoleName::Ingress->value,
            'status' => NodeRoleStatus::Active->value,
        ]);

        $appProd = Node::factory()->create([
            'name' => 'app-prod-1',
            'host' => '10.6.0.5',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $appProd->id,
            'role' => NodeRoleName::Ingress->value,
            'status' => NodeRoleStatus::Active->value,
        ]);

        $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-prod-1',
            '--role' => 'app-prod',
            '--host' => '10.6.0.5',
            '--wireguard-address' => '10.6.0.5',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
            '--ingress-node' => 'edge-1',
        ])->assertSuccessful();

        $assignment = NodeRoleAssignment::query()
            ->where('node_id', $appProd->id)
            ->where('role', NodeRoleName::AppProduction->value)
            ->first();

        expect($assignment)->not->toBeNull()
            ->and($assignment?->settings)->toBe(['ingress_node_id' => $edge->id])
            ->and(NodeRoleAssignment::query()
                ->where('node_id', $appProd->id)
                ->where('role', NodeRoleName::Ingress->value)
                ->exists())->toBeFalse();
    });

    it('requires the selected ingress node to have an active ingress assignment', function (): void {
        Node::factory()->create([
            'name' => 'edge-1',
            'host' => '10.6.0.7',
            'wireguard_address' => '10.6.0.7',
        ]);

        expect(fn () => $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-prod-1',
            '--role' => 'app-prod',
            '--host' => '10.6.0.5',
            '--wireguard-address' => '10.6.0.5',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
            '--ingress-node' => 'edge-1',
        ])->run())->toThrow(RuntimeException::class, 'Active ingress node [edge-1] was not found.');
    });
});

final class BakeAppNodeRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var array<string, bool> */
    private array $installed = [
        'caddy' => false,
        'php-cli' => false,
        'composer' => false,
        'gh' => false,
        'laravel-installer' => false,
    ];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        if ($this->isProbeScript($script)) {
            return $this->probeResult($node, $options);
        }

        if (($tool = $this->toolForRepairScript($script)) !== null) {
            $this->installed[$tool] = true;
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }

    /**
     * @return list<string>
     */
    public function repairScripts(): array
    {
        return array_values(array_filter(
            $this->scripts,
            fn (string $script): bool => ! $this->isProbeScript($script),
        ));
    }

    /**
     * @return list<string>
     */
    public function probeScripts(): array
    {
        return array_values(array_filter(
            $this->scripts,
            $this->isProbeScript(...),
        ));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function probeResult(Node $node, array $options): RemoteShellResult
    {
        $payload = json_decode((string) ($options['input'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);

        if (is_array($payload['tools'] ?? null)) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: $this->batchProbeOutput($node, $payload['tools']),
                stderr: '',
                durationMs: 1,
            );
        }

        $binary = is_string($payload['binary'] ?? null) ? $payload['binary'] : '';
        $container = is_string($payload['container'] ?? null) ? $payload['container'] : '';

        if ($container === 'orbit-caddy') {
            $hash = OrbitCaddyContainer::forPrivateNode((string) $node->wireguard_address)->specHash();

            return $this->installed['caddy']
                ? new RemoteShellResult(exitCode: 0, stdout: "/usr/bin/docker\tDocker version 27.0.0\trunning\t\t\t\t\t1\trunning\t{$hash}\n", stderr: '', durationMs: 1)
                : new RemoteShellResult(exitCode: 0, stdout: "/usr/bin/docker\tDocker version 27.0.0\tmissing\t\t\t\t\t0\tmissing\t\n", stderr: '', durationMs: 1);
        }

        return match ($binary) {
            '/opt/orbit/php/8.5/bin/php' => $this->installedProbe('php-cli', "/opt/orbit/php/8.5/bin/php\t8.5.6\n"),
            '/usr/local/bin/composer' => $this->installedProbe('composer', "/usr/local/bin/composer\tComposer version 2.9.0\n"),
            'gh' => $this->installedProbe('gh', "/usr/bin/gh\tgh version 2.60.0\n"),
            '/usr/local/bin/laravel' => $this->installedProbe('laravel-installer', "/usr/local/bin/laravel\tLaravel Installer 5.0.0\n"),
            default => new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        };
    }

    /**
     * @param  array<string, mixed>  $tools
     */
    private function batchProbeOutput(Node $node, array $tools): string
    {
        $lines = [];

        foreach ($tools as $name => $tool) {
            if (! is_string($name) || ! is_array($tool)) {
                continue;
            }

            $lines[] = json_encode($this->batchProbePayload($node, $name, $tool), JSON_THROW_ON_ERROR);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function batchProbePayload(Node $node, string $name, array $tool): array
    {
        if ($name === 'caddy') {
            $hash = OrbitCaddyContainer::forPrivateNode((string) $node->wireguard_address)->specHash();

            return [
                'name' => 'caddy',
                'installed' => true,
                'path' => '/usr/bin/docker',
                'version' => 'Docker version 27.0.0',
                'state' => $this->installed['caddy'] ? 'running' : 'missing',
                'config_exists' => null,
                'config_hash' => null,
                'secret_exists' => null,
                'secret_hash' => null,
                'container_exists' => $this->installed['caddy'],
                'container_state' => $this->installed['caddy'] ? 'running' : 'missing',
                'container_spec_hash' => $this->installed['caddy'] ? $hash : null,
            ];
        }

        $installedPayloads = [
            'composer' => ['/usr/local/bin/composer', 'Composer version 2.9.0'],
            'gh' => ['/usr/bin/gh', 'gh version 2.60.0'],
            'laravel-installer' => ['/usr/local/bin/laravel', 'Laravel Installer 5.0.0'],
            'php-cli' => ['/opt/orbit/php/8.5/bin/php', '8.5.6'],
        ];
        [$path, $version] = $installedPayloads[$name] ?? [is_string($tool['binary'] ?? null) ? $tool['binary'] : null, null];
        $installed = $this->installed[$name] ?? false;

        return [
            'name' => $name,
            'installed' => $installed,
            'path' => $installed ? $path : null,
            'version' => $installed ? $version : null,
            'state' => $installed ? 'unknown' : null,
            'config_exists' => null,
            'config_hash' => null,
            'secret_exists' => null,
            'secret_hash' => null,
            'container_exists' => null,
            'container_state' => null,
            'container_spec_hash' => null,
        ];
    }

    private function installedProbe(string $tool, string $stdout): RemoteShellResult
    {
        return $this->installed[$tool]
            ? new RemoteShellResult(exitCode: 0, stdout: $stdout, stderr: '', durationMs: 1)
            : new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1);
    }

    private function isProbeScript(string $script): bool
    {
        return str_contains($script, '$payload = json_decode(stream_get_contents(STDIN), true);');
    }

    private function toolForRepairScript(string $script): ?string
    {
        return match (true) {
            str_contains($script, 'orbit.caddy.spec_hash') => 'caddy',
            str_contains($script, '# orbit install php-cli') => 'php-cli',
            str_contains($script, '# orbit install composer') => 'composer',
            str_contains($script, '# orbit install gh') => 'gh',
            str_contains($script, '# orbit install laravel-installer') => 'laravel-installer',
            default => null,
        };
    }
}
