<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const TOOL_TARGET_AUTH_CALLER_WG_IP = '10.6.0.95';

function createToolTargetAuthCaller(): Node
{
    return Node::factory()->create([
        'name' => 'caller',
        'host' => TOOL_TARGET_AUTH_CALLER_WG_IP,
        'wireguard_address' => TOOL_TARGET_AUTH_CALLER_WG_IP]);
}

/**
 * @param  list<string>  $permissions
 */
function grantToolTargetAuthAccess(Node $caller, Node $appNode, array $permissions = ['*']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions),
        'created_at' => now(),
        'updated_at' => now()]);
}

describe('tool API target authorization', function (): void {
    it('rejects hidden target selectors before tool side effects', function (string $method, string $uri, array $parameters): void {
        $caller = createToolTargetAuthCaller();
        $visibleNode = createTestAppHostNode(['name' => 'visible-node', 'status' => 'active']);
        $hiddenNode = createTestAppHostNode(['name' => 'hidden-node', 'status' => 'active']);
        grantToolTargetAuthAccess($caller, $visibleNode);

        NodeTool::factory()->create([
            'node_id' => $hiddenNode->id,
            'name' => toolTargetAuthToolNameFromUri($uri),
            'expected_state' => 'installed',
            'config' => ['compose_path' => '/opt/orbit/docker-compose.yml'],
            'credentials' => [
                'fields' => [
                    'password' => 'secret']]]);

        $shell = new ToolTargetAuthorizationRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call($method, $uri, $parameters, [], [], ['REMOTE_ADDR' => TOOL_TARGET_AUTH_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect($shell->scripts)->toBe([]);
    })->with([
        'install' => ['POST', '/api/tools/composer/install', ['node' => 'hidden-node']],
        'update' => ['POST', '/api/tools/composer/update', ['node' => 'hidden-node']],
        'credentials' => ['GET', '/api/tools/openclaw/credentials', ['node' => 'hidden-node']],
        'remove' => ['DELETE', '/api/tools/composer', ['node' => 'hidden-node', 'destructive_consent' => true]],
        'reconfigure' => ['POST', '/api/tools/polyscope-server/reconfigure', ['node' => 'hidden-node']]]);

    it('uses the only visible target when no selector is supplied', function (): void {
        $caller = createToolTargetAuthCaller();
        $visibleNode = createTestAppHostNode(['name' => 'visible-node', 'status' => 'active']);
        $hiddenNode = createTestAppHostNode(['name' => 'hidden-node', 'status' => 'active']);
        grantToolTargetAuthAccess($caller, $visibleNode);

        NodeTool::factory()->create([
            'node_id' => $visibleNode->id,
            'name' => 'openclaw',
            'credentials' => [
                'fields' => [
                    'password' => 'visible-secret']]]);
        NodeTool::factory()->create([
            'node_id' => $hiddenNode->id,
            'name' => 'openclaw',
            'credentials' => [
                'fields' => [
                    'password' => 'hidden-secret']]]);

        $response = $this->call('GET', '/api/tools/openclaw/credentials', [], [], [], ['REMOTE_ADDR' => TOOL_TARGET_AUTH_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.node', 'visible-node')
            ->assertJsonPath('success.data.credentials.fields.password', 'visible-secret');
    });

    it('allows hosted callers to target their own app tool node with an explicit self grant', function (): void {
        $caller = createTestAppHostNode([
            'name' => 'caller',
            'host' => TOOL_TARGET_AUTH_CALLER_WG_IP,
            'wireguard_address' => TOOL_TARGET_AUTH_CALLER_WG_IP]);

        grantToolTargetAuthAccess($caller, $caller);

        NodeTool::factory()->create([
            'node_id' => $caller->id,
            'name' => 'openclaw',
            'credentials' => [
                'fields' => [
                    'password' => 'self-secret']]]);

        $response = $this->call('GET', '/api/tools/openclaw/credentials', [
            'node' => 'caller'], [], [], ['REMOTE_ADDR' => TOOL_TARGET_AUTH_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.node', 'caller')
            ->assertJsonPath('success.data.credentials.fields.password', 'self-secret');

        expect(DB::table('node_access')->count())->toBe(1);
    });

    it('rejects credentials when the grant only allows reading tools', function (): void {
        $caller = createToolTargetAuthCaller();
        $visibleNode = createTestAppHostNode(['name' => 'visible-node', 'status' => 'active']);
        grantToolTargetAuthAccess($caller, $visibleNode, ['tool:read']);

        NodeTool::factory()->create([
            'node_id' => $visibleNode->id,
            'name' => 'openclaw',
            'credentials' => [
                'fields' => [
                    'password' => 'visible-secret']]]);

        $response = $this->call('GET', '/api/tools/openclaw/credentials', [
            'node' => 'visible-node'], [], [], ['REMOTE_ADDR' => TOOL_TARGET_AUTH_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('streams tool mutation progress from the canonical endpoint', function (): void {
        $caller = createToolTargetAuthCaller();
        $visibleNode = createTestAppHostNode(['name' => 'visible-node', 'status' => 'active']);
        grantToolTargetAuthAccess($caller, $visibleNode);

        app()->instance(RemoteShell::class, new ToolTargetAuthorizationRecordingShell);

        $response = $this->call(
            'POST',
            '/api/tools/composer/install',
            ['node' => 'visible-node'],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'text/event-stream',
                'REMOTE_ADDR' => TOOL_TARGET_AUTH_CALLER_WG_IP],
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $content = $response->streamedContent();

        expect($content)->toContain('event: tree')
            ->and($content)->toContain('"title":"Installing Tool"')
            ->and($content)->toContain('"key":"resolve-target"')
            ->and($content)->toContain('"key":"read-intent"')
            ->and($content)->toContain('"key":"run-action"')
            ->and($content)->toContain('event: complete')
            ->and($content)->toContain('"name":"composer"')
            ->and($content)->not->toContain('/stream');
    });
});

function toolTargetAuthToolNameFromUri(string $uri): string
{
    if (str_contains($uri, 'openclaw')) {
        return 'openclaw';
    }

    if (str_contains($uri, 'opencode-server')) {
        return 'opencode-server';
    }

    if (str_contains($uri, 'polyscope-server')) {
        return 'polyscope-server';
    }

    return 'composer';
}

final class ToolTargetAuthorizationRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
