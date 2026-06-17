<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/NodeRoleApiTestHelpers.php';

describe('NodeRoleListController', function (): void {
    it('lists role assignments for an authorized caller', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:read']);
        createNodeRoleApiContractAssignment($target, 'app-dev', 'error', ['tld' => 'test'], 'DNS failed.');
        createNodeRoleApiContractAssignment($target, 'database');

        $response = getNodeRoleApiContractJson('/api/nodes/target-1/roles');

        $response->assertOk()
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.roles.0.role', 'app-dev')
            ->assertJsonPath('success.data.roles.0.status', 'error')
            ->assertJsonPath('success.data.roles.0.settings.tld', 'test')
            ->assertJsonPath('success.data.roles.0.last_error', 'DNS failed.')
            ->assertJsonPath('success.data.roles.1.role', 'database')
            ->assertJsonPath('success.data.roles.1.status', 'active')
            ->assertJsonPath('success.data.roles.1.settings', []);
    });

    it('returns the authorized caller response shape for empty role lists', function (): void {
        setUpNodeRoleApiContractAccess(['role:read']);

        $response = getNodeRoleApiContractJson('/api/nodes/target-1/roles');

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'node' => 'target-1',
                        'roles' => [],
                    ],
                ],
            ]);
    });
});
