<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleStatus;
use App\Models\NodeAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/NodeRoleApiTestHelpers.php';

describe('NodeRoleAddController', function (): void {
    it('adds a role for an authorized caller and returns the assignment payload', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'database',
            'settings' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.assignment.role', 'database')
            ->assertJsonPath('success.data.assignment.status', 'active')
            ->assertJsonPath('success.data.assignment.settings', [])
            ->assertJsonPath('success.data.assignment.last_error', null);

        expect($target->roleAssignments()->where('role', 'database')->where('status', NodeRoleStatus::Active->value)->exists())->toBeTrue();
    });

    it('rejects gateway role additions before side effects', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'gateway',
            'settings' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', "Role 'gateway' is gateway-coupled and cannot be assigned independently.")
            ->assertJsonPath('error.meta.field', 'role')
            ->assertJsonPath('error.meta.role', 'gateway')
            ->assertJsonMissingPath('success');

        expect($target->roleAssignments()->where('role', 'gateway')->exists())->toBeFalse();
    });

    it('returns the authorized caller response shape', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'app-dev',
            'settings' => ['tld' => 'test'],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success' => [
                    'data' => [
                        'node',
                        'assignment' => [
                            'role',
                            'status',
                            'settings',
                            'last_error',
                            'converged_at',
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.assignment.settings.tld', 'test');

        $selfGrant = NodeAccess::query()
            ->where('consumer_node_id', $target->id)
            ->where('serving_node_id', $target->id)
            ->first();

        expect($selfGrant?->permissions)->toBe(['workspace:setup'])
            ->and($selfGrant?->custom_permissions)->toBe([]);
    });
});
