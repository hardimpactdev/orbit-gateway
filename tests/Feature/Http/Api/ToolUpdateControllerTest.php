<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const TOOL_UPDATE_API_CALLER_WG_IP = '10.6.0.93';

function createToolUpdateApiCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'tool-update-api-caller',
        'host' => TOOL_UPDATE_API_CALLER_WG_IP,
        'wireguard_address' => TOOL_UPDATE_API_CALLER_WG_IP,
    ], $overrides));
}

function assignToolUpdateApiRole(Node $node, string $role): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
    ]);
}

function grantToolUpdateApiAccess(Node $caller, Node $node): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $node->id,
        'permissions' => json_encode(['tool:update'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('updates host capability expected versions without service instance fields', function (): void {
    $caller = createToolUpdateApiCallerNode();
    $node = Node::factory()->create(['name' => 'app-update-api-1', 'status' => 'active']);
    assignToolUpdateApiRole($node, 'app-dev');
    grantToolUpdateApiAccess($caller, $node);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_version' => '8.4',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call('POST', '/api/tools/php-cli/update', [
        'node' => 'app-update-api-1',
        'version' => '8.5',
    ], [], [], ['REMOTE_ADDR' => TOOL_UPDATE_API_CALLER_WG_IP]);

    $response->assertOk()
        ->assertJsonPath('success.data.tool.name', 'php-cli')
        ->assertJsonPath('success.data.tool.version', '8.5');

    $tool = NodeTool::query()->where('name', 'php-cli')->firstOrFail();

    expect($tool->expected_version)->toBe('8.5')
        ->and($tool->getAttributes())->not->toHaveKeys(['instance_key', 'version_family', 'runtime', 'runtime_config'])
        ->and($shell->scripts)->toHaveCount(1);
});

it('does not update database and cache services through tool updates', function (string $tool): void {
    $caller = createToolUpdateApiCallerNode();
    $node = Node::factory()->create(['name' => 'app-update-api-1', 'status' => 'active']);
    assignToolUpdateApiRole($node, 'app-dev');
    grantToolUpdateApiAccess($caller, $node);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call('POST', "/api/tools/{$tool}/update", [
        'node' => 'app-update-api-1',
        'version' => '8',
    ], [], [], ['REMOTE_ADDR' => TOOL_UPDATE_API_CALLER_WG_IP]);

    $response->assertStatus(400)
        ->assertJsonPath('error.code', 'tool.unsupported_action')
        ->assertJsonPath('error.meta.tool', $tool)
        ->assertJsonPath('error.meta.action', 'update');

    expect(NodeTool::query()->count())->toBe(0)
        ->and($shell->scripts)->toBe([]);
})->with([
    'mysql',
    'postgres',
    'redis',
]);

it('treats service-style instance selectors as missing tool rows', function (): void {
    $caller = createToolUpdateApiCallerNode();
    $node = Node::factory()->create(['name' => 'app-update-api-1', 'status' => 'active']);
    assignToolUpdateApiRole($node, 'app-dev');
    grantToolUpdateApiAccess($caller, $node);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
    ]);
    $shell = new ToolUpdateApiRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call('POST', '/api/tools/php-cli/update', [
        'node' => 'app-update-api-1',
        'instance' => 'php-cli:8.5',
    ], [], [], ['REMOTE_ADDR' => TOOL_UPDATE_API_CALLER_WG_IP]);

    $response->assertNotFound()
        ->assertJsonPath('error.code', 'tool.not_found');

    expect($shell->scripts)->toBe([]);
});

final class ToolUpdateApiRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
