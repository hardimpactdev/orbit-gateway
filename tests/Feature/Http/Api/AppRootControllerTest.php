<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_ROOT_CALLER_WG_IP = '10.6.0.79';

function createAppRootCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_ROOT_CALLER_WG_IP,
        'wireguard_address' => APP_ROOT_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantAppRootAccess(Node $caller, Node $appNode, array $permissions = ['app:root']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('AppRootController', function (): void {
    it('updates app root for authorized callers', function (): void {
        Node::factory()->create([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRootCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        grantAppRootAccess($caller, $targetNode);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $targetNode->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
        ]);

        app()->instance(RemoteShell::class, new AppRootApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('POST', '/api/apps/docs/root', [
            'root' => 'web',
        ], [], [], ['REMOTE_ADDR' => APP_ROOT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app.root', 'web')
            ->assertJsonPath('success.data.result.changed', true)
            ->assertJsonPath('success.meta.node', 'app-1');

        expect(App::query()->where('name', 'docs')->value('document_root'))->toBe('web');
    });

    it('rejects root updates when the caller lacks app:root on the app node', function (): void {
        Node::factory()->create([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRootCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantAppRootAccess($caller, $targetNode, ['app:read']);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $targetNode->id,
        ]);

        app()->instance(RemoteShell::class, new AppRootApiSequencedRemoteShell([]));

        $response = $this->call('POST', '/api/apps/docs/root', [
            'root' => 'web',
        ], [], [], ['REMOTE_ADDR' => APP_ROOT_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:root')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(App::query()->where('name', 'docs')->value('document_root'))->toBe('public');
    });
});

final class AppRootApiSequencedRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
