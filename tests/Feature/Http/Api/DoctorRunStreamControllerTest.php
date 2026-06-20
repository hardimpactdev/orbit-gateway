<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Platform\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(PlatformDetector::class, new class extends PlatformDetector
    {
        public function detectLocal(): string
        {
            return 'linux';
        }
    });
});

const DOCTOR_RUN_STREAM_CALLER_WG_IP = '10.6.0.194';

function createDoctorRunStreamCallerNode(array $overrides = []): Node
{
    return createTestGatewayNode([
        'name' => 'doctor-stream-caller',
        'host' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        'wireguard_address' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        'platform' => 'linux',
        ...$overrides,
    ]);
}

it('streams doctor verify progress from the gateway', function (): void {
    createDoctorRunStreamCallerNode();

    $response = $this->call('POST', '/api/doctor/run', [
        'families' => ['node'],
        'mode' => 'verify',
        'self' => true,
    ], [], [], [
        'HTTP_ACCEPT' => 'text/event-stream',
        'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
    ]);

    $response->assertOk();

    $content = $response->streamedContent();

    expect($content)->toContain('event: tree')
        ->and($content)->toContain('Running Doctor')
        ->and($content)->toContain('Checking node')
        ->and($content)->toContain('event: complete');
});

it('streams fleet doctor progress per node instead of batching all running steps before probing', function (): void {
    createDoctorRunStreamCallerNode(['name' => 'doctor-stream-caller']);
    createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);

    $response = $this->call('POST', '/api/doctor/run', [
        'families' => ['node'],
        'mode' => 'verify',
    ], [], [], [
        'HTTP_ACCEPT' => 'text/event-stream',
        'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
    ]);

    $response->assertOk();

    $stepEvents = doctorRunStreamStepEvents($response->streamedContent());

    expect($stepEvents)->not->toBeEmpty()
        ->and(doctorRunStreamStepEventIndex($stepEvents, 'app-1', 'done'))
        ->toBeLessThan(doctorRunStreamStepEventIndex($stepEvents, 'doctor-stream-caller', 'running'));
});

/**
 * @return list<array{key: string, status: string}>
 */
function doctorRunStreamStepEvents(string $content): array
{
    $events = [];

    foreach (preg_split("/\r\n\r\n|\n\n/", trim($content)) ?: [] as $frame) {
        if (! str_contains($frame, "event: step\n")) {
            continue;
        }

        $dataLine = array_values(array_filter(
            explode("\n", $frame),
            static fn (string $line): bool => str_starts_with($line, 'data: '),
        ))[0] ?? null;

        if ($dataLine === null) {
            continue;
        }

        /** @var array{key?: string, status?: string} $payload */
        $payload = json_decode(substr($dataLine, 6), true, flags: JSON_THROW_ON_ERROR);

        if (! is_string($payload['key'] ?? null) || ! is_string($payload['status'] ?? null)) {
            continue;
        }

        $events[] = [
            'key' => $payload['key'],
            'status' => $payload['status'],
        ];
    }

    return $events;
}

/**
 * @param  list<array{key: string, status: string}>  $events
 */
function doctorRunStreamStepEventIndex(array $events, string $key, string $status): int
{
    foreach ($events as $index => $event) {
        if ($event['key'] === $key && $event['status'] === $status) {
            return $index;
        }
    }

    throw new RuntimeException("Missing step event {$key} {$status}.");
}
