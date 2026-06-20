<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Nodes\Roles\NodeRoleDependencyInspector;
use App\Services\Nodes\Roles\RoleBaselines\AgentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppDevelopmentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\DatabaseRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\GatewayRoleBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $developmentDnsConfigDir = storage_path('framework/testing/node-role-assignment-dns/'.bin2hex(random_bytes(6)));

    app()->instance(DevelopmentDnsMappingEnactor::class, new DevelopmentDnsMappingEnactor($developmentDnsConfigDir));
});

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

describe('node role assignment service', function (): void {
    it('activates a compatible role after convergence succeeds', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

        ]);

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'database', []);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Active)
            ->and($assignment->role)
            ->toBe('database')
            ->and($assignment->converged_at)
            ->not->toBeNull()
            ->and($assignment->last_error)
            ->toBeNull()
            ->and($assignment->settings)
            ->toBe([]);
    });

    it('rejects duplicate role assignment before hitting the unique index', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'database', []))
            ->toThrow(InvalidArgumentException::class, "Role 'database' is already assigned to node '{$node->name}'.");
    });

    it('rejects app-dev assignment when another active node owns the tld', function (): void {
        $existingNode = Node::factory()->create([
            'platform' => 'ubuntu',

            'tld' => null,
            'wireguard_address' => '10.0.0.11',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $existingNode->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'test'],
        ]);
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

            'wireguard_address' => '10.0.0.12',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-dev', ['tld' => 'test']))
            ->toThrow(InvalidArgumentException::class, "Development TLD 'test' is already assigned to another node.");

        expect($node->roleAssignments()->where('role', 'app-dev')->exists())->toBeFalse();
    });

    it('rejects app-dev updates when another active node owns the tld', function (): void {
        $existingNode = Node::factory()->create([
            'platform' => 'ubuntu',

            'tld' => null,
            'wireguard_address' => '10.0.0.11',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $existingNode->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'test'],
        ]);
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

            'wireguard_address' => '10.0.0.12',
        ]);
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old'],
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->update($node, 'app-dev', ['tld' => 'test']))
            ->toThrow(InvalidArgumentException::class, "Development TLD 'test' is already assigned to another node.");

        expect($assignment->fresh()->settings)->toBe(['tld' => 'old'])
            ->and($assignment->fresh()->status)->toBe(NodeRoleStatus::Active)
            ->and($assignment->fresh()->last_error)->toBeNull();
    });

    it('syncs node tld from active app-dev and database roles', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'tld' => null,
            'wireguard_address' => '10.0.0.10',
        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'app-dev', ['tld' => 'test']);

        $node->refresh();

        expect($node->tld)->toBe('test');

        app(NodeRoleAssignmentService::class)->add($node, 'database', []);
        app(NodeRoleAssignmentService::class)->remove($node->refresh(), 'app-dev', force: true);

        $node->refresh();

        expect($node->tld)->toBe('test');

        app(NodeRoleAssignmentService::class)->remove($node, 'database', force: true);

        $node->refresh();

        expect($node->tld)->toBeNull();
    });

    it('preserves a fresh database node tld when the model instance is stale', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'tld' => null,
            'wireguard_address' => '10.0.0.10',
        ]);

        Node::query()
            ->whereKey($node->id)
            ->update(['tld' => 'db1']);

        app(NodeRoleAssignmentService::class)->add($node, 'database', []);

        expect($node->fresh()->tld)->toBe('db1');
    });

    it('materializes and reconciles role-derived self grants through role mutations', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

            'wireguard_address' => '10.6.0.20',
        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'app-dev', ['tld' => 'test']);

        $selfGrant = NodeAccess::query()
            ->where('consumer_node_id', $node->id)
            ->where('serving_node_id', $node->id)
            ->first();

        expect($selfGrant?->permissions)->toBe(['workspace:setup'])
            ->and($selfGrant?->custom_permissions)->toBe([]);

        app(NodeRoleAssignmentService::class)->remove($node->refresh(), 'app-dev', force: true);

        expect(NodeAccess::query()
            ->where('consumer_node_id', $node->id)
            ->where('serving_node_id', $node->id)
            ->exists())->toBeFalse();
    });

    it('materializes docker as a desired tool for database roles', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'database', []);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'docker')
            ->first();

        expect($tool)->not->toBeNull()
            ->and($tool->expected_state)->toBe('installed')
            ->and(NodeTool::query()
                ->where('node_id', $node->id)
                ->whereIn('name', ['mysql', 'postgres'])
                ->exists())->toBeFalse();
    });

    it('does not materialize sqlite3 as a desired tool for development app roles', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

            'wireguard_address' => '10.6.0.20',
        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'app-dev', ['tld' => 'test']);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'sqlite3')
            ->first();

        expect($tool)->toBeNull();
    });

    it('materializes the development app runtime baseline as desired tools', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

            'wireguard_address' => '10.6.0.20',
        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'app-dev', ['tld' => 'test']);

        $tools = NodeTool::query()
            ->where('node_id', $node->id)
            ->whereIn('name', ['caddy', 'composer', 'laravel-installer', 'php', 'php-cli', 'supervisor'])
            ->orderBy('name')
            ->get();

        expect($tools->pluck('name')->all())
            ->toBe(['caddy', 'composer', 'laravel-installer', 'php-cli'])
            ->and($tools->mapWithKeys(fn (NodeTool $tool): array => [$tool->name => $tool->expected_state])->all())
            ->toBe([
                'caddy' => 'installed',
                'composer' => 'installed',
                'laravel-installer' => 'installed',
                'php-cli' => 'installed',
            ]);

        app(NodeRoleAssignmentService::class)->remove($node->refresh(), 'app-dev', force: true);

        expect(NodeTool::query()
            ->where('node_id', $node->id)
            ->whereIn('name', ['caddy', 'composer', 'laravel-installer', 'php-cli'])
            ->exists())->toBeFalse();
    });

    it('materializes the production app runtime baseline as desired tools', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

            'host' => 'app-prod-1.example.com',
        ]);

        $ingressNode = Node::factory()->create([
            'platform' => 'ubuntu',

            'status' => 'active',
            'host' => 'edge-1.example.com',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $ingressNode->id,
            'role' => 'ingress',
            'status' => NodeRoleStatus::Active->value,
        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'app-prod', [
            'ingress_node_id' => $ingressNode->id,
        ]);

        $tools = NodeTool::query()
            ->where('node_id', $node->id)
            ->whereIn('name', ['caddy', 'composer', 'laravel-installer', 'php', 'php-cli', 'supervisor'])
            ->orderBy('name')
            ->get();

        expect($tools->pluck('name')->all())
            ->toBe(['caddy', 'composer', 'laravel-installer', 'php-cli'])
            ->and($tools->mapWithKeys(fn (NodeTool $tool): array => [$tool->name => $tool->expected_state])->all())
            ->toBe([
                'caddy' => 'installed',
                'composer' => 'installed',
                'laravel-installer' => 'installed',
                'php-cli' => 'installed',
            ]);
    });

    it('materializes the ingress baseline as desired tools', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

            'host' => 'edge-1.example.com',
        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'ingress', []);

        $tools = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'caddy')
            ->get();

        expect($tools->pluck('name')->all())->toBe(['caddy'])
            ->and($tools->first()?->expected_state)->toBe('installed');
    });

    it('rejects conflicting roles', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'test'],
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-prod', []))
            ->toThrow(InvalidArgumentException::class, "Role 'app-prod' conflicts with active role 'app-dev'.");
    });

    it('rejects app-prod assignment without an active ingress node', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'host' => 'app-prod-1.example.com',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-prod', []))
            ->toThrow(InvalidArgumentException::class, 'The app-prod role requires an active ingress node.');
    });

    it('rejects app-prod assignment when the selected ingress node is not active', function (): void {
        $ingressNode = Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'provisioning',
            'host' => 'edge-1.example.com',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $ingressNode->id,
            'role' => 'ingress',
            'status' => NodeRoleStatus::Active->value,
        ]);
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'host' => 'app-prod-1.example.com',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-prod', [
            'ingress_node_id' => $ingressNode->id,
        ]))->toThrow(InvalidArgumentException::class, 'The app-prod role requires an active ingress node.');
    });

    it('rejects app-prod assignment when the selected node lacks an active ingress role', function (): void {
        $ingressNode = Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
            'host' => 'edge-1.example.com',
        ]);
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'host' => 'app-prod-1.example.com',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-prod', [
            'ingress_node_id' => $ingressNode->id,
        ]))->toThrow(InvalidArgumentException::class, 'The app-prod role requires an active ingress node.');
    });

    it('allows app-prod assignment when the target node already has active ingress', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
            'host' => 'edge-app-1.example.com',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'ingress',
            'status' => NodeRoleStatus::Active->value,
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'app-prod', [
            'ingress_node_id' => $node->id,
        ]);

        expect($assignment->status)->toBe(NodeRoleStatus::Active)
            ->and($assignment->settings)->toBe([
                'ingress_node_id' => $node->id,
            ]);
    });

    it('rejects app-prod assignment when the selected ingress role is pending', function (): void {
        $ingressNode = Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
            'host' => 'edge-pending.example.com',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $ingressNode->id,
            'role' => 'ingress',
            'status' => NodeRoleStatus::Pending->value,
        ]);
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'host' => 'app-prod-1.example.com',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-prod', [
            'ingress_node_id' => $ingressNode->id,
        ]))->toThrow(InvalidArgumentException::class, 'The app-prod role requires an active ingress node.');
    });

    it('rejects app-prod assignment when the selected ingress role is errored', function (): void {
        $ingressNode = Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
            'host' => 'edge-error.example.com',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $ingressNode->id,
            'role' => 'ingress',
            'status' => NodeRoleStatus::Error->value,
        ]);
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'host' => 'app-prod-1.example.com',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-prod', [
            'ingress_node_id' => $ingressNode->id,
        ]))->toThrow(InvalidArgumentException::class, 'The app-prod role requires an active ingress node.');
    });

    it('revalidates the referenced ingress node during app-prod updates and preserves the previous assignment on failure', function (): void {
        $ingressNode = Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
            'host' => 'edge-1.example.com',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $ingressNode->id,
            'role' => 'ingress',
            'status' => NodeRoleStatus::Pending->value,
        ]);

        $appNode = Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
            'host' => 'app-prod-1.example.com',
        ]);
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $appNode->id,
            'role' => 'app-prod',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['ingress_node_id' => 999],
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->update($appNode, 'app-prod', [
            'ingress_node_id' => $ingressNode->id,
        ]))->toThrow(InvalidArgumentException::class, 'The app-prod role requires an active ingress node.');

        expect($assignment->fresh()->settings)->toBe(['ingress_node_id' => 999])
            ->and($assignment->fresh()->status)->toBe(NodeRoleStatus::Active)
            ->and($assignment->fresh()->last_error)->toBeNull();
    });

    it('rejects websocket assignment when redis node is not an active database node with a Redis process', function (callable $createRedisNode): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
        ]);
        $redisNode = $createRedisNode();

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'websocket', [
            'redis_node_id' => $redisNode->id,
        ]))->toThrow(InvalidArgumentException::class, 'The websocket role requires redis_node_id to reference an active database node with a Redis process.');

        expect($node->roleAssignments()->where('role', 'websocket')->exists())->toBeFalse();
    })->with([
        'non-database node with redis process' => fn (): Node => tap(Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
        ]), function (Node $node): void {
            Process::factory()->forOwner($node)->create([
                'name' => 'redis',
                'runtime_config' => ['definition' => 'redis'],
            ]);
        }),
        'inactive database node with redis process' => fn (): Node => tap(Node::factory()->database()->create([
            'platform' => 'ubuntu',
            'status' => 'provisioning',
        ]), function (Node $node): void {
            Process::factory()->forOwner($node)->create([
                'name' => 'redis',
                'runtime_config' => ['definition' => 'redis'],
            ]);
        }),
        'database node without redis process' => fn (): Node => Node::factory()->database()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
        ]),
        'database node with legacy redis tool row only' => fn (): Node => tap(Node::factory()->database()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
        ]), function (Node $node): void {
            NodeTool::factory()->create([
                'node_id' => $node->id,
                'name' => 'redis',
                'expected_state' => 'installed',
            ]);
        }),
    ]);

    it('allows websocket assignment when redis node is an active database node with a Redis process', function (): void {
        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger
        {
            public function __construct() {}

            public function converge(Node $node, NodeRoleAssignment $assignment): void {}
        });

        $databaseNode = Node::factory()->database()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
        ]);
        Process::factory()->forOwner($databaseNode)->create([
            'name' => 'redis',
            'runtime_config' => ['definition' => 'redis'],
        ]);
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'websocket', [
            'redis_node_id' => $databaseNode->id,
        ]);

        expect($assignment->status)->toBe(NodeRoleStatus::Active)
            ->and($assignment->settings)->toBe(['redis_node_id' => $databaseNode->id]);
    });

    it('rejects websocket updates with an invalid redis node and preserves the existing assignment', function (): void {
        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger
        {
            public function __construct() {}

            public function converge(Node $node, NodeRoleAssignment $assignment): void {}
        });

        $validRedisNode = Node::factory()->database()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
        ]);
        Process::factory()->forOwner($validRedisNode)->create([
            'name' => 'redis',
            'runtime_config' => ['definition' => 'redis'],
        ]);
        $invalidRedisNode = Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
        ]);
        NodeTool::factory()->create([
            'node_id' => $invalidRedisNode->id,
            'name' => 'redis',
            'expected_state' => 'installed',
        ]);
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
        ]);
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'websocket',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['redis_node_id' => $validRedisNode->id],
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->update($node, 'websocket', [
            'redis_node_id' => $invalidRedisNode->id,
        ]))->toThrow(InvalidArgumentException::class, 'The websocket role requires redis_node_id to reference an active database node with a Redis process.');

        expect($assignment->fresh()->settings)->toBe(['redis_node_id' => $validRedisNode->id])
            ->and($assignment->fresh()->status)->toBe(NodeRoleStatus::Active)
            ->and($assignment->fresh()->last_error)->toBeNull();
    });

    it('rejects pending and error role conflicts', function (string $status): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'host' => 'app-prod-1.example.com',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => $status,
            'settings' => ['tld' => 'test'],
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-prod', []))
            ->toThrow(InvalidArgumentException::class, "Role 'app-prod' conflicts with {$status} role 'app-dev'.");
    })->with([
        NodeRoleStatus::Pending->value,
        NodeRoleStatus::Error->value,
    ]);

    it('rejects updates when pending and error role conflicts exist', function (string $status): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu_24-04',

            'wireguard_address' => '10.0.0.10',
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old'],
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-prod',
            'status' => $status,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->update($node, 'app-dev', ['tld' => 'new']))
            ->toThrow(InvalidArgumentException::class, "Role 'app-dev' conflicts with {$status} role 'app-prod'.");

        expect($assignment->fresh()->settings)->toBe(['tld' => 'old'])
            ->and($assignment->fresh()->status)->toBe(NodeRoleStatus::Active)
            ->and($assignment->fresh()->last_error)->toBeNull();
    })->with([
        NodeRoleStatus::Pending->value,
        NodeRoleStatus::Error->value,
    ]);

    it('rejects roles that conflict with an active gateway assignment', function (string $role, array $settings): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'host' => 'gateway.example.com',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, $role, $settings))
            ->toThrow(InvalidArgumentException::class, "Role '{$role}' conflicts with active role 'gateway'.");
    })->with([
        'app-dev' => ['app-dev', ['tld' => 'test']],
        'app-prod' => ['app-prod', ['ingress_node_id' => 9999]],
        'database' => ['database', []],
        'ingress' => ['ingress', []],
    ]);

    it('ignores removing assignments during conflict checks', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'status' => 'active',
            'host' => 'app-prod-1.example.com',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Removing->value,
            'settings' => ['tld' => 'test'],
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'ingress',
            'status' => NodeRoleStatus::Active->value,
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'app-prod', [
            'ingress_node_id' => $node->id,
        ]);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Active)
            ->and($assignment->role)
            ->toBe('app-prod');
    });

    it('marks role as error when convergence fails', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger
        {
            public function __construct()
            {
                parent::__construct(
                    app(GatewayRoleBaseline::class),
                    app(AppDevelopmentRoleBaseline::class),
                    app(AppProductionRoleBaseline::class),
                    app(DatabaseRoleBaseline::class),
                    app(AgentRoleBaseline::class),
                );
            }

            public function converge(Node $node, NodeRoleAssignment $assignment): void
            {
                throw new RuntimeException('Docker is missing.');
            }
        });

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'database', []);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Error)
            ->and($assignment->last_error)
            ->toBe('Docker is missing.')
            ->and($assignment->converged_at)
            ->toBeNull();
    });

    it('marks app-dev as error when the development dns mapping cannot be materialized', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

            'wireguard_address' => null,
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'app-dev', ['tld' => 'test']);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Error)
            ->and($assignment->last_error)
            ->toBe('The app-dev role requires a WireGuard address so the development DNS mapping can be materialized.')
            ->and($assignment->converged_at)
            ->toBeNull();
    });

    it('rejects production and database baselines for nodes with an assigned gateway role', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

            'host' => 'gateway.example.com',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => NodeRoleStatus::Active->value,
        ]);

        $productionAssignment = NodeRoleAssignment::factory()->make([
            'node_id' => $node->id,
            'role' => 'app-prod',
            'status' => NodeRoleStatus::Pending->value,
        ]);
        $databaseAssignment = NodeRoleAssignment::factory()->make([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Pending->value,
        ]);

        expect(fn () => app(AppProductionRoleBaseline::class)->converge($node, $productionAssignment))
            ->toThrow(RuntimeException::class, 'The app-prod role cannot be assigned to a gateway node.');

        expect(fn () => app(DatabaseRoleBaseline::class)->converge($node, $databaseAssignment))
            ->toThrow(RuntimeException::class, 'The database role cannot be assigned to a gateway node.');
    });

    it('updates an existing role and re-activates it after convergence succeeds', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu_24-04',

            'wireguard_address' => '10.0.0.10',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old'],
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->update($node, 'app-dev', ['tld' => 'new']);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Active)
            ->and($assignment->settings)
            ->toBe(['tld' => 'new'])
            ->and($assignment->last_error)
            ->toBeNull()
            ->and($assignment->converged_at)
            ->not->toBeNull();
    });

    it('removes the previous development dns mapping after an app-dev tld update', function (): void {
        $configDir = app(DevelopmentDnsMappingEnactor::class)->configDir();

        File::deleteDirectory($configDir);
        File::ensureDirectoryExists($configDir);
        File::put("{$configDir}/old.conf", 'stale mapping');

        $node = Node::factory()->create([
            'platform' => 'ubuntu_24-04',

            'tld' => 'old',
            'wireguard_address' => '10.0.0.10',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old'],
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->update($node, 'app-dev', ['tld' => 'new']);

        expect($assignment->status)->toBe(NodeRoleStatus::Active)
            ->and($assignment->settings)->toBe(['tld' => 'new'])
            ->and("{$configDir}/old.conf")->not->toBeFile()
            ->and("{$configDir}/new.conf")->toBeFile();
    });

    it('rejects updates when a conflicting role is active', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu_24-04',

            'wireguard_address' => '10.0.0.10',
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old'],
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-prod',
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->update($node, 'app-dev', ['tld' => 'new']))
            ->toThrow(InvalidArgumentException::class, "Role 'app-dev' conflicts with active role 'app-prod'.");

        expect($assignment->fresh()->settings)->toBe(['tld' => 'old'])
            ->and($assignment->fresh()->status)->toBe(NodeRoleStatus::Active)
            ->and($assignment->fresh()->last_error)->toBeNull();
    });

    it('rejects unsupported platforms', function (): void {
        $node = Node::factory()->create([
            'platform' => 'macos_15',

        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-dev', ['tld' => 'test']))
            ->toThrow(InvalidArgumentException::class, "Role 'app-dev' does not support platform 'macos_15'.");
    });

    it('rejects gateway-coupled role assignment through the normal service', function (string $role): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, $role, []))
            ->toThrow(InvalidArgumentException::class, "Role '{$role}' is gateway-coupled and cannot be assigned independently.");

        expect(fn () => app(NodeRoleAssignmentService::class)->addDuringCreation($node, $role, []))
            ->toThrow(InvalidArgumentException::class, "Role '{$role}' is gateway-coupled and cannot be assigned independently.");
    })->with([
        'gateway' => 'gateway',
        'vpn' => 'vpn',
        'router' => 'router',
    ]);

    it('rejects gateway-coupled role updates through the normal service', function (string $role): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->update($node, $role, []))
            ->toThrow(InvalidArgumentException::class, "Role '{$role}' is gateway-coupled and cannot be assigned independently.");
    })->with([
        'gateway' => 'gateway',
        'vpn' => 'vpn',
        'router' => 'router',
    ]);

    it('rejects unknown roles during removal through the registry', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'queue'))
            ->toThrow(InvalidArgumentException::class, 'Unknown node role [queue].');
    });

    it('rejects gateway-coupled role removal through the normal service', function (string $role): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, $role))
            ->toThrow(InvalidArgumentException::class, "Role '{$role}' is gateway-coupled and cannot be assigned independently.");
    })->with([
        'gateway' => 'gateway',
        'vpn' => 'vpn',
        'router' => 'router',
    ]);

    it('rejects agent assignment through the normal service', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'agent', []))
            ->toThrow(InvalidArgumentException::class, "Role 'agent' cannot be assigned through this service.");
    });

    it('allows agent assignment during node creation', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',

            'wireguard_address' => '10.6.0.50',
        ]);

        app()->instance(RemoteShell::class, new class implements RemoteShell
        {
            public function run(Node $node, string $script, array $options = []): RemoteShellResult
            {
                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: '',
                    stderr: '',
                    durationMs: 0,
                );
            }
        });

        $assignment = app(NodeRoleAssignmentService::class)->addDuringCreation($node, 'agent', ['tld' => 'agent']);

        expect($assignment->role)->toBe('agent')
            ->and($assignment->status)->toBe(NodeRoleStatus::Active);
    });

    it('blocks removal when dependents exist and force is false', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
        ]);

        App::factory()->create([
            'node_id' => $node->id,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'app-dev'))
            ->toThrow(InvalidArgumentException::class, "Role 'app-dev' cannot be removed while dependents exist.");
    });

    it('rechecks removal dependents inside the transaction before destructive cleanup', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
        ]);
        $inspector = new class extends NodeRoleDependencyInspector
        {
            public int $calls = 0;

            public bool $removed = false;

            public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
            {
                $this->calls++;

                return $this->calls === 1 ? [] : ['1 development app record'];
            }

            public function removeOrbitOwnedDependents(Node $node, NodeRoleAssignment $assignment): void
            {
                $this->removed = true;
            }
        };
        app()->instance(NodeRoleDependencyInspector::class, $inspector);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'app-dev'))
            ->toThrow(InvalidArgumentException::class, "Role 'app-dev' cannot be removed while dependents exist.");

        expect($assignment->fresh()->status)->toBe(NodeRoleStatus::Active)
            ->and($inspector->calls)->toBe(2)
            ->and($inspector->removed)->toBeFalse();
    });

    it('requires force when purge data is requested', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'database', purgeData: true))
            ->toThrow(InvalidArgumentException::class, 'The purgeData option requires force.');
    });

    it('forces removal by deleting Orbit-owned dependents and deleting the assignment', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
        ]);

        $app = App::factory()->create([
            'node_id' => $node->id,
        ]);
        ProxyRoute::factory()->forApp($app)->create([
            'node_id' => $node->id,
            'domain' => 'docs.test',
        ]);

        app(NodeRoleAssignmentService::class)->remove($node, 'app-dev', force: true);

        expect(App::query()->whereKey($app->id)->exists())->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('blocks ingress removal while public proxy route records depend on it', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);
        $backendNode = Node::factory()->create(['platform' => 'ubuntu']);
        $app = App::factory()->create([
            'node_id' => $backendNode->id,
            'environment' => 'production',
        ]);
        $workspace = Workspace::factory()->create(['app_id' => $app->id]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'ingress',
            'status' => NodeRoleStatus::Active->value,
        ]);

        ProxyRoute::factory()->forApp($app)->create([
            'node_id' => $node->id,
            'kind' => 'app',
            'owner_type' => 'app',
            'config' => ['placement' => 'ingress'],
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'kind' => 'workspace',
            'owner_type' => 'workspace',
            'config' => ['placement' => 'ingress'],
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'ingress'))
            ->toThrow(InvalidArgumentException::class, "Role 'ingress' cannot be removed while dependents exist.");
    });

    it('does not count custom ingress placement routes as ingress dependents', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'ingress',
            'status' => NodeRoleStatus::Active->value,
        ]);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => [
                'placement' => 'ingress',
                'upstream' => 'http://127.0.0.1:8080',
            ],
        ]);

        app(NodeRoleAssignmentService::class)->remove($node, 'ingress');

        expect(ProxyRoute::query()->where('node_id', $node->id)->count())->toBe(1)
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('forces ingress removal by deleting Orbit-owned public proxy route records', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);
        $backendNode = Node::factory()->create(['platform' => 'ubuntu']);
        $app = App::factory()->create([
            'node_id' => $backendNode->id,
            'environment' => 'production',
        ]);
        $workspace = Workspace::factory()->create(['app_id' => $app->id]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'ingress',
            'status' => NodeRoleStatus::Active->value,
        ]);

        ProxyRoute::factory()->forApp($app)->create([
            'node_id' => $node->id,
            'kind' => 'app',
            'owner_type' => 'app',
            'config' => ['placement' => 'ingress'],
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'kind' => 'workspace',
            'owner_type' => 'workspace',
            'config' => ['placement' => 'ingress'],
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => [
                'placement' => 'ingress',
                'upstream' => 'http://127.0.0.1:8080',
            ],
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => [
                'placement' => 'ingress',
                'target' => ['value' => 'https://example.com'],
                'code' => 302,
            ],
        ]);
        ProxyRoute::factory()->create([
            'node_id' => Node::factory()->create(['platform' => 'ubuntu'])->id,
            'kind' => 'app',
            'owner_type' => 'app',
            'config' => ['placement' => 'ingress'],
        ]);

        app(NodeRoleAssignmentService::class)->remove($node, 'ingress', force: true);

        expect(ProxyRoute::query()->where('node_id', $node->id)->count())->toBe(2)
            ->and(ProxyRoute::query()->where('node_id', $node->id)->pluck('owner_type')->all())->toBe(['custom', 'custom'])
            ->and(ProxyRoute::query()->count())->toBe(3)
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('removes Orbit-owned dependents before removing role baselines', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
        ]);

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject;

        app()->instance(NodeRoleDependencyInspector::class, new class($events) extends NodeRoleDependencyInspector
        {
            /**
             * @param  ArrayObject<int, string>  $events
             */
            public function __construct(private readonly ArrayObject $events) {}

            public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
            {
                return ['1 development app record'];
            }

            public function removeOrbitOwnedDependents(Node $node, NodeRoleAssignment $assignment): void
            {
                $this->events->append('dependents');
            }
        });

        app()->instance(NodeRoleBaselineConverger::class, new class($events) extends NodeRoleBaselineConverger
        {
            /**
             * @param  ArrayObject<int, string>  $events
             */
            public function __construct(private readonly ArrayObject $events) {}

            public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                $this->events->append('baseline');
            }
        });

        app(NodeRoleAssignmentService::class)->remove($node, 'app-dev', force: true);

        expect($events->getArrayCopy())->toBe(['dependents', 'baseline']);
    });

    it('removes app dependents and passes purge intent when purge data is requested', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
        ]);

        $app = App::factory()->create([
            'node_id' => $node->id,
        ]);
        ProxyRoute::factory()->forApp($app)->create([
            'node_id' => $node->id,
            'domain' => 'docs.test',
        ]);

        app(NodeRoleAssignmentService::class)->remove($node, 'app-dev', force: true, purgeData: true);

        expect(App::query()->whereKey($app->id)->exists())->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('forces database role removal by deleting database dependents and clearing docker baseline intent', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);
        Process::factory()->forOwner($node)->create([
            'name' => 'postgres16',
            'runtime_config' => ['definition' => 'postgres'],
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'docker',
            'expected_state' => 'installed',
        ]);

        app(NodeRoleAssignmentService::class)->remove($node, 'database', force: true);

        expect(Process::query()->ownedBy($node)->where('runtime_config->definition', 'postgres')->exists())->toBeFalse()
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'docker')->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('removes database dependents and passes purge intent when purge data is requested', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);
        Process::factory()->forOwner($node)->create([
            'name' => 'postgres16',
            'runtime_config' => ['definition' => 'postgres'],
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'docker',
            'expected_state' => 'installed',
        ]);

        app(NodeRoleAssignmentService::class)->remove($node, 'database', force: true, purgeData: true);

        expect(Process::query()->ownedBy($node)->where('runtime_config->definition', 'postgres')->exists())->toBeFalse()
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'docker')->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('leaves the assignment in error and keeps dependents intact when baseline removal fails', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'test'],
        ]);
        $app = App::factory()->create([
            'node_id' => $node->id,
        ]);
        ProxyRoute::factory()->forApp($app)->create([
            'node_id' => $node->id,
            'domain' => 'docs.test',
        ]);
        $inspector = new class extends NodeRoleDependencyInspector
        {
            public bool $removed = false;

            public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
            {
                return ['1 development app record'];
            }

            public function removeOrbitOwnedDependents(Node $node, NodeRoleAssignment $assignment): void
            {
                $this->removed = true;
            }
        };
        app()->instance(NodeRoleDependencyInspector::class, $inspector);

        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger
        {
            public function __construct()
            {
                parent::__construct(
                    app(GatewayRoleBaseline::class),
                    app(AppDevelopmentRoleBaseline::class),
                    app(AppProductionRoleBaseline::class),
                    app(DatabaseRoleBaseline::class),
                    app(AgentRoleBaseline::class),
                );
            }

            public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                throw new RuntimeException('Cleanup failed.');
            }
        });

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'app-dev', force: true))
            ->toThrow(RuntimeException::class, 'Cleanup failed.');

        expect($assignment->fresh()->status)
            ->toBe(NodeRoleStatus::Error)
            ->and($assignment->fresh()->last_error)
            ->toBe('Cleanup failed.')
            ->and($inspector->removed)
            ->toBeTrue()
            ->and(App::query()->whereKey($app->id)->exists())
            ->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())
            ->toBeTrue();
    });
});
