<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\StartsRemoteShellProcesses;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

const UPDATE_ALL_CALLER_WG_IP = '10.6.0.99';

function createUpdateAllAppHostNode(array $attributes, string $role = 'app-dev'): Node
{
    $node = Node::factory()->create([
        'status' => 'active',
        ...$attributes]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $role === 'app-dev' ? ['tld' => 'test'] : []]);

    return $node;
}

beforeEach(function (): void {
    createTestGatewayNode([
        'name' => 'gateway',
        'host' => 'gateway',
        'orbit_path' => '/home/gateway/orbit',
        'status' => 'active',
        'wireguard_address' => UPDATE_ALL_CALLER_WG_IP]);
});

it('updates local checkout and returns updates array for gateway caller', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0)]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllControllerRemoteShell);

    $response = $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP]);

    $response->assertOk();
    $response->assertJsonPath('success.data.updates.0.target', 'gateway');
    $response->assertJsonPath('success.data.updates.0.node', 'gateway');
    $response->assertJsonPath('success.data.updates.0.role', 'gateway');
    $response->assertJsonPath('success.data.updates.0.status', 'completed');
    $response->assertJsonPath('success.meta.summary.total', 1);
    $response->assertJsonPath('success.meta.summary.completed', 1);
    $response->assertJsonPath('success.meta.summary.failed', 0);
});

it('returns local_update_failed when git pull fails', function (): void {
    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'merge conflict',
            exitCode: 1,
        )]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllControllerRemoteShell);

    $response = $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP]);

    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'local_update_failed');
    $response->assertJsonPath('error.data.output', 'merge conflict');
    $response->assertJsonPath('error.meta.failed_step', 'local_checkout');
});

it('includes active app host nodes in updates and uses RemoteShell', function (): void {
    createUpdateAllAppHostNode([
        'name' => 'beast',
        'host' => 'beast',
        'orbit_path' => '/home/nckrtl/orbit']);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0)]);
    Process::preventStrayProcesses();

    $logPath = tempnam(sys_get_temp_dir(), 'orbit-update-all-shell-');

    if ($logPath === false) {
        $this->fail('Could not create update shell log.');
    }

    try {
        app()->instance(RemoteShell::class, new UpdateAllControllerTimedRemoteShell($logPath, pullDelayMicroseconds: 0));

        $response = $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP]);

        $response->assertOk();
        $response->assertJsonPath('success.data.updates.1.target', 'beast');
        $response->assertJsonPath('success.data.updates.1.status', 'completed');

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($lines)->toBeArray();

        $events = array_map(
            fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );

        expect(array_column($events, 'node'))->toBe([
            'beast',
            'beast',
            'beast']);
    } finally {
        @unlink($logPath);
    }
});

it('updates app nodes in parallel after gateway checkout succeeds', function (): void {
    createUpdateAllAppHostNode([
        'name' => 'beast',
        'host' => 'beast',
        'orbit_path' => '/home/nckrtl/orbit']);
    createUpdateAllAppHostNode([
        'name' => 'sidecar',
        'host' => 'sidecar',
        'orbit_path' => '/home/nckrtl/orbit'], 'app-prod');

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0)]);
    Process::preventStrayProcesses();

    $logPath = tempnam(sys_get_temp_dir(), 'orbit-update-all-parallel-');

    if ($logPath === false) {
        $this->fail('Could not create update timing log.');
    }

    try {
        app()->instance(RemoteShell::class, new UpdateAllControllerTimedRemoteShell($logPath));

        $response = $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP]);

        $response->assertOk();
        $response->assertJsonPath('success.data.updates.1.target', 'beast');
        $response->assertJsonPath('success.data.updates.2.target', 'sidecar');

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($lines)->toBeArray();

        $events = array_map(
            fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );
        $pullEvents = array_values(array_filter(
            $events,
            fn (array $event): bool => ($event['script'] ?? null) === 'git pull --ff-only',
        ));

        expect($pullEvents)->toHaveCount(2);

        $latestStart = max(array_column($pullEvents, 'started_at'));
        $earliestEnd = min(array_column($pullEvents, 'ended_at'));

        expect($latestStart)->toBeLessThan($earliestEnd);
    } finally {
        @unlink($logPath);
    }
});

it('starts app node updates concurrently in the streamed gateway path without pcntl workers', function (): void {
    createUpdateAllAppHostNode([
        'name' => 'beast',
        'host' => 'beast',
        'orbit_path' => '/home/nckrtl/orbit']);
    createUpdateAllAppHostNode([
        'name' => 'main1',
        'host' => 'main1',
        'orbit_path' => '/home/nckrtl/orbit'], 'app-prod');

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0)]);
    Process::preventStrayProcesses();

    $logPath = tempnam(sys_get_temp_dir(), 'orbit-update-all-stream-async-');

    if ($logPath === false) {
        $this->fail('Could not create update stream timing log.');
    }

    try {
        app()->instance(RemoteShell::class, new UpdateAllControllerAsyncOnlyRemoteShell($logPath));

        $response = $this->call(
            'POST',
            '/api/update/all',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'text/event-stream',
                'REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP],
        );

        $response->assertOk();
        $content = $response->streamedContent();

        expect($content)->toContain('event: complete');

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($lines)->toBeArray();

        $events = array_map(
            fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );

        $pullStarts = array_values(array_filter(
            $events,
            fn (array $event): bool => ($event['script'] ?? null) === 'git pull --ff-only',
        ));
        $firstInstallIndex = array_find_key(
            $events,
            fn (array $event): bool => str_contains((string) ($event['script'] ?? ''), 'install --no-interaction'),
        );

        expect(array_column($pullStarts, 'node'))->toBe(['beast', 'main1']);
        expect($firstInstallIndex)->not->toBeNull();

        $mainPullIndex = array_find_key(
            $events,
            fn (array $event): bool => ($event['node'] ?? null) === 'main1'
                && ($event['script'] ?? null) === 'git pull --ff-only',
        );

        expect($mainPullIndex)->not->toBeNull();
        expect($mainPullIndex)->toBeLessThan($firstInstallIndex);
    } finally {
        @unlink($logPath);
    }
});

