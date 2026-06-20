<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\OperationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

const APP_STORE_STREAM_CALLER_WG_IP = '10.6.0.177';

function createAppStoreStreamCallerNode(): Node
{
    return Node::factory()->create([
        'name' => 'stream-caller',
        'host' => APP_STORE_STREAM_CALLER_WG_IP,
        'wireguard_address' => APP_STORE_STREAM_CALLER_WG_IP,
    ]);
}

function assignAppStoreStreamRole(Node $node, string $role, array $settings = []): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

function grantAppStoreStreamAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['app:new'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('streams app creation from an operation_run source', function (): void {
    $caller = createAppStoreStreamCallerNode();
    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);
    assignAppStoreStreamRole($targetNode, 'app-dev', ['tld' => 'test']);
    grantAppStoreStreamAccess($caller, $targetNode);

    app()->instance(RemoteShell::class, new AppStoreStreamRecordingRemoteShell);
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

    $response = $this->call('POST', '/api/apps', [
        'name' => 'docs',
        'node' => 'app-1',
        'root' => 'public',
        'php_version' => '8.5',
    ], [], [], [
        'HTTP_ACCEPT' => 'text/event-stream',
        'REMOTE_ADDR' => APP_STORE_STREAM_CALLER_WG_IP,
    ]);

    $response->assertOk();

    $content = $response->streamedContent();
    $operationRun = OperationRun::query()->where('operation_type', 'app:new')->firstOrFail();

    expect($content)->toContain('event: tree')
        ->and($content)->toContain('Record operation state')
        ->and($content)->toContain('Create app source')
        ->and($content)->toContain('event: complete')
        ->and($content)->toContain($operationRun->id)
        ->and($operationRun->status->value)->toBe('succeeded')
        ->and($operationRun->caller_node_id)->toBe($caller->id)
        ->and($operationRun->target_node_id)->toBe($targetNode->id)
        ->and($operationRun->result['app']['name'])->toBe('docs');
});

final class AppStoreStreamRecordingRemoteShell implements RemoteShell
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
