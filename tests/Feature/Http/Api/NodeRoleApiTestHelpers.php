<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use Illuminate\Testing\TestResponse;

function nodeRoleApiContractCallerIp(): string
{
    return '10.6.0.92';
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeRoleApiContractRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'target-1',
        'host' => '10.6.0.20',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.20',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createNodeRoleApiContractCaller(): Node
{
    return Node::query()->create(nodeRoleApiContractRow([
        'name' => 'api-caller',
        'host' => nodeRoleApiContractCallerIp(),
        'wireguard_address' => nodeRoleApiContractCallerIp(),
    ]));
}

function createNodeRoleApiContractGateway(): Node
{
    $gateway = Node::query()->create(nodeRoleApiContractRow([
        'name' => 'gateway-1',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
    ]));

    createNodeRoleApiContractAssignment($gateway, 'gateway');

    return $gateway;
}

/**
 * @param  list<string>  $permissions
 */
function grantNodeRoleApiContractAccess(Node $caller, Node $target, array $permissions): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $target->id,
        'permissions' => $permissions,
        'custom_permissions' => [],
    ]);
}

function createNodeRoleApiContractTarget(array $overrides = []): Node
{
    return Node::query()->create(nodeRoleApiContractRow($overrides));
}

/**
 * @param  array<string, mixed>  $settings
 */
function createNodeRoleApiContractAssignment(
    Node $node,
    string $role,
    string $status = 'active',
    array $settings = [],
    ?string $lastError = null,
): NodeRoleAssignment {
    return NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
        'last_error' => $lastError,
        'converged_at' => $status === 'active' ? now() : null,
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function postNodeRoleApiContractJson(string $uri, array $data): TestResponse
{
    return nodeRoleApiContractRequest('POST', $uri, $data);
}

function getNodeRoleApiContractJson(string $uri): TestResponse
{
    return nodeRoleApiContractRequest('GET', $uri, []);
}

/**
 * @param  array<string, mixed>  $data
 */
function nodeRoleApiContractRequest(string $method, string $uri, array $data): TestResponse
{
    /** @phpstan-ignore-next-line Pest resolves call() on the bound Laravel test case at runtime. */
    return test()->call(
        $method,
        $uri,
        $data,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => nodeRoleApiContractCallerIp(),
        ],
        $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
    );
}

/**
 * @param  list<string>  $permissions
 */
function setUpNodeRoleApiContractAccess(array $permissions): array
{
    $caller = createNodeRoleApiContractCaller();
    $gateway = createNodeRoleApiContractGateway();
    $target = createNodeRoleApiContractTarget();
    grantNodeRoleApiContractAccess($caller, $target, $permissions);

    return [$caller, $gateway, $target];
}
