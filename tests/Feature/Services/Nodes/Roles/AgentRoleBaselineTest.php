<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\RoleBaselines\AgentRoleBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->configDir = storage_path('framework/testing/agent-dns');
    File::deleteDirectory($this->configDir);

    $this->agentRemoteShell = fakeAgentRemoteShell();
    app()->instance(RemoteShell::class, $this->agentRemoteShell);
});

afterEach(function (): void {
    File::deleteDirectory($this->configDir);
});

function fakeAgentRemoteShell(): RemoteShell
{
    return new class implements RemoteShell
    {
        /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
        public array $calls = [];

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            $this->calls[] = ['node' => $node, 'script' => $script, 'options' => $options];

            return new RemoteShellResult(
                exitCode: 0,
                stdout: '',
                stderr: '',
                durationMs: 0,
            );
        }
    };
}

describe('agent role baseline', function (): void {
    it('converges caddy as a desired tool', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'wireguard_address' => '10.6.0.50',
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => ['tld' => 'agent'],
        ]);

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        $tools = NodeTool::query()
            ->where('node_id', $node->id)
            ->whereIn('name', ['caddy', 'supervisor'])
            ->orderBy('name')
            ->get();

        expect($tools->pluck('name')->all())->toBe(['caddy'])
            ->and($tools->mapWithKeys(fn (NodeTool $tool): array => [$tool->name => $tool->expected_state])->all())
            ->toBe([
                'caddy' => 'installed',
            ]);
    });

    it('materializes a gateway-owned agent dns mapping for the tld', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'wireguard_address' => '10.6.0.50',
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => ['tld' => 'agent'],
        ]);

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        expect(File::exists("{$this->configDir}/agent.conf"))->toBeTrue();
        expect(File::get("{$this->configDir}/agent.conf"))
            ->toContain('orbit-managed=node-development-dns')
            ->toContain('address=/agent/10.6.0.50');
    });

    it('converges the shared unprivileged agent user via remote shell', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'wireguard_address' => '10.6.0.50',
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => ['tld' => 'agent'],
        ]);

        $shell = fakeAgentRemoteShell();

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
            remoteShell: $shell,
        );

        $baseline->converge($node, $assignment);

        expect($shell->calls)->toHaveCount(2)
            ->and($shell->calls[0]['script'])->toBe('id -u agent >/dev/null 2>&1 || sudo useradd --create-home --shell /bin/bash agent')
            ->and($shell->calls[0]['options'])->toBe(['throw' => true])
            ->and($shell->calls[1]['script'])->toBe('sudo passwd -l agent >/dev/null 2>&1 || true')
            ->and($shell->calls[1]['options'])->toBe(['throw' => true]);
    });

    it('rejects agent convergence without a wireguard address', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'wireguard_address' => null,
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => ['tld' => 'agent'],
        ]);

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        expect(fn () => $baseline->converge($node, $assignment))
            ->toThrow(RuntimeException::class, 'The agent role requires a WireGuard address so the agent DNS mapping can be materialized.');
    });

    it('rejects agent convergence on gateway nodes', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'wireguard_address' => '10.6.0.2',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Gateway->value,
            'status' => NodeRoleStatus::Active->value,
        ]);

        $assignment = NodeRoleAssignment::factory()->make([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => ['tld' => 'agent'],
        ]);

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        expect(fn () => $baseline->converge($node, $assignment))
            ->toThrow(RuntimeException::class, 'The agent role cannot be assigned to a gateway node.');
    });

    it('rejects agent convergence on non-ubuntu platforms', function (): void {
        $node = Node::factory()->create([
            'platform' => 'macos_15',
            'wireguard_address' => '10.6.0.50',
        ]);

        $assignment = NodeRoleAssignment::factory()->make([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => ['tld' => 'agent'],
        ]);

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        expect(fn () => $baseline->converge($node, $assignment))
            ->toThrow(RuntimeException::class, 'The agent role requires an Ubuntu host.');
    });

    it('removes agent baseline including dns mapping and tools', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'wireguard_address' => '10.6.0.50',
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'agent'],
        ]);

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        expect(File::exists("{$this->configDir}/agent.conf"))->toBeTrue();
        expect(NodeTool::query()->where('node_id', $node->id)->exists())->toBeTrue();

        $baseline->remove($node, $assignment, purgeData: false);

        expect(File::exists("{$this->configDir}/agent.conf"))->toBeFalse();
        expect(NodeTool::query()->where('node_id', $node->id)->exists())->toBeFalse();
    });
});

describe('agent role tld uniqueness', function (): void {
    it('rejects agent assignment during creation when another active node owns the tld via app-dev', function (): void {
        $existingNode = Node::factory()->create([
            'platform' => 'ubuntu',
            'tld' => null,
            'wireguard_address' => '10.0.0.11',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $existingNode->id,
            'role' => NodeRoleName::AppDevelopment->value,
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'test'],
        ]);

        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'wireguard_address' => '10.0.0.12',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->addDuringCreation($node, 'agent', ['tld' => 'test']))
            ->toThrow(InvalidArgumentException::class, "Node TLD 'test' is already assigned to another node.");

        expect($node->roleAssignments()->where('role', 'agent')->exists())->toBeFalse();
    });

    it('rejects agent assignment during creation when another active agent node owns the tld', function (): void {
        $existingNode = Node::factory()->create([
            'platform' => 'ubuntu',
            'wireguard_address' => '10.0.0.11',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $existingNode->id,
            'role' => 'agent',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'agent'],
        ]);

        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'wireguard_address' => '10.0.0.12',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->addDuringCreation($node, 'agent', ['tld' => 'agent']))
            ->toThrow(InvalidArgumentException::class, "Node TLD 'agent' is already assigned to another node.");

        expect($node->roleAssignments()->where('role', 'agent')->exists())->toBeFalse();
    });

    it('rejects app-dev assignment when an active agent node owns the tld', function (): void {
        $existingNode = Node::factory()->create([
            'platform' => 'ubuntu',
            'wireguard_address' => '10.0.0.11',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $existingNode->id,
            'role' => 'agent',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'test'],
        ]);

        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'wireguard_address' => '10.0.0.12',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, NodeRoleName::AppDevelopment->value, ['tld' => 'test']))
            ->toThrow(InvalidArgumentException::class, "Node TLD 'test' is already assigned to another node.");

        expect($node->roleAssignments()->where('role', NodeRoleName::AppDevelopment->value)->exists())->toBeFalse();
    });
});
