<?php

declare(strict_types=1);

use App\Contracts\RemoteShellStream;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const PROCESS_LOG_STREAM_CALLER_WG_IP = '10.6.0.96';

describe('ProcessLogController follow stream', function (): void {
    it('streams followed process log output through the gateway API', function (): void {
        createTestGatewayNode([
            'name' => 'caller',
            'host' => PROCESS_LOG_STREAM_CALLER_WG_IP,
            'wireguard_address' => PROCESS_LOG_STREAM_CALLER_WG_IP,
        ]);
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        $stream = new ProcessLogApiRecordingRemoteStream;
        app()->instance(RemoteShellStream::class, $stream);

        $response = $this->call(
            'GET',
            '/api/processes/vite/log?app=docs&lines=5&follow=1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LOG_STREAM_CALLER_WG_IP],
        );

        $response->assertStreamed()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertStreamedContent("streamed vite line\n");

        expect($stream->scripts)->toBe(["sudo journalctl -u 'orbit_docs_main_vite.service' -n 5 -f --no-pager --output=short-iso 2>&1"]);
    });
});

final class ProcessLogApiRecordingRemoteStream implements RemoteShellStream
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  callable(string): void  $onOutput
     * @param  array<string, mixed>  $options
     */
    public function stream(Node $node, string $script, callable $onOutput, array $options = []): int
    {
        $this->scripts[] = $script;
        $onOutput("streamed vite line\n");

        return 0;
    }
}
