<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

const APP_STORE_ROLE_CALLER_WG_IP = '10.6.0.177';

function createAppStoreRoleCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_STORE_ROLE_CALLER_WG_IP,
        'wireguard_address' => APP_STORE_ROLE_CALLER_WG_IP,
    ], $overrides));
}

function createEligibleAppStoreTargetNode(string $name = 'app-1', array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => $name,
        'tld' => 'test',
        'status' => 'active',
    ], $overrides));
}

function assignRole(Node $node, string $role, string $status = 'active', array $settings = []): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
    ]);
}

/**
 * @param  list<string>  $permissions
 */
function grantAppStoreRoleAccess(Node $caller, Node $target, array $permissions = ['app:new']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $target->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    app()->instance(RemoteShell::class, new AppStoreNodeRoleTestRemoteShell);
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

describe('AppStore node role eligibility', function (): void {
    it('accepts a node with active app-dev for development app creation', function (): void {
        $caller = createAppStoreRoleCallerNode();
        $target = createEligibleAppStoreTargetNode();
        assignRole($target, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreRoleAccess($caller, $target);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => $target->name,
            'root' => 'public',
            'php_version' => '8.5',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_ROLE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app.node', $target->name);

        expect(App::query()->where('name', 'docs')->exists())->toBeTrue()
            ->and(App::query()->where('name', 'docs')->value('environment'))->toBe('development');
    });

    it('accepts a node with active app-prod for production app creation', function (): void {
        $caller = createAppStoreRoleCallerNode();
        $router = createEligibleAppStoreTargetNode('router-1', [
            'tld' => null,
            'wireguard_address' => '10.6.0.2',
            'host' => '10.6.0.2',
        ]);
        $ingress = createEligibleAppStoreTargetNode('edge-1', [
            'tld' => null,
            'wireguard_address' => '10.6.0.7',
            'host' => '10.6.0.7',
        ]);
        $target = createEligibleAppStoreTargetNode(overrides: [
            'tld' => null,
            'wireguard_address' => '10.6.0.5',
            'host' => '10.6.0.5',
        ]);
        assignRole($router, 'router');
        assignRole($ingress, 'ingress');
        assignRole($target, 'app-prod', settings: ['ingress_node_id' => $ingress->id]);
        grantAppStoreRoleAccess($caller, $target);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => $target->name,
            'domain' => 'docs.example.com',
            'root' => 'public',
            'php_version' => '8.5',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_ROLE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app.url', 'https://docs.example.com');

        expect(App::query()->where('name', 'docs')->value('environment'))->toBe('production');
    });

    it('rejects a node with only active database role', function (): void {
        $caller = createAppStoreRoleCallerNode();
        $target = createEligibleAppStoreTargetNode();
        assignRole($target, 'database');
        grantAppStoreRoleAccess($caller, $target);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => $target->name,
        ], [], [], ['REMOTE_ADDR' => APP_STORE_ROLE_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'app.ineligible_node');

        expect(App::query()->count())->toBe(0);
    });

    it('rejects nodes where the relevant app host role is not active', function (string $status): void {
        $caller = createAppStoreRoleCallerNode();
        $target = createEligibleAppStoreTargetNode();
        assignRole($target, 'app-dev', $status, ['tld' => 'test']);
        grantAppStoreRoleAccess($caller, $target);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => $target->name,
        ], [], [], ['REMOTE_ADDR' => APP_STORE_ROLE_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'app.ineligible_node');

        expect(App::query()->count())->toBe(0);
    })->with(['pending', 'error', 'removing']);
});

final class AppStoreNodeRoleTestRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
