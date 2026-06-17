<?php

declare(strict_types=1);

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Middleware\RequireGrantPermission;
use App\Http\Middleware\WireGuardIdentity;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

const REQUIRE_GRANT_CALLER_WG_IP = '10.6.30.10';
const REQUIRE_GRANT_GATEWAY_WG_IP = '10.6.30.1';

final class RequireGrantPermissionOpenController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['success' => ['data' => ['ok' => true]]]);
    }
}

#[RequiresPermission('tool:read', servingNode: ServingNode::Target)]
final class RequireGrantPermissionInvokableController
{
    public function __invoke(string $name): JsonResponse
    {
        return response()->json(['success' => ['data' => ['target' => $name]]]);
    }
}

final class RequireGrantPermissionMethodController
{
    #[RequiresPermission('tool:read', servingNode: ServingNode::Target)]
    public function show(string $name): JsonResponse
    {
        return response()->json(['success' => ['data' => ['target' => $name]]]);
    }
}

function requireGrantNode(string $name, ?string $wireguardAddress = null): Node
{
    return Node::factory()->create([
        'name' => $name,
        'status' => 'active',
        'wireguard_address' => $wireguardAddress,
    ]);
}

function requireGrantGatewayNode(): Node
{
    $gateway = Node::factory()->create([
        'name' => 'gateway-1',
        'status' => 'active',
        'wireguard_address' => REQUIRE_GRANT_GATEWAY_WG_IP,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $gateway;
}

/**
 * @param  list<string>  $permissions
 */
function requireGrantAccess(Node $consumer, Node $serving, array $permissions): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $serving->id,
        'permissions' => $permissions,
    ]);
}

function requireGrantGet(string $uri, string $wireguardAddress = REQUIRE_GRANT_CALLER_WG_IP): TestResponse
{
    /** @var TestCase $test */
    // @phpstan-ignore-next-line varTag.nativeType
    $test = test();

    return $test->call(
        'GET',
        $uri,
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => $wireguardAddress,
        ],
    );
}

describe('RequireGrantPermission middleware', function (): void {
    beforeEach(function (): void {
        Route::middleware([WireGuardIdentity::class, RequireGrantPermission::class])
            ->get('/_test/require-grant/open', RequireGrantPermissionOpenController::class);

        Route::middleware([WireGuardIdentity::class, RequireGrantPermission::class])
            ->get('/_test/require-grant/protected/{name}', RequireGrantPermissionInvokableController::class);

        Route::middleware([WireGuardIdentity::class, RequireGrantPermission::class])
            ->get('/_test/require-grant/method/{name}', [RequireGrantPermissionMethodController::class, 'show']);

        Route::middleware(RequireGrantPermission::class)
            ->get('/_test/require-grant/no-peer/{name}', RequireGrantPermissionInvokableController::class);
    });

    it('passes through routes without permission attributes', function (): void {
        requireGrantNode('caller-1', REQUIRE_GRANT_CALLER_WG_IP);

        requireGrantGet('/_test/require-grant/open')
            ->assertOk()
            ->assertJsonPath('success.data.ok', true);
    });

    it('allows class-level permission attributes with direct grants', function (): void {
        $caller = requireGrantNode('caller-1', REQUIRE_GRANT_CALLER_WG_IP);
        $target = requireGrantNode('target-1');

        requireGrantAccess($caller, $target, ['tool:read']);

        requireGrantGet('/_test/require-grant/protected/target-1')
            ->assertOk()
            ->assertJsonPath('success.data.target', 'target-1');
    });

    it('denies class-level permission attributes without a covering grant', function (): void {
        requireGrantNode('caller-1', REQUIRE_GRANT_CALLER_WG_IP);
        requireGrantNode('target-1');

        requireGrantGet('/_test/require-grant/protected/target-1')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'tool:read')
            ->assertJsonPath('error.meta.serving_node', 'target-1');
    });

    it('allows gateway callers without grants', function (): void {
        requireGrantGatewayNode();
        requireGrantNode('target-1');

        requireGrantGet('/_test/require-grant/protected/target-1', REQUIRE_GRANT_GATEWAY_WG_IP)
            ->assertOk()
            ->assertJsonPath('success.data.target', 'target-1');
    });

    it('allows gateway-admin grants without direct target grants', function (): void {
        $caller = requireGrantNode('caller-1', REQUIRE_GRANT_CALLER_WG_IP);
        $gateway = requireGrantGatewayNode();

        requireGrantNode('target-1');
        requireGrantAccess($caller, $gateway, ['*']);

        requireGrantGet('/_test/require-grant/protected/target-1')
            ->assertOk()
            ->assertJsonPath('success.data.target', 'target-1');
    });

    it('reads permission attributes from controller methods', function (): void {
        $caller = requireGrantNode('caller-1', REQUIRE_GRANT_CALLER_WG_IP);
        $target = requireGrantNode('target-1');

        requireGrantAccess($caller, $target, ['tool:read']);

        requireGrantGet('/_test/require-grant/method/target-1')
            ->assertOk()
            ->assertJsonPath('success.data.target', 'target-1');
    });

    it('passes unresolved target resources through to the controller', function (): void {
        requireGrantNode('caller-1', REQUIRE_GRANT_CALLER_WG_IP);

        requireGrantGet('/_test/require-grant/protected/missing')
            ->assertOk()
            ->assertJsonPath('success.data.target', 'missing');
    });

    it('denies protected routes when the caller node is unavailable', function (): void {
        requireGrantNode('target-1');

        requireGrantGet('/_test/require-grant/no-peer/target-1')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
