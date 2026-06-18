<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\DevelopmentDnsMappingProbe;
use App\Services\Nodes\NodesProbe;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $remoteShell = new NodesProbeRoleAssignmentsRemoteShell;
    $developmentDnsConfigDir = storage_path('framework/testing/nodes-probe-role-dns/'.bin2hex(random_bytes(6)));
    $developmentDnsMappingEnactor = new DevelopmentDnsMappingEnactor($developmentDnsConfigDir);

    $this->app->instance(DevelopmentDnsMappingEnactor::class, $developmentDnsMappingEnactor);
    $this->app->instance(DevelopmentDnsMappingProbe::class, new DevelopmentDnsMappingProbe($developmentDnsMappingEnactor));
    $this->app->instance(RemoteShell::class, $remoteShell);
    $this->app->instance(NodesProbe::class, new NodesProbe(remoteShell: $remoteShell));
    $this->probe = app(NodesProbe::class);
});

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

function roleDriftEntries(Node $node): array
{
    $probe = app(NodesProbe::class);

    return array_values(array_filter(
        $probe->diff($node->fresh()->load('roleAssignments'), new ProbeSnapshot([])),
        fn (DriftEntry $entry): bool => str_starts_with($entry->key, 'node.role_'),
    ));
}

it('does not synthesize missing role drift for unassigned nodes', function (): void {
    $node = Node::factory()->create([

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toBe([]);
});

it('does not synthesize missing role drift for unassigned nodes without a host', function (): void {
    $node = Node::factory()->create([

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '',
        'wireguard_address' => '10.6.0.6',
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toBe([]);
});

it('reports invalid role assignments with unknown roles', function (): void {
    $node = Node::factory()->create([

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'unknown-role',
        'status' => NodeRoleStatus::Active->value,
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toHaveCount(1)
        ->and($roleDrift[0]->key)->toBe('node.role_assignment_invalid')
        ->and($roleDrift[0]->kind)->toBe(DriftKind::Divergent);
});

it('reports invalid role settings when assignment settings do not hydrate', function (): void {
    $node = Node::factory()->create([

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toHaveCount(1)
        ->and($roleDrift[0]->key)->toBe('node.role_settings_invalid')
        ->and($roleDrift[0]->kind)->toBe(DriftKind::Divergent);
});

it('reports conflicting unresolved role assignments', function (NodeRoleStatus $conflictingStatus): void {
    $node = Node::factory()->create([
        'name' => 'test',

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-prod',
        'status' => $conflictingStatus->value,
    ]);

    $configDir = app(DevelopmentDnsMappingEnactor::class)->configDir();

    File::ensureDirectoryExists($configDir);
    File::put("{$configDir}/test.conf", implode("\n", [
        '# orbit-managed=node-development-dns',
        '# node=test',
        '# bind-scope=orbit_network',
        'address=/test/10.6.0.5',
        '',
    ]));

    $roleDrift = roleDriftEntries($node);
    $conflictDrift = array_values(array_filter(
        $roleDrift,
        fn (DriftEntry $entry): bool => $entry->key === 'node.role_conflict',
    ));

    expect($conflictDrift)->toHaveCount(1)
        ->and($conflictDrift[0]->kind)->toBe(DriftKind::Divergent);
})->with([
    'active' => [NodeRoleStatus::Active],
    'pending' => [NodeRoleStatus::Pending],
    'error' => [NodeRoleStatus::Error],
]);

it('reports invalid role settings when an active app-dev assignment has no tld', function (): void {
    $node = Node::factory()->create([

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => ''],
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toHaveCount(1)
        ->and($roleDrift[0]->key)->toBe('node.role_settings_invalid')
        ->and($roleDrift[0]->kind)->toBe(DriftKind::Divergent);
});

it('reports convergence failures for error assignments', function (): void {
    $node = Node::factory()->create([

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => NodeRoleStatus::Error->value,
        'last_error' => 'baseline failed',
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toHaveCount(1)
        ->and($roleDrift[0]->key)->toBe('node.role_convergence_failed')
        ->and($roleDrift[0]->kind)->toBe(DriftKind::Divergent)
        ->and($roleDrift[0]->detail)->toMatchArray([
        ]);
});

it('reports baseline mismatches for active role-owned artifacts', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toHaveCount(1)
        ->and($roleDrift[0]->key)->toBe('node.role_baseline_mismatch')
        ->and($roleDrift[0]->kind)->toBe(DriftKind::Missing)
        ->and($roleDrift[0]->detail)->toMatchArray([
            'tld' => 'test',
        ]);
});

it('does not require node environment when active role assignments provide the required facts', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
    ]);

    $configDir = app(DevelopmentDnsMappingEnactor::class)->configDir();

    File::ensureDirectoryExists($configDir);
    File::put("{$configDir}/test.conf", implode("\n", [
        '# orbit-managed=node-development-dns',
        '# node=test',
        '# bind-scope=orbit_network',
        'address=/test/10.6.0.5',
        '',
    ]));

    $drift = $this->probe->diff($node->fresh()->load('roleAssignments'), new ProbeSnapshot([]));
    $recordIncomplete = array_values(array_filter(
        $drift,
        fn (DriftEntry $entry): bool => $entry->key === 'node.record_incomplete',
    ));

    expect($recordIncomplete)->toBeEmpty();
});

it('does not require host for database-only nodes', function (): void {
    $node = Node::factory()->create([
        'name' => 'database',

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '',
        'wireguard_address' => '10.6.0.6',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $drift = $this->probe->diff($node->fresh()->load('roleAssignments'), new ProbeSnapshot([]));
    $recordIncomplete = array_values(array_filter(
        $drift,
        fn (DriftEntry $entry): bool => $entry->key === 'node.record_incomplete',
    ));

    expect($recordIncomplete)->toBeEmpty();
});

it('retries baseline convergence for error assignments during reconcile', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Error->value,
        'settings' => ['tld' => 'test'],
        'last_error' => 'baseline failed',
        'converged_at' => null,
    ]);

    $this->app->bind(NodeRoleBaselineConverger::class, function (): NodeRoleBaselineConverger {
        return new class extends NodeRoleBaselineConverger
        {
            public array $convergedRoles = [];

            public function __construct() {}

            public function converge(Node $node, NodeRoleAssignment $assignment): void
            {
                $this->convergedRoles[] = $assignment->role;
            }

            public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                throw new RuntimeException('not used');
            }
        };
    });

    $this->probe->reconcile($node, new DriftEntry(
        family: 'nodes',
        key: 'node.role_convergence_failed',
        kind: DriftKind::Divergent,
        summary: 'retry role convergence',
        detail: [
            'role' => 'app-dev',
        ],
    ));

    expect($assignment->fresh())
        ->status->toBe(NodeRoleStatus::Active)
        ->last_error->toBeNull()
        ->converged_at->not->toBeNull();
});

it('keeps role assignments errored when convergence retry fails during reconcile', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Error->value,
        'settings' => ['tld' => 'test'],
        'last_error' => 'baseline failed',
        'converged_at' => null,
    ]);

    $this->app->bind(NodeRoleBaselineConverger::class, function (): NodeRoleBaselineConverger {
        return new class extends NodeRoleBaselineConverger
        {
            public function __construct() {}

            public function converge(Node $node, NodeRoleAssignment $assignment): void
            {
                throw new RuntimeException('baseline still failed');
            }

            public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                throw new RuntimeException('not used');
            }
        };
    });

    expect(fn () => $this->probe->reconcile($node, new DriftEntry(
        family: 'nodes',
        key: 'node.role_convergence_failed',
        kind: DriftKind::Divergent,
        summary: 'retry role convergence',
        detail: [
            'role' => 'app-dev',
        ],
    )))->toThrow(RuntimeException::class, 'baseline still failed');

    expect($assignment->fresh())
        ->status->toBe(NodeRoleStatus::Error)
        ->last_error->toBe('baseline still failed')
        ->converged_at->toBeNull();
});

it('restores role-owned settings-derived artifacts during reconcile', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
    ]);

    $this->probe->reconcile($node, new DriftEntry(
        family: 'nodes',
        key: 'node.role_baseline_mismatch',
        kind: DriftKind::Missing,
        summary: 'restore role baseline',
        detail: [
            'role' => 'app-dev',
            'tld' => 'test',
        ],
    ));

    expect(app(DevelopmentDnsMappingEnactor::class)->configDir().'/test.conf')
        ->toBeFile();
});

it('only re-converges the role assignment that owns a baseline mismatch', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $converger = new class extends NodeRoleBaselineConverger
    {
        public array $convergedRoles = [];

        public function __construct() {}

        public function converge(Node $node, NodeRoleAssignment $assignment): void
        {
            $this->convergedRoles[] = $assignment->role;
        }

        public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
        {
            throw new RuntimeException('not used');
        }
    };

    $this->app->instance(NodeRoleBaselineConverger::class, $converger);

    $this->probe->reconcile($node, new DriftEntry(
        family: 'nodes',
        key: 'node.role_baseline_mismatch',
        kind: DriftKind::Missing,
        summary: 'restore role baseline',
        detail: [
            'role' => 'app-dev',
            'tld' => 'test',
        ],
    ));

    expect($converger->convergedRoles)->toBe(['app-dev']);
});

final class NodesProbeRoleAssignmentsRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
