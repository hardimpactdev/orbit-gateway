<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const PROCESS_EVENT_INGEST_APP_WG_IP = '10.6.0.95';

function createProcessEventIngestNode(array $overrides = [], string $role = 'app-dev'): Node
{
    $attributes = array_merge([
        'name' => 'app-node',
        'status' => 'active',
        'host' => PROCESS_EVENT_INGEST_APP_WG_IP,
        'wireguard_address' => PROCESS_EVENT_INGEST_APP_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

describe('ProcessEventIngestController', function (): void {
    it('records a crashed process event from an active app node and links runtime intent', function (): void {
        $node = createProcessEventIngestNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $process = Process::factory()->forOwner($app)->create(['name' => 'vite']);

        $response = $this->call('POST', '/api/events/process', [
            'event_id' => 'evt-crash-1',
            'event' => 'crashed',
            'unit' => 'orbit_docs_main_vite',
            'exit_code' => 1,
            'exit_status' => 'exited',
            'at' => '2026-04-21T12:00:00+00:00',
        ], [], [], ['REMOTE_ADDR' => PROCESS_EVENT_INGEST_APP_WG_IP]);

        $response->assertCreated()
            ->assertJsonPath('success.meta.matched', true);

        $this->assertDatabaseHas('process_events', [
            'event_id' => 'evt-crash-1',
            'event' => 'crashed',
            'node_id' => $node->id,
            'app_id' => $app->id,
            'process_id' => $process->id,
            'workspace_id' => null,
            'unit_name' => 'orbit_docs_main_vite',
            'exit_code' => 1,
            'exit_status' => 'exited',
        ]);
    });

    it('links workspace runtime units when the unit name matches active intent', function (): void {
        $node = createProcessEventIngestNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['app_id' => $app->id, 'name' => 'feature-docs']);
        $process = Process::factory()->forOwner($app)->create(['name' => 'vite']);

        $this->call('POST', '/api/events/process', [
            'event_id' => 'evt-crash-workspace-1',
            'event' => 'crashed',
            'unit' => 'orbit_docs_feature-docs_vite',
            'exit_code' => 1,
            'exit_status' => 'exited',
            'at' => '2026-04-21T12:00:00+00:00',
        ], [], [], ['REMOTE_ADDR' => PROCESS_EVENT_INGEST_APP_WG_IP])->assertCreated();

        $this->assertDatabaseHas('process_events', [
            'event_id' => 'evt-crash-workspace-1',
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'process_id' => $process->id,
        ]);
    });

    it('records unmatched crash events without intent foreign keys', function (): void {
        $node = createProcessEventIngestNode();

        $response = $this->call('POST', '/api/events/process', [
            'event_id' => 'evt-unmatched-1',
            'event' => 'crashed',
            'unit' => 'missing-runtime-unit',
            'exit_code' => 137,
            'exit_status' => 'signal',
            'at' => '2026-04-21T12:00:00+00:00',
        ], [], [], ['REMOTE_ADDR' => PROCESS_EVENT_INGEST_APP_WG_IP]);

        $response->assertCreated()
            ->assertJsonPath('success.meta.matched', false);

        $this->assertDatabaseHas('process_events', [
            'event_id' => 'evt-unmatched-1',
            'event' => 'crashed',
            'node_id' => $node->id,
            'unit_name' => 'missing-runtime-unit',
            'app_id' => null,
            'workspace_id' => null,
            'process_id' => null,
        ]);
    });

    it('is idempotent by event id', function (): void {
        createProcessEventIngestNode();
        $payload = [
            'event_id' => 'evt-idempotent-1',
            'event' => 'crashed',
            'unit' => 'orbit_docs_main_vite',
            'exit_code' => 1,
            'exit_status' => 'exited',
            'at' => '2026-04-21T12:00:00+00:00',
        ];

        $this->call('POST', '/api/events/process', $payload, [], [], ['REMOTE_ADDR' => PROCESS_EVENT_INGEST_APP_WG_IP])->assertCreated();
        $this->call('POST', '/api/events/process', $payload, [], [], ['REMOTE_ADDR' => PROCESS_EVENT_INGEST_APP_WG_IP])
            ->assertOk()
            ->assertJsonPath('success.meta.idempotent', true);

        expect(ProcessEvent::query()->where('event_id', 'evt-idempotent-1')->count())->toBe(1);
    });

    it('rejects non-crashed events and non-app node identities', function (array $nodeOverrides, string $role, string $event, int $status): void {
        createProcessEventIngestNode($nodeOverrides, $role);

        $response = $this->call('POST', '/api/events/process', [
            'event_id' => 'evt-rejected',
            'event' => $event,
            'unit' => 'orbit_docs_main_vite',
            'exit_code' => 1,
            'exit_status' => 'exited',
            'at' => '2026-04-21T12:00:00+00:00',
        ], [], [], ['REMOTE_ADDR' => PROCESS_EVENT_INGEST_APP_WG_IP]);

        $response->assertStatus($status);
    })->with([
        'started event' => [[], 'app-dev', 'started', 422],
        'gateway node' => [[], 'gateway', 'crashed', 403],
        'inactive app node' => [['status' => 'inactive'], 'app-dev', 'crashed', 403],
    ]);
});
