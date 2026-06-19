<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Tools\ToolsProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function toolProbeIssue(array $drift, string $key): mixed
{
    return collect($drift)->first(fn ($entry): bool => $entry->key === $key);
}

function createToolsProbeAppHostNode(array $attributes = []): Node
{
    return createTestAppHostNode([
        'status' => 'active',
        ...$attributes,
    ]);
}

function createToolsProbeAgentNode(): Node
{
    $node = Node::factory()->create([
        'status' => 'active',
        'tld' => 'agent',
    ]);
    $node->roleAssignments()->create([
        'role' => 'agent',
        'status' => 'active',
        'settings' => ['tld' => 'agent'],
    ]);

    return $node;
}

/**
 * @return array{target: array{type: string, value: string}, upstream: string, owner_name: string}
 */
function toolsProbeAgentRouteConfig(string $tool): array
{
    $upstream = 'http://host.docker.internal:8080';

    return [
        'target' => ['type' => 'upstream', 'value' => $upstream],
        'upstream' => $upstream,
        'owner_name' => $tool,
    ];
}

function toolsProbeAgentRouteSourceHash(Node $node, string $tool): string
{
    return app(ProxyRouteRenderer::class)->sourceHash(new ProxyRoute([
        'node_id' => $node->id,
        'domain' => "{$tool}.agent",
        'kind' => 'proxy',
        'owner_type' => 'tool',
        'config' => toolsProbeAgentRouteConfig($tool),
    ]));
}

function toolsProbeCapabilityStdout(string $path, string $version = '', string $state = 'running'): string
{
    return implode("\t", [$path, $version, $state, '', '', '', '', '', '', ''])."\n";
}

function toolsProbeManagedFileStdout(bool $exists, ?string $hash, ?string $mode): string
{
    return json_encode([
        'exists' => $exists,
        'hash' => $hash,
        'mode' => $mode,
    ], JSON_THROW_ON_ERROR)."\n";
}

