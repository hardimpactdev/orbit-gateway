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

const TOOL_INSTALL_API_CALLER_WG_IP = '10.6.0.98';

function createToolInstallApiCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'tool-install-api-caller',
        'host' => TOOL_INSTALL_API_CALLER_WG_IP,
        'wireguard_address' => TOOL_INSTALL_API_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantToolInstallApiAccess(Node $caller, Node $appNode, array $permissions = ['*']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignToolInstallApiRole(Node $node, string $role, string $status = 'active'): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
    ]);
}

describe('ToolInstallController', function (): void {
    it('allows gateway callers to install host capabilities on visible tool nodes', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create([
            'name' => 'app-install-api-1',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/php-cli/install', [
            'node' => 'app-install-api-1',
            'version' => '8.5',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'php-cli')
            ->assertJsonPath('success.data.tool.node', 'app-install-api-1')
            ->assertJsonPath('success.data.tool.state', 'installed')
            ->assertJsonPath('success.data.tool.version', '8.5');

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'php-cli')
            ->firstOrFail();

        expect($tool->expected_version)->toBe('8.5')
            ->and($tool->getAttributes())->not->toHaveKeys(['instance_key', 'version_family', 'runtime', 'runtime_config'])
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('configures the related singleton process by default when installing a service tool', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-oc-1', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        assignToolInstallApiRole($node, 'app-dev');
        app()->instance(RemoteShell::class, new ToolInstallApiRecordingShell);

        $response = $this->call('POST', '/api/tools/opencode-server/install', [
            'node' => 'app-oc-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'opencode-server')
            ->assertJsonPath('success.data.tool.process.name', 'opencode-server')
            ->assertJsonPath('success.data.tool.process.runtime', 'systemd')
            ->assertJsonPath('success.data.tool.process.tool', 'opencode')
            ->assertJsonPath('success.data.tool.process.action', 'configured');

        $process = DB::table('processes')
            ->where('node_id', $node->id)
            ->where('name', 'opencode-server')
            ->first();

        expect($process)->not->toBeNull()
            ->and($process->command)->toBe('opencode serve -a')
            ->and($process->runtime)->toBe('systemd')
            ->and($process->tool)->toBe('opencode');
    });

    it('skips process configuration when with_process is false', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-oc-2', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        assignToolInstallApiRole($node, 'app-dev');
        app()->instance(RemoteShell::class, new ToolInstallApiRecordingShell);

        $response = $this->call('POST', '/api/tools/opencode-server/install', [
            'node' => 'app-oc-2',
            'with_process' => false,
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.process', null);

        expect(DB::table('processes')->where('node_id', $node->id)->where('name', 'opencode-server')->exists())->toBeFalse();
    });

    it('converges the related process idempotently on re-install', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-oc-3', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        assignToolInstallApiRole($node, 'app-dev');
        app()->instance(RemoteShell::class, new ToolInstallApiRecordingShell);

        $payload = ['node' => 'app-oc-3'];
        $headers = ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP];

        $this->call('POST', '/api/tools/opencode-server/install', $payload, [], [], $headers)->assertOk();

        $response = $this->call('POST', '/api/tools/opencode-server/install', $payload, [], [], $headers);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.process.action', 'converged');

        expect(DB::table('processes')->where('node_id', $node->id)->where('name', 'opencode-server')->count())->toBe(1);
    });

    it('configures the related singleton process by default when installing polyscope server', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-ps-1', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        assignToolInstallApiRole($node, 'app-dev');
        app()->instance(RemoteShell::class, new ToolInstallApiRecordingShell);

        $response = $this->call('POST', '/api/tools/polyscope-server/install', [
            'node' => 'app-ps-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'polyscope-server')
            ->assertJsonPath('success.data.tool.process.name', 'polyscope-server')
            ->assertJsonPath('success.data.tool.process.runtime', 'systemd')
            ->assertJsonPath('success.data.tool.process.tool', 'polyscope')
            ->assertJsonPath('success.data.tool.process.action', 'configured');

        $process = DB::table('processes')
            ->where('node_id', $node->id)
            ->where('name', 'polyscope-server')
            ->first();

        expect($process)->not->toBeNull()
            ->and($process->command)->toBe('polyscope-server')
            ->and($process->runtime)->toBe('systemd')
            ->and($process->tool)->toBe('polyscope');
    });

    it('skips polyscope server process configuration when with_process is false', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-ps-2', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        assignToolInstallApiRole($node, 'app-dev');
        app()->instance(RemoteShell::class, new ToolInstallApiRecordingShell);

        $response = $this->call('POST', '/api/tools/polyscope-server/install', [
            'node' => 'app-ps-2',
            'with_process' => false,
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.process', null);

        expect(DB::table('processes')->where('node_id', $node->id)->where('name', 'polyscope-server')->exists())->toBeFalse();
    });

    it('converges the polyscope server related process idempotently on re-install', function (): void {
        $caller = createToolInstallApiCallerNode();
        assignToolInstallApiRole($caller, 'gateway');
        $node = Node::factory()->create(['name' => 'app-ps-3', 'status' => 'active', 'platform' => 'ubuntu_24-04']);
        assignToolInstallApiRole($node, 'app-dev');
        app()->instance(RemoteShell::class, new ToolInstallApiRecordingShell);

        $payload = ['node' => 'app-ps-3'];
        $headers = ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP];

        $this->call('POST', '/api/tools/polyscope-server/install', $payload, [], [], $headers)->assertOk();

        $response = $this->call('POST', '/api/tools/polyscope-server/install', $payload, [], [], $headers);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.process.action', 'converged');

        expect(DB::table('processes')->where('node_id', $node->id)->where('name', 'polyscope-server')->count())->toBe(1);
    });

    it('does not treat an unassigned caller as gateway tool authority', function (): void {
        createToolInstallApiCallerNode([
            'name' => 'plain-gateway-install-api-caller',
        ]);
        $node = Node::factory()->create([
            'name' => 'app-install-api-1',
            'status' => 'active',
        ]);
        assignToolInstallApiRole($node, 'app-dev');
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/php-cli/install', [
            'node' => 'app-install-api-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects invalid status before row writes or remote shell actions', function (): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/php-cli/install', [
            'node' => 'app-install-api-1',
            'status' => 'foo',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'status')
            ->assertJsonPath('error.meta.value', 'foo')
            ->assertJsonPath('error.meta.reason', 'unsupported_value');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects runtime and instance options for tool installs before side effects', function (array $payload, string $field): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/php-cli/install', [
            'node' => 'app-install-api-1',
            ...$payload,
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field)
            ->assertJsonPath('error.meta.reason', 'unsupported_field');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    })->with([
        'runtime' => [['runtime' => 'docker'], 'runtime'],
        'instance' => [['instance' => 'php-cli:8.5'], 'instance'],
    ]);

    it('rejects database and cache services as tool installs before side effects', function (string $tool): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', "/api/tools/{$tool}/install", [
            'node' => 'app-install-api-1',
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'tool.unsupported_action')
            ->assertJsonPath('error.meta.tool', $tool)
            ->assertJsonPath('error.meta.action', 'install');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    })->with([
        'mysql',
        'postgres',
        'redis',
    ]);

    it('rejects update-only version intent before side effects', function (array $payload): void {
        $caller = createToolInstallApiCallerNode();
        $node = Node::factory()->create(['name' => 'app-install-api-1', 'status' => 'active']);
        assignToolInstallApiRole($node, 'app-dev');
        grantToolInstallApiAccess($caller, $node);
        $shell = new ToolInstallApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/tools/php-cli/install', [
            'node' => 'app-install-api-1',
            ...$payload,
        ], [], [], ['REMOTE_ADDR' => TOOL_INSTALL_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'unsupported_field');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    })->with([
        'expected_version' => [['expected_version' => '1.0.0']],
        'expected-version' => [['expected-version' => '1.0.0']],
    ]);
});

final class ToolInstallApiRecordingShell implements RemoteShell
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
