<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Processes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\StopProcessesRequest;
use App\Http\Gateway\Responses\Processes\ProcessStopResponse;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'ca_pem_path' => '/path/to/ca.pem',
    ])->save();
});

it('targets the process stop gateway endpoint with optional filters', function (): void {
    $request = new StopProcessesRequest(app: 'docs', workspace: 'feature-docs', name: 'vite');

    expect($request->getMethod())->toBe(Method::POST)
        ->and($request->resolveEndpoint())->toBe('/api/processes/stop')
        ->and($request->body()->all())->toBe([
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'name' => 'vite',
        ]);
});

it('returns a ProcessStopResponse DTO', function (): void {
    $mock = new MockClient([
        StopProcessesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'runtimes' => [
                        [
                            'process' => 'vite',
                            'app' => 'docs',
                            'workspace' => null,
                            'runtime_unit' => 'orbit_docs_main_vite',
                            'state' => 'stopped',
                            'event' => ['type' => 'stopped'],
                        ],
                    ],
                ],
                'meta' => [],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new StopProcessesRequest(app: 'docs', workspace: null, name: 'vite'))->dto();

    expect($dto)->toBeInstanceOf(ProcessStopResponse::class)
        ->and($dto->data['runtimes'][0]['state'])->toBe('stopped');
});
