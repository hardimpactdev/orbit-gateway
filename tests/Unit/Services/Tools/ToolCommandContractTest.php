<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Tools\ToolPayloadMapper;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolShowLiveInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function assignToolContractAppHostRole(Node $node, string $role = 'app-dev', array $settings = ['tld' => 'test']): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

describe('tool command shared contract', function (): void {
    it('maps node tool models to the canonical JSON entity shape without runtime instance state', function (): void {
        $node = new Node(['name' => 'app-contract-1']);
        $tool = new NodeTool([
            'name' => 'php',
            'expected_state' => 'installed',
            'expected_version' => '8.5',
            'config' => [
                'endpoints' => [],
            ],
        ]);
        $tool->setRelation('node', $node);

        $payload = app(ToolPayloadMapper::class)->toArray($tool);

        expect(array_keys($payload))->toBe([
            'name',
            'node',
            'expected_state',
            'observed_state',
            'version',
            'managed',
            'endpoints',
        ])
            ->and($payload)->toMatchArray([
                'name' => 'php',
                'node' => 'app-contract-1',
                'expected_state' => 'installed',
                'observed_state' => null,
                'version' => '8.5',
                'managed' => true,
                'endpoints' => [],
            ])
            ->and($payload)->not->toHaveKeys(['instance', 'version_family', 'runtime']);
    });

    it('keeps observed state out of the registry model because tool:list does not probe live state', function (): void {
        $tool = new NodeTool;

        expect($tool->getFillable())->toBe([
            'node_id',
            'name',
            'expected_state',
            'expected_version',
            'config',
            'credentials',
        ])
            ->and($tool->getFillable())->not->toContain('observed_state', 'instance_key', 'version_family', 'runtime', 'runtime_config');
    });

    it('preserves populated observed state as a gateway-owned live inspection overlay', function (): void {
        $node = createTestAppHostNode(['name' => 'app-contract-live', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'name' => 'php-cli',
            'node_id' => $node->id,
            'expected_state' => 'installed',
            'expected_version' => '8.5',
        ]);

        app()->instance(RemoteShell::class, new class implements RemoteShell
        {
            public function run(Node $node, string $script, array $options = []): RemoteShellResult
            {
                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: "/opt/orbit/php/8.5/bin/php\t8.5.1\tinstalled\n",
                    stderr: '',
                    durationMs: 1,
                );
            }
        });

        $payload = app(ToolPayloadMapper::class)->toArray($tool);
        $live = app(ToolShowLiveInspector::class)->inspect($tool);

        expect([...$payload, ...$live])->toMatchArray([
            'observed_state' => 'installed',
            'observed_version' => '8.5.1',
        ]);
    });

    it('filters registry lists to visible app hosts by node selector and app selector', function (): void {
        $firstNode = Node::factory()->create(['name' => 'app-contract-a', 'status' => 'active']);
        $secondNode = Node::factory()->create(['name' => 'app-contract-b', 'status' => 'active']);
        $inactiveNode = Node::factory()->create(['name' => 'app-contract-c', 'status' => 'inactive']);
        $unassignedNode = Node::factory()->create(['name' => 'app-contract-unassigned', 'status' => 'active']);
        $gatewayNode = createTestGatewayNode(['name' => 'gateway-contract']);
        assignToolContractAppHostRole($firstNode);
        assignToolContractAppHostRole($secondNode, 'app-prod', []);
        assignToolContractAppHostRole($inactiveNode);

        App::factory()->create([
            'name' => 'docs-contract',
            'domain' => 'docs-contract.test',
            'node_id' => $secondNode->id,
        ]);

        NodeTool::factory()->create(['name' => 'z-php', 'node_id' => $firstNode->id]);
        NodeTool::factory()->create(['name' => 'a-caddy', 'node_id' => $firstNode->id]);
        NodeTool::factory()->create(['name' => 'composer', 'node_id' => $secondNode->id]);
        NodeTool::factory()->create(['name' => 'hidden', 'node_id' => $inactiveNode->id]);
        NodeTool::factory()->create(['name' => 'unassigned', 'node_id' => $unassignedNode->id]);
        NodeTool::factory()->create(['name' => 'gateway-only', 'node_id' => $gatewayNode->id]);

        $registry = app(ToolRegistry::class);

        expect($registry->list()->map(fn (NodeTool $tool): string => "{$tool->node?->name}:{$tool->name}")->all())->toBe([
            'app-contract-a:a-caddy',
            'app-contract-a:z-php',
            'app-contract-b:composer',
        ])
            ->and($registry->list(node: 'app-contract-a')->pluck('name')->all())->toBe(['a-caddy', 'z-php'])
            ->and($registry->list(app: 'docs-contract')->pluck('name')->all())->toBe(['composer'])
            ->and($registry->list(app: 'docs-contract.test')->pluck('name')->all())->toBe(['composer']);
    });

    it('returns contract failures for invalid or conflicting registry filters', function (): void {
        $firstNode = Node::factory()->create(['name' => 'app-contract-a', 'status' => 'active']);
        $secondNode = Node::factory()->create(['name' => 'app-contract-b', 'status' => 'active']);
        $unassignedNode = Node::factory()->create(['name' => 'app-contract-unassigned', 'status' => 'active']);
        assignToolContractAppHostRole($firstNode);
        assignToolContractAppHostRole($secondNode, 'app-prod', []);

        App::factory()->create([
            'name' => 'docs-contract',
            'node_id' => $secondNode->id,
        ]);

        $registry = app(ToolRegistry::class);

        expect($registry->validateFilters(node: $firstNode->name))->toBeNull()
            ->and($registry->validateFilters(app: 'docs-contract'))->toBeNull();

        $invalidNode = $registry->validateFilters(node: 'missing-node');
        $unassignedNodeFailure = $registry->validateFilters(node: $unassignedNode->name);
        $invalidApp = $registry->validateFilters(app: 'missing-app');
        $conflictingApp = $registry->validateFilters(node: $firstNode->name, app: 'docs-contract');

        expect($invalidNode)->toBeInstanceOf(ToolRegistryFailure::class)
            ->and($invalidNode->code)->toBe('validation_failed')
            ->and($invalidNode->meta)->toMatchArray(['field' => 'node', 'value' => 'missing-node'])
            ->and($unassignedNodeFailure)->toBeInstanceOf(ToolRegistryFailure::class)
            ->and($unassignedNodeFailure->code)->toBe('validation_failed')
            ->and($unassignedNodeFailure->meta)->toMatchArray(['field' => 'node', 'value' => 'app-contract-unassigned'])
            ->and($invalidApp)->toBeInstanceOf(ToolRegistryFailure::class)
            ->and($invalidApp->code)->toBe('validation_failed')
            ->and($invalidApp->meta)->toMatchArray(['field' => 'app', 'value' => 'missing-app'])
            ->and($conflictingApp)->toBeInstanceOf(ToolRegistryFailure::class)
            ->and($conflictingApp->code)->toBe('validation_failed')
            ->and($conflictingApp->meta)->toMatchArray(['field' => 'app', 'value' => 'docs-contract']);
    });

    it('resolves shared tool targets by app slug domain combined selector and matching node rules', function (): void {
        $firstNode = Node::factory()->create(['name' => 'app-contract-a', 'status' => 'active', 'tld' => 'dev1']);
        $secondNode = Node::factory()->create(['name' => 'app-contract-b', 'status' => 'active', 'tld' => 'dev2']);
        assignToolContractAppHostRole($firstNode, settings: ['tld' => 'dev1']);
        assignToolContractAppHostRole($secondNode, 'app-prod', []);

        App::factory()->create([
            'name' => 'docs-contract',
            'domain' => 'docs-contract.example.test',
            'node_id' => $firstNode->id,
        ]);
        App::factory()->create([
            'name' => 'api-contract',
            'node_id' => $secondNode->id,
        ]);

        NodeTool::factory()->create(['name' => 'php', 'node_id' => $firstNode->id]);
        NodeTool::factory()->create(['name' => 'php', 'node_id' => $secondNode->id]);

        $registry = app(ToolRegistry::class);

        $slugResult = $registry->show('php', app: 'docs-contract');
        $domainResult = $registry->show('php', app: 'docs-contract.example.test');
        $combinedResult = $registry->show('php', app: 'docs-contract.dev1');
        $matchingResult = $registry->show('php', node: $firstNode->name, app: 'docs-contract');

        expect($slugResult)->toBeInstanceOf(NodeTool::class)
            ->and($slugResult->node?->name)->toBe($firstNode->name)
            ->and($domainResult)->toBeInstanceOf(NodeTool::class)
            ->and($domainResult->node?->name)->toBe($firstNode->name)
            ->and($combinedResult)->toBeInstanceOf(NodeTool::class)
            ->and($combinedResult->node?->name)->toBe($firstNode->name)
            ->and($matchingResult)->toBeInstanceOf(NodeTool::class)
            ->and($matchingResult->node?->name)->toBe($firstNode->name);
    });

    it('does not resolve service process versions as tool instances', function (): void {
        $node = Node::factory()->create(['name' => 'app-contract-default', 'status' => 'active']);
        assignToolContractAppHostRole($node);
        NodeTool::factory()->create(['name' => 'php', 'node_id' => $node->id]);

        $registry = app(ToolRegistry::class);

        $withInstance = $registry->show('php', node: $node->name, instance: '8.5');

        expect($registry->show('php', node: $node->name))->toBeInstanceOf(NodeTool::class)
            ->and($registry->show('php')->code)->toBe('validation_failed')
            ->and($withInstance)->toBeInstanceOf(ToolRegistryFailure::class)
            ->and($withInstance->code)->toBe('tool.not_found');
    });

    it('exposes the shared tool failure shape and allowed remote action metadata', function (): void {
        $failure = ToolRegistryFailure::remoteActionFailed(
            tool: 'caddy',
            node: 'app-contract-a',
            action: 'start',
            exitCode: 7,
            stderr: 'systemctl failed',
        );

        expect(array_keys(get_object_vars($failure)))->toBe([
            'code',
            'message',
            'meta',
        ])
            ->and($failure->code)->toBe('tool.remote_action_failed')
            ->and($failure->message)->toBe("Tool 'caddy' start failed on node 'app-contract-a'.")
            ->and($failure->meta)->toBe([
                'tool' => 'caddy',
                'node' => 'app-contract-a',
                'action' => 'start',
                'exit_code' => 7,
                'stderr' => 'systemctl failed',
            ]);
    });
});
