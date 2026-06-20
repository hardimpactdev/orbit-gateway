<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process as NodeProcess;
use App\Services\WebSockets\WebSocketDoctorProbe;
use App\Services\WebSockets\WebSocketRuntimeContainerRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('runs the redis doctor script inside the rendered websocket runtime container without php dash r', function (): void {
    $redisNode = Node::factory()->database()->create([
        'name' => 'redis-1',
        'status' => 'active',
        'host' => '203.0.113.10',
        'wireguard_address' => '10.6.0.10',
    ]);
    NodeProcess::factory()->forOwner($redisNode)->create([
        'name' => 'redis',
        'runtime' => ProcessRuntime::Docker,
        'command' => 'redis-server --appendonly yes',
        'runtime_config' => [
            'definition' => 'redis',
        ],
    ]);
    $websocketNode = Node::factory()->create([
        'name' => 'realtime-1',
        'status' => 'active',
        'host' => '203.0.113.44',
        'wireguard_address' => '10.6.0.44',
    ]);
    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $websocketNode->id,
        'role' => 'websocket',
        'status' => 'active',
        'settings' => [
            'redis_node_id' => $redisNode->id,
        ],
    ]);
    $shell = new WebSocketDoctorProbeTestRemoteShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: "exists=1\nrunning=true\nenv_host=10.6.0.44\ncmd_host=10.6.0.44\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $drift = app(WebSocketDoctorProbe::class)->toolDrift($websocketNode, $assignment);

    $expectedContainer = app(WebSocketRuntimeContainerRenderer::class)->containerName($websocketNode);
    $redisScript = collect($shell->scripts)
        ->first(fn (string $script): bool => str_contains($script, '# orbit-websocket-doctor:redis-probe'));

    expect($drift)->toBe([])
        ->and($redisScript)->toBeString();

    $redisScript = (string) $redisScript;

    expect($redisScript)->toContain('# orbit-websocket-doctor:redis-probe')
        ->and($redisScript)->toContain('docker exec -i "$container" php')
        ->and($redisScript)->toContain('container='.escapeshellarg($expectedContainer))
        ->and($redisScript)->toContain("<?php\n")
        ->and($redisScript)->toContain("getenv('REDIS_HOST')")
        ->and($redisScript)->toContain("getenv('REDIS_PORT')")
        ->and($redisScript)->toContain('fsockopen($host, $port, $errno, $errstr, 2)')
        ->and($redisScript)->toContain("fwrite(STDERR, \$errstr !== '' ? \$errstr : 'redis unavailable')")
        ->and($redisScript)->toContain('exit(1)')
        ->and($redisScript)->not->toContain('php -r');
})->group('websocket', 'doctor');

final class WebSocketDoctorProbeTestRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
