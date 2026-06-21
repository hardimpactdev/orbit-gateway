<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_WORKER_CALLER_WG_IP = '10.6.0.99';

beforeEach(function (): void {});

function createWorkerControllerCaller(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_WORKER_CALLER_WG_IP,
        'wireguard_address' => APP_WORKER_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantWorkerAccess(Node $caller, Node $appNode, array $permissions): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now()]);
}

/**
 * @param  list<RemoteShellResult>  $results
 */
function bindWorkerControllerShell(array $results = []): void
{
    app()->instance(RemoteShell::class, new class($results) implements RemoteShell
    {
        public function __construct(public array $results) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return array_shift($this->results) ?? new RemoteShellResult(
                exitCode: 0,
                stdout: "octane:installed\nfrankenphp-worker-file:present\nfrankenphp:configured\n",
                stderr: '',
                durationMs: 1,
            );
        }
    });
}

describe('AppWorkerController', function (): void {
    it('returns the worker payload for a freshly created app via show', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['app:read']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime_kind' => AppRuntimeKind::Php]);
        bindWorkerControllerShell();

        $response = $this->call('GET', '/api/apps/docs/worker', [], [], [], ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app', 'docs')
            ->assertJsonPath('success.data.worker_enabled', false)
            ->assertJsonPath('success.data.worker_config', null);
    });

    it('enables worker mode and stores worker_config', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['app:worker']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php]);
        bindWorkerControllerShell();

        $response = $this->call('POST', '/api/apps/docs/worker/enable', [], [], [], ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.worker_enabled', true)
            ->assertJsonPath('success.data.worker_config.workers', 'auto')
            ->assertJsonPath('success.data.worker_config.max_requests', 500)
            ->assertJsonMissingPath('success.data.worker_config.max_consecutive_failures');

        $app = App::query()->where('name', 'docs')->first();
        expect($app->worker_enabled)->toBeTrue();
    });

    it('refuses to enable worker mode when readiness fails and leaves state unchanged', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['app:worker']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php]);
        bindWorkerControllerShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1)]);

        $response = $this->call('POST', '/api/apps/docs/worker/enable', [], [], [], ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'app.worker_readiness_failed');

        $app = App::query()->where('name', 'docs')->first();
        expect($app->worker_enabled)->toBeFalse();
    });

    it('disables worker mode and keeps the stored worker_config', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['app:worker']);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'runtime_kind' => AppRuntimeKind::Php,
            'worker_enabled' => true,
            'worker_config' => ['workers' => 'auto', 'max_requests' => 500]]);
        bindWorkerControllerShell();

        $response = $this->call('POST', '/api/apps/docs/worker/disable', [], [], [], ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.worker_enabled', false)
            ->assertJsonPath('success.data.worker_config.workers', 'auto');

        $app = App::query()->where('name', 'docs')->first();
        expect($app->worker_enabled)->toBeFalse()
            ->and($app->worker_config)->toMatchArray(['workers' => 'auto', 'max_requests' => 500]);
    });

    it('rejects worker mutations when the caller lacks the app:worker permission', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['app:read']);
        App::factory()->for($node, 'node')->create(['name' => 'docs', 'runtime_kind' => AppRuntimeKind::Php]);
        bindWorkerControllerShell();

        $response = $this->call('POST', '/api/apps/docs/worker/enable', [], [], [], ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:worker');
    });

    it('returns 404 when the targeted app is missing', function (): void {
        createWorkerControllerCaller();
        bindWorkerControllerShell();

        $response = $this->call('GET', '/api/apps/missing/worker', [], [], [], ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'app.not_found');
    });

    it('resolves the worker payload by exact app name even when another app holds the same value as a domain', function (): void {
        $caller = createWorkerControllerCaller();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantWorkerAccess($caller, $node, ['app:read']);

        // App "alpha" carries the colliding domain. If the controller path
        // short-circuits on a domain match, it would return alpha instead
        // of the docs.example.com-named app.
        App::factory()->for($node, 'node')->create([
            'name' => 'alpha',
            'domain' => 'docs.example.com',
            'runtime_kind' => AppRuntimeKind::Php]);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs.example.com',
            'domain' => 'other.example.com',
            'runtime_kind' => AppRuntimeKind::Php]);
        bindWorkerControllerShell();

        $response = $this->call('GET', '/api/apps/docs.example.com/worker', [], [], [], ['REMOTE_ADDR' => APP_WORKER_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app', 'docs.example.com');
    });
});