it('excludes control nodes from remote updates', function (): void {
    Node::factory()->create([
        'name' => 'mini',
        'host' => 'mini',
        'orbit_path' => '/Users/nckrtl/orbit',
        'status' => 'active']);
    Node::factory()->create([
        'name' => 'legacy-app-only',
        'host' => 'legacy-app-only',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active']);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0)]);
    Process::preventStrayProcesses();

    $shell = new UpdateAllControllerRemoteShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP]);

    $response->assertOk();
    $response->assertJsonPath('success.data.updates.0.target', 'gateway');

    expect($shell->nodes)->toHaveCount(0);
});

it('reports remote_update_failed when an app host node fails', function (): void {
    createUpdateAllAppHostNode([
        'name' => 'beast',
        'host' => 'beast',
        'orbit_path' => '/home/nckrtl/orbit']);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0)]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllControllerRemoteShell(exitCode: 255, stderr: 'Permission denied'));

    $response = $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP]);

    $response->assertOk();
    $response->assertJsonPath('success.data.updates.0.status', 'completed');
    $response->assertJsonPath('success.data.updates.1.status', 'failed');
    $response->assertJsonPath('success.data.updates.1.output', 'Permission denied');
    $response->assertJsonPath('success.meta.summary.total', 2);
    $response->assertJsonPath('success.meta.summary.completed', 1);
    $response->assertJsonPath('success.meta.summary.failed', 1);
});

it('rejects unauthenticated requests', function (): void {
    $this->call('POST', '/api/update/all')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'authorization_failed');
});

it('requires gateway-admin authority for non-gateway callers', function (): void {
    Node::factory()->create([
        'name' => 'control-1',
        'status' => 'active',
        'wireguard_address' => '10.6.0.90']);

    $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => '10.6.0.90'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', '*')
        ->assertJsonPath('error.meta.serving_node', 'gateway');
});

it('allows non-gateway callers with gateway-admin authority', function (): void {
    $gateway = Node::query()->where('name', 'gateway')->firstOrFail();
    $caller = Node::factory()->create([
        'name' => 'control-1',
        'status' => 'active',
        'wireguard_address' => '10.6.0.90']);
    NodeAccess::query()->create([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $gateway->id,
        'permissions' => ['*'],
        'custom_permissions' => ['*']]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0)]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllControllerRemoteShell);

    $response = $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => '10.6.0.90']);

    $response->assertOk()
        ->assertJsonPath('success.data.updates.0.target', 'gateway');
});

it('streams progress events for gateway-owned update targets', function (): void {
    createUpdateAllAppHostNode([
        'name' => 'beast',
        'host' => 'beast',
        'orbit_path' => '/home/nckrtl/orbit']);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0)]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllControllerRemoteShell);

    $response = $this->call(
        'POST',
        '/api/update/all',
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP],
    );

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
    $response->assertHeader('X-Accel-Buffering', 'no');
    $content = $response->streamedContent();

    expect($content)->toContain('event: tree')
        ->and($content)->toContain('"key":"gateway"')
        ->and($content)->toContain('"label":"Pulling source - gateway"')
        ->and($content)->toContain('"key":"beast"')
        ->and($content)->toContain('"status":"pulling_source"')
        ->and($content)->toContain('"status":"installing_dependencies"')
        ->and($content)->toContain('"status":"running_migrations"')
        ->and($content)->toContain('"status":"done"')
        ->and($content)->toContain('event: complete');
});

final class UpdateAllControllerRemoteShell implements RemoteShell
{
    public array $nodes = [];

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stderr = '',
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;

        return new RemoteShellResult(
            exitCode: $this->exitCode,
            stdout: '',
            stderr: $this->stderr,
            durationMs: 1,
        );
    }
}

final readonly class UpdateAllControllerTimedRemoteShell implements RemoteShell
{
    public function __construct(
        private string $logPath,
        private int $pullDelayMicroseconds = 100_000,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $startedAt = hrtime(true);

        if ($script === 'git pull --ff-only') {
            usleep($this->pullDelayMicroseconds);
        }

        $endedAt = hrtime(true);

        file_put_contents(
            $this->logPath,
            json_encode([
                'node' => $node->name,
                'script' => $script,
                'started_at' => $startedAt,
                'ended_at' => $endedAt], JSON_THROW_ON_ERROR).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );

        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: (int) (($endedAt - $startedAt) / 1_000_000),
        );
    }
}

final readonly class UpdateAllControllerAsyncOnlyRemoteShell implements RemoteShell, StartsRemoteShellProcesses
{
    public function __construct(
        private string $logPath,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException('Synchronous remote shell should not be used for streamed app-node updates.');
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        file_put_contents(
            $this->logPath,
            json_encode([
                'node' => $node->name,
                'script' => $script], JSON_THROW_ON_ERROR).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );

        return new FakeInvokedProcess(
            $script,
            Process::describe()->iterations($script === 'git pull --ff-only' ? 3 : 0)->exitCode(0),
        );
    }
}
