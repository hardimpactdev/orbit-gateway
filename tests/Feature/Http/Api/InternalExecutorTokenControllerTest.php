<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Operations\OperationTokenIntrospector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

const INTERNAL_EXECUTOR_TOKEN_CALLER_WG_IP = '10.6.0.50';

describe('InternalExecutorTokenController', function (): void {
    beforeEach(function (): void {
        config()->set('orbit.trust_wireguard_proxy_header', true);
        config()->set('app.key', 'gateway-app-key');
        config()->set('orbit.operation_token_ttl_seconds', 120);

        app()->forgetInstance(OperationTokenFactory::class);
        app()->forgetInstance(OperationTokenIntrospector::class);
    });

    it('returns a successful introspection result for a valid token', function (): void {
        internalExecutorCallerNode();

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-123',
            targetNode: 'app-dev',
            command: 'internal:executor:verify',
        );

        internalExecutorVerifyTokenRequest([
            'operation_token' => $operationToken->toString(),
            'command' => 'internal:executor:verify',
        ])->assertOk()
            ->assertJsonPath('success.data.allowed', true)
            ->assertJsonPath('success.data.reason', null)
            ->assertJsonPath('success.data.operation_id', 'operation-123');
    });

    it('returns invalid_token for malformed tokens', function (): void {
        internalExecutorCallerNode();

        internalExecutorVerifyTokenRequest([
            'operation_token' => 'not-a-token',
            'command' => 'internal:executor:verify',
        ])->assertOk()
            ->assertJsonPath('success.data.allowed', false)
            ->assertJsonPath('success.data.reason', 'invalid_token')
            ->assertJsonPath('success.data.operation_id', null);
    });

    it('returns target_node_mismatch for tokens minted for another node', function (): void {
        internalExecutorCallerNode();

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-123',
            targetNode: 'app-prod',
            command: 'internal:executor:verify',
        );

        internalExecutorVerifyTokenRequest([
            'operation_token' => $operationToken->toString(),
            'command' => 'internal:executor:verify',
        ])->assertOk()
            ->assertJsonPath('success.data.allowed', false)
            ->assertJsonPath('success.data.reason', 'target_node_mismatch')
            ->assertJsonPath('success.data.operation_id', 'operation-123');
    });

    it('returns command_mismatch for a different internal command', function (): void {
        internalExecutorCallerNode();

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-123',
            targetNode: 'app-dev',
            command: 'internal:executor:status',
        );

        internalExecutorVerifyTokenRequest([
            'operation_token' => $operationToken->toString(),
            'command' => 'internal:executor:verify',
        ])->assertOk()
            ->assertJsonPath('success.data.allowed', false)
            ->assertJsonPath('success.data.reason', 'command_mismatch')
            ->assertJsonPath('success.data.operation_id', 'operation-123');
    });

    it('rejects non-internal commands at validation time', function (): void {
        internalExecutorCallerNode();

        internalExecutorVerifyTokenRequest([
            'operation_token' => 'not-a-token',
            'command' => 'deploy:run',
        ])->assertUnprocessable()
            ->assertInvalid(['command']);
    });

    it('rejects unknown peers before reaching the controller', function (): void {
        internalExecutorVerifyTokenRequest([
            'operation_token' => 'not-a-token',
            'command' => 'internal:executor:verify',
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });

    it('rejects inactive peers before reaching the controller', function (): void {
        internalExecutorCallerNode([
            'status' => 'inactive',
        ]);

        internalExecutorVerifyTokenRequest([
            'operation_token' => 'not-a-token',
            'command' => 'internal:executor:verify',
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});

function internalExecutorCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'app-dev',
        'status' => 'active',
        'wireguard_address' => INTERNAL_EXECUTOR_TOKEN_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $payload
 */
function internalExecutorVerifyTokenRequest(array $payload)
{
    /** @var TestCase $test */
    // @phpstan-ignore-next-line varTag.nativeType
    $test = test();

    return $test
        ->withHeader('X-Orbit-WireGuard-Ip', INTERNAL_EXECUTOR_TOKEN_CALLER_WG_IP)
        ->postJson('/api/internal-executor/token/verify', $payload);
}
