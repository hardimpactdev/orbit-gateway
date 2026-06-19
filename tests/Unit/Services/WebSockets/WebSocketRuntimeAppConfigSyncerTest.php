<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\AppWebSocketBinding;
use App\Models\Node;
use App\Services\WebSockets\WebSocketRuntimeAppConfigSyncer;
use App\Services\WebSockets\WebSocketRuntimeSourceInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('syncs enabled binding credentials to each active websocket node runtime config', function (): void {
    $websocketNode = Node::factory()->withActiveRole('websocket')->create([
        'name' => 'app-dev-1',
        'host' => 'app-dev-1.example.com',
        'wireguard_address' => '10.6.0.44',
    ]);

    Node::factory()->withActiveRole('websocket')->create([
        'name' => 'ws-disabled',
        'status' => 'inactive',
        'wireguard_address' => '10.6.0.45',
    ]);

    $app = App::factory()->create(['name' => 'docs']);

    AppWebSocketBinding::factory()->create([
        'app_id' => $app->id,
        'enabled' => true,
        'reverb_app_id' => 'docs',
        'reverb_app_key' => 'app-key',
        'reverb_app_secret' => 'app-secret',
        'allowed_origins' => [
            'https://docs.test',
            'https://docs.test',
            'https://api.docs.test:8443',
        ],
    ]);

    AppWebSocketBinding::factory()->create([
        'enabled' => false,
        'reverb_app_id' => 'disabled',
        'reverb_app_key' => 'disabled-key',
        'reverb_app_secret' => 'disabled-secret',
    ]);

    $shell = new WebSocketRuntimeAppConfigSyncerTestShell;
    app()->instance(RemoteShell::class, $shell);

    app(WebSocketRuntimeAppConfigSyncer::class)->sync();

    expect($shell->nodes)->toHaveCount(1)
        ->and($shell->nodes[0]->is($websocketNode))->toBeTrue()
        ->and($shell->scripts[0])->toContain(WebSocketRuntimeSourceInstaller::AppsConfigPath)
        ->and($shell->scripts[0])->toContain("docker container inspect 'orbit-websocket-app-dev-1'")
        ->and($shell->scripts[0])->toContain("docker restart 'orbit-websocket-app-dev-1'")
        ->and($shell->options[0]['metadata'])->toBe([
            'ORBIT_OPERATION_ID' => 'websocket-runtime-app-config-sync',
        ]);

    $config = websocketRuntimeAppConfigFromScript($shell->scripts[0]);

    expect($config)->toHaveCount(1)
        ->and($config[0])->toMatchArray([
            'key' => 'app-key',
            'secret' => 'app-secret',
            'app_id' => 'docs',
            'options' => [
                'host' => 'websocket.orbit',
                'port' => 443,
                'scheme' => 'https',
                'useTLS' => true,
            ],
            'allowed_origins' => ['docs.test', 'api.docs.test'],
            'ping_interval' => 60,
            'activity_timeout' => 30,
            'max_message_size' => 10_000,
        ]);
});

it('writes an empty runtime app list when no bindings are enabled', function (): void {
    Node::factory()->withActiveRole('websocket')->create([
        'name' => 'app-dev-1',
        'wireguard_address' => '10.6.0.44',
    ]);

    AppWebSocketBinding::factory()->create([
        'enabled' => false,
        'reverb_app_id' => 'disabled',
    ]);

    $shell = new WebSocketRuntimeAppConfigSyncerTestShell;
    app()->instance(RemoteShell::class, $shell);

    app(WebSocketRuntimeAppConfigSyncer::class)->sync();

    expect(websocketRuntimeAppConfigFromScript($shell->scripts[0]))->toBe([]);
});

/**
 * @return list<array<string, mixed>>
 */
function websocketRuntimeAppConfigFromScript(string $script): array
{
    preg_match("/printf %s\\s+'([^']+)' \\| base64 -d/", $script, $matches);

    expect($matches[1] ?? null)->toBeString();

    $content = base64_decode($matches[1], true);

    expect($content)->toBeString();

    preg_match('/return (.*);\\n/s', $content, $returnMatches);

    expect($returnMatches[1] ?? null)->toBeString();

    /** @var list<array<string, mixed>> $config */
    $config = eval('return '.$returnMatches[1].';');

    return $config;
}

final class WebSocketRuntimeAppConfigSyncerTestShell implements RemoteShell
{
    /** @var list<Node> */
    public array $nodes = [];

    /** @var list<string> */
    public array $scripts = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;
        $this->options[] = $options;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