describe('ToolsProbe', function (): void {
    it('has key and label', function (): void {
        $probe = new ToolsProbe;

        expect($probe->key())->toBe('tool')
            ->and($probe->label())->toBe('Tools');
    });

    it('detects incomplete tool records', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => '',
            'expected_state' => '',
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('requires active app or gateway nodes', function (): void {
        $node = Node::factory()->create(['status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.node_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('allows managed caddy on ingress nodes', function (): void {
        $node = Node::factory()->ingress()->create(['status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'caddy']);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.node_invalid'))->toBeNull()
            ->and(toolProbeIssue($drift, 'tool.capability_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('allows metrics node exporter on ingress nodes', function (): void {
        $node = Node::factory()->ingress()->create(['status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'node-exporter']);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.node_invalid'))->toBeNull()
            ->and(toolProbeIssue($drift, 'tool.capability_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('does not allow non-caddy managed tools on ingress-only nodes', function (): void {
        $node = Node::factory()->ingress()->create(['status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.node_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('allows provisioning app nodes during managed setup', function (): void {
        $node = createToolsProbeAppHostNode(['status' => NodeStatus::Provisioning]);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]), allowProvisioning: true);

        expect(toolProbeIssue($drift, 'tool.node_invalid'))->toBeNull()
            ->and(toolProbeIssue($drift, 'tool.capability_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('requires known tool catalog definitions', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'not-a-tool']);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.definition_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects missing live capabilities', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 1));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.capability_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('passes when live capability exists', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/local/bin/composer\n"));

        $snapshot = $probe->introspect($tool);

        expect($probe->diff($tool, $snapshot))->toBe([]);
    });

    it('checks absolute binary metadata as an executable path instead of a PATH lookup', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php-cli']);
        $shell = new RecordingToolsProbeRemoteShell(
            exitCode: 1,
            stdout: '',
        );
        $probe = new ToolsProbe($shell);

        $probe->introspect($tool);

        $input = json_decode($shell->input, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($input['binary'])->toBe('/opt/orbit/php/8.5/bin/php')
            ->and($shell->script)->toContain('str_contains($binary')
            ->and($shell->script)->toContain('is_executable($binary)')
            ->and($shell->script)->toContain('command -v');
    });

    it('frankenphp probes approved Docker image inventory for the PHP tool instead of host PHP', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php']);
        $shell = new RecordingToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "dunglas/frankenphp:1-php8.5-bookworm\n",
        );
        $probe = new ToolsProbe($shell);

        $snapshot = $probe->introspect($tool);

        expect($shell->script)->toContain('docker image inspect')
            ->not->toContain('command -v php')
            ->and($shell->input)->toContain('dunglas/frankenphp:1-php8.5-bookworm')
            ->and($snapshot->get('php'))->toMatchArray([
                'installed' => true,
                'version' => '8.5',
                'images' => ['dunglas/frankenphp:1-php8.5-bookworm'],
            ])
            ->and($probe->diff($tool, $snapshot))->toBe([]);
    });

    it('frankenphp does not accept host PHP output as PHP tool capability', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php']);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/usr/bin/php\t8.5.0\n",
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect($snapshot->get('php'))->toMatchArray([
            'installed' => false,
            'images' => [],
        ])
            ->and(toolProbeIssue($drift, 'tool.capability_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects version drift when the catalog tracks versions', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_version' => '2.8',
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/local/bin/composer\tComposer version 2.7.0\n"));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.version_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.version_mismatch')?->detail)->toMatchArray([
                'expected_version' => '2.8',
                'observed_version' => 'Composer version 2.7.0',
            ]);
    });

    it('does not emit tool.lifecycle_state_mismatch when a service-backed tool is stopped', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_state' => 'installed',
        ]);
        // Probe reports binary present but runtime state stopped — must produce no tool issue code
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/local/bin/composer\tComposer version 2.8.0\tstopped\n"));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        $codes = array_column($drift, null);
        $issueKeys = array_map(fn ($entry) => $entry->key, $drift);

        expect(in_array('tool.lifecycle_state_mismatch', $issueKeys, true))->toBeFalse()
            ->and($probe->diff($tool, $snapshot))->toBe([]);
    });

    it('does not produce any tool issue code when a tool is installed but its backing service is not running', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_state' => 'installed',
        ]);
        // Service down: binary exists, state is stopped — runtime state is process-family fact
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/local/bin/composer\tComposer version 2.8.0\tstopped\n"));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect($drift)->toBe([]);
    });

    it('only accepts installed and absent as valid expected_state values', function (): void {
        $node = createToolsProbeAppHostNode();
        // Deliberately write an illegal expected_state value that the old contract allowed
        $tool = NodeTool::factory()->make([
            'node_id' => $node->id,
            'name' => 'composer',
        ]);
        $tool->expected_state = 'running'; // bypasses factory default to test validation
        $tool->save();

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(collect($drift)->first(fn ($entry) => $entry->key === 'tool.record_incomplete'))->not->toBeNull();
    });

    it('inspects agent IDE server capability without probing process lifecycle', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'expected_state' => 'installed',
        ]);
        $shell = new RecordingToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/home/orbit/.opencode/bin/opencode\t\tunknown\t\t\t\t\t\t\t\n",
        );
        $probe = new ToolsProbe($shell);

        $snapshot = $probe->introspect($tool);
        $input = json_decode($shell->input, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($input)->not->toHaveKey('supervisor_program')
            ->and($snapshot->get('opencode-server'))->toMatchArray([
                'installed' => true,
                'path' => '/home/orbit/.opencode/bin/opencode',
            ]);
    });

    it('inspects orbit-caddy container state instead of only checking the docker binary', function (): void {
        withE2EEnvironment(['ORBIT_E2E_DOCKER_NETWORK'], [
            'ORBIT_E2E_DOCKER_NETWORK' => 'orbit-e2e-dev-abc123',
        ], function (): void {
            $node = createToolsProbeAppHostNode();
            $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50', OrbitContainerNames::forNodeScope('dev'));
            $tool = NodeTool::factory()->create([
                'node_id' => $node->id,
                'name' => 'caddy',
                'expected_state' => 'installed',
                'config' => ['container' => $container->spec()],
            ]);
            $shell = new RecordingToolsProbeRemoteShell(
                exitCode: 0,
                stdout: "/usr/bin/docker\tDocker version 27.0.0\tunknown\t\t\t\t\t1\tstopped\t{$container->specHash()}\n",
            );
            $probe = new ToolsProbe($shell);

            $snapshot = $probe->introspect($tool);
            $drift = $probe->diff($tool, $snapshot);
            $input = json_decode($shell->input, associative: true, flags: JSON_THROW_ON_ERROR);

            $issueKeys = array_map(fn ($entry) => $entry->key, $drift);

            expect($shell->script)->toContain('docker container inspect')
                ->and($input['container'])->toBe($container->name())
                ->and($input['container'])->toBe('orbit-e2e-dev-abc123-dev-orbit-caddy')
                ->and(in_array('tool.lifecycle_state_mismatch', $issueKeys, true))->toBeFalse();
        });
    });

    it('detects missing orbit-caddy containers separately from missing docker capability', function (): void {
        $node = createToolsProbeAppHostNode();
        $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => $container->spec()],
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/usr/bin/docker\tDocker version 27.0.0\tunknown\t\t\t\t\t0\tmissing\t\n",
        ));

        $drift = $probe->diff($tool, $probe->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.container_missing')?->kind)->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.capability_missing'))->toBeNull();
    });

    it('detects orbit-caddy container spec hash drift', function (): void {
        $node = createToolsProbeAppHostNode();
        $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => $container->spec()],
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(
            exitCode: 0,
            stdout: "/usr/bin/docker\tDocker version 27.0.0\tunknown\t\t\t\t\t1\trunning\t".str_repeat('b', 64)."\n",
        ));

        $drift = $probe->diff($tool, $probe->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.container_spec_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.container_spec_mismatch')?->detail)->toMatchArray([
                'expected_hash' => $container->specHash(),
                'observed_hash' => str_repeat('b', 64),
            ]);
    });

    it('passes managed config files when the managed file resource probe plans ok', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $hash = hash('sha256', $content);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => $hash,
                    'content' => $content,
                ],
            ],
        ]);
        $shell = new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/dns'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(true, $hash, '0644'), '', 1),
        );
        $probe = new ToolsProbe($shell);

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect($drift)->toBe([])
            ->and($snapshot->get('dns'))->toMatchArray([
                'config_exists' => true,
                'config_hash' => $hash,
                'config_mode' => '0644',
            ])
            ->and($shell->scripts)->toHaveCount(2)
            ->and($shell->scripts[1])->toContain('sudo test -f "$path"')
            ->and($shell->options[1])->toMatchArray(['throw' => false]);
    });

    it('uses managed file resource probes when batch introspecting managed config', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $hash = hash('sha256', $content);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => $hash,
                    'content' => $content,
                ],
            ],
        ]);
        $shell = new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(
                0,
                json_encode([
                    'name' => 'dns',
                    'installed' => true,
                    'path' => '/usr/bin/dns',
                    'version' => null,
                    'state' => 'running',
                    'container_exists' => null,
                    'container_state' => null,
                    'container_spec_hash' => null,
                ], JSON_THROW_ON_ERROR)."\n",
                '',
                1,
            ),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(true, $hash, '0644'), '', 1),
        );
        $probe = new ToolsProbe($shell);

        $snapshots = $probe->introspectMany([$tool]);

        expect($snapshots['dns']->get('dns'))->toMatchArray([
            'config_exists' => true,
            'config_hash' => $hash,
            'config_mode' => '0644',
        ])
            ->and($shell->scripts)->toHaveCount(2)
            ->and($shell->scripts[1])->toContain('sudo test -f "$path"');
    });

    it('detects missing managed config files through the managed file resource plan', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $hash = hash('sha256', $content);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => $hash,
                    'content' => $content,
                ],
            ],
        ]);
        $probe = new ToolsProbe(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/dns'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(false, null, null), '', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.config_missing')?->kind)->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.config_missing')?->detail)->toMatchArray([
                'path' => '/etc/orbit/dns.conf',
                'expected_hash' => $hash,
            ]);
    });

    it('detects managed config hash mismatches through the managed file resource plan', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $hash = hash('sha256', $content);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => $hash,
                    'content' => $content,
                ],
            ],
        ]);
        $probe = new ToolsProbe(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/dns'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(true, str_repeat('b', 64), '0644'), '', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.config_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.config_mismatch')?->detail)->toMatchArray([
                'path' => '/etc/orbit/dns.conf',
                'expected_hash' => $hash,
                'observed_hash' => str_repeat('b', 64),
            ]);
    });

    it('detects managed config mode mismatches through the managed file resource plan', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $hash = hash('sha256', $content);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => $hash,
                    'content' => $content,
                    'mode' => '0640',
                ],
            ],
        ]);
        $probe = new ToolsProbe(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/dns'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(true, $hash, '0600'), '', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.config_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.config_mismatch')?->detail)->toMatchArray([
                'path' => '/etc/orbit/dns.conf',
                'expected_hash' => $hash,
                'observed_hash' => $hash,
                'mode' => '0640',
                'observed_mode' => '0600',
            ]);
    });

    it('marks managed config probe failures as unverifiable instead of repairable mismatch', function (): void {
        $content = "address=/test/10.6.0.2\n";
        $hash = hash('sha256', $content);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => $hash,
                    'content' => $content,
                ],
            ],
        ]);
        $probe = new ToolsProbe(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/dns'), '', 1),
            new RemoteShellResult(255, '', 'ssh: connection refused', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.config_probe_failed')?->kind)->toBe(DriftKind::Unverifiable)
            ->and(toolProbeIssue($drift, 'tool.config_mismatch'))->toBeNull()
            ->and(toolProbeIssue($drift, 'tool.config_probe_failed')?->detail)->toMatchArray([
                'path' => '/etc/orbit/dns.conf',
                'error' => 'ssh: connection refused',
            ]);
    });

    it('marks managed config intent incomplete when declared content cannot satisfy the managed file resource', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'dns',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/orbit/dns.conf',
                    'hash' => str_repeat('a', 64),
                    'content' => "address=/test/10.6.0.2\n",
                ],
            ],
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([
            'dns' => ['installed' => true],
        ]));

        expect(toolProbeIssue($drift, 'tool.record_incomplete')?->kind)->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.record_incomplete')?->detail)->toMatchArray([
                'tool' => 'dns',
                'field' => 'managed_config',
            ]);
    });

    it('detects missing managed credential material through the managed file resource plan', function (): void {
        $secret = 'generated-password';
        $hash = hash('sha256', $secret);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => $hash,
                    'content' => $secret,
                ],
            ],
        ]);
        $probe = new ToolsProbe(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/opencode-server'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(false, null, null), '', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.credentials_missing')?->kind)->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.credentials_missing')?->detail)->toMatchArray([
                'path' => '/home/orbit/.config/opencode-server/password',
                'expected_hash' => $hash,
                'mode' => '0600',
            ]);
    });

    it('detects managed credential hash mismatches through the managed file resource plan', function (): void {
        $secret = 'generated-password';
        $hash = hash('sha256', $secret);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => $hash,
                    'content' => $secret,
                ],
            ],
        ]);
        $probe = new ToolsProbe(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/opencode-server'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(true, str_repeat('b', 64), '0644'), '', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.credentials_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.credentials_mismatch')?->detail)->toMatchArray([
                'path' => '/home/orbit/.config/opencode-server/password',
                'expected_hash' => $hash,
                'observed_hash' => str_repeat('b', 64),
                'mode' => '0600',
            ]);
    });

    it('detects managed credential mode mismatches through the managed file resource plan', function (): void {
        $secret = 'generated-password';
        $hash = hash('sha256', $secret);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => $hash,
                    'content' => $secret,
                ],
            ],
        ]);
        $probe = new ToolsProbe(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/opencode-server'), '', 1),
            new RemoteShellResult(0, toolsProbeManagedFileStdout(true, $hash, '0644'), '', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.credentials_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.credentials_mismatch')?->detail)->toMatchArray([
                'path' => '/home/orbit/.config/opencode-server/password',
                'expected_hash' => $hash,
                'observed_hash' => $hash,
                'mode' => '0600',
                'observed_mode' => '0644',
            ]);
    });

    it('marks managed credential probe failures as unverifiable instead of repairable mismatch', function (): void {
        $secret = 'generated-password';
        $hash = hash('sha256', $secret);
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => $hash,
                    'content' => $secret,
                ],
            ],
        ]);
        $probe = new ToolsProbe(new QueuedToolsProbeRemoteShell(
            new RemoteShellResult(0, toolsProbeCapabilityStdout('/usr/bin/opencode-server'), '', 1),
            new RemoteShellResult(255, '', 'ssh: connection refused', 1),
        ));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.credentials_probe_failed')?->kind)->toBe(DriftKind::Unverifiable)
            ->and(toolProbeIssue($drift, 'tool.credentials_mismatch'))->toBeNull()
            ->and(toolProbeIssue($drift, 'tool.credentials_probe_failed')?->detail)->toMatchArray([
                'path' => '/home/orbit/.config/opencode-server/password',
                'error' => 'ssh: connection refused',
            ]);
    });

    it('marks managed secret intent incomplete when it is not a valid managed file resource', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'credentials' => [
                'managed_secret' => [
                    'path' => 'relative/password',
                    'hash' => str_repeat('a', 64),
                    'content' => 'generated-password',
                ],
            ],
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([
            'opencode-server' => ['installed' => true],
        ]));

        expect(toolProbeIssue($drift, 'tool.record_incomplete')?->kind)->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.record_incomplete')?->detail)->toMatchArray([
                'tool' => 'opencode-server',
                'field' => 'managed_secret',
            ]);
    });

    it('detects missing agent tool proxy route', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing')?->kind)->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.agent_route_missing')?->detail)->toMatchArray([
                'tool' => 'openclaw',
                'domain' => 'openclaw.agent',
            ]);
    });

    it('passes when agent tool proxy route exists', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => toolsProbeAgentRouteSourceHash($node, 'openclaw'),
            'config' => toolsProbeAgentRouteConfig('openclaw'),
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing'))->toBeNull();
    });

    it('detects drift when agent tool proxy route is owned by a different tool', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'config' => ['owner_name' => 'hermes'],
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.agent_route_missing')?->detail)->toMatchArray([
                'tool' => 'openclaw',
                'domain' => 'openclaw.agent',
                'route_owner' => 'hermes',
            ]);
    });

    it('detects drift when agent tool proxy route has the wrong kind', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'upstream',
            'source_hash' => str_repeat('a', 64),
            'config' => toolsProbeAgentRouteConfig('openclaw'),
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.agent_route_missing')?->detail)->toMatchArray([
                'tool' => 'openclaw',
                'domain' => 'openclaw.agent',
                'expected_kind' => 'proxy',
                'observed_kind' => 'upstream',
            ]);
    });

    it('detects drift when agent tool proxy route config or source hash is stale', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => str_repeat('b', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:9999'],
                'upstream' => 'http://127.0.0.1:9999',
                'owner_name' => 'openclaw',
            ],
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.agent_route_missing')?->detail)->toMatchArray([
                'tool' => 'openclaw',
                'domain' => 'openclaw.agent',
                'expected_upstream' => 'http://host.docker.internal:8080',
                'observed_upstream' => 'http://127.0.0.1:9999',
            ]);
    });

    it('detects missing agent tool credentials metadata', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
            'credentials' => null,
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_credentials_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('passes when agent tool credentials metadata exists', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
            'credentials' => ['fields' => ['url' => 'https://openclaw.agent']],
        ]);

        $drift = (new ToolsProbe)->diff($tool, (new ToolsProbe)->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.agent_credentials_missing'))->toBeNull();
    });

    it('detects missing agent user for agent tools', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 1));

        $drift = $probe->diff($tool, $probe->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.agent_user_missing')?->kind)->toBe(DriftKind::Missing);
    });
});

final class ToolsProbeRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts;

    public function __construct(
        private int $exitCode = 0,
        private string $stdout = '',
    ) {
        $this->scripts = [];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}

final class RecordingToolsProbeRemoteShell implements RemoteShell
{
    public string $script = '';

    public string $input = '';

    public function __construct(
        private int $exitCode = 0,
        private string $stdout = '',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->script = $script;
        $this->input = is_string($options['input'] ?? null) ? $options['input'] : '';

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}

final class QueuedToolsProbeRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /**
     * @var list<RemoteShellResult>
     */
    private array $results;

    public function __construct(RemoteShellResult ...$results)
    {
        $this->results = $results;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected shell call', 1);
    }
}
