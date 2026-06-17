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
