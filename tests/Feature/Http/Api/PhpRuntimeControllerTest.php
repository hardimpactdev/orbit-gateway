<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PHP_API_CALLER_WG_IP = '10.6.0.97';

function createPhpApiCaller(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => PHP_API_CALLER_WG_IP,
        'wireguard_address' => PHP_API_CALLER_WG_IP,
    ], $overrides));
}

function grantPhpApiAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('PHP runtime API controllers', function (): void {
    it('returns a PHP runtime view for an authorized caller', function (): void {
        $caller = createPhpApiCaller();
        $node = Node::factory()->appDev()->create(['name' => 'app-1']);
        grantPhpApiAccess($caller, $node);
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php', 'config' => ['versions' => ['8.5'], 'cli_version' => '8.5']]);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'php_version' => null]);

        $response = $this->call('GET', '/api/php/runtime?app=docs&workspace=feature-docs', [], [], [], ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.php.node', 'app-1')
            ->assertJsonPath('success.data.php.workspace.inherits', true);
    });

    it('writes app PHP runtime intent for an authorized caller', function (): void {
        $caller = createPhpApiCaller();
        $node = Node::factory()->appDev()->create(['name' => 'app-1']);
        grantPhpApiAccess($caller, $node);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'php',
            'config' => [
                'versions' => ['8.5', '8.4'],
                'images' => [
                    'dunglas/frankenphp:1-php8.5-bookworm',
                    'dunglas/frankenphp:1-php8.4-bookworm',
                ],
                'cli_version' => '8.5',
            ],
        ]);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.4']);

        $response = $this->call('POST', '/api/php/use', [
            'version' => '8.5',
            'app' => 'docs',
        ], [], [], ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.result.target', 'app');

        expect($app->refresh()->php_version)->toBe('8.5');
    });

    it('returns authorization failure for hidden nodes', function (): void {
        createPhpApiCaller();
        $node = Node::factory()->appDev()->create(['name' => 'app-1']);
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php', 'config' => ['versions' => ['8.5'], 'cli_version' => '8.5']]);

        $response = $this->call('GET', '/api/php/runtime?node=app-1', [], [], [], ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('authorizes app-selected targets against their owning node', function (): void {
        createPhpApiCaller();
        $node = Node::factory()->appDev()->create(['name' => 'app-1']);
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php', 'config' => ['versions' => ['8.5'], 'cli_version' => '8.5']]);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/php/runtime?app=docs', [], [], [], ['REMOTE_ADDR' => PHP_API_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });
});
