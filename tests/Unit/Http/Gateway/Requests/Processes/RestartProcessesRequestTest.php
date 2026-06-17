<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Processes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\RestartProcessesRequest;
use App\Http\Gateway\Responses\Processes\ProcessRestartResponse;
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

it('targets the process restart gateway endpoint with optional filters', function (): void {
    $request = new RestartProcessesRequest(app: 'docs', workspace: 'feature-docs', name: 'vite');

    expect($request->getMethod())->toBe(Method::POST)
        ->and($request->resolveEndpoint())->toBe('/api/processes/restart')
        ->and($request->body()->all())->toBe([
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'name' => 'vite',
        ]);
});

it('returns a ProcessRestartResponse DTO', function (): void {
    $mock = new MockClient([
        RestartProcessesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'runtimes' => [
                        [
                            'process' => 'vite',
                            'app' => 'docs',
                            'workspace' => null,
                            'runtime_unit' => 'orbit_docs_main_vite',
                            'state' => 'running',
                            'events' => [['type' => 'stopped'], ['type' => 'started']],
                        ],
                    ],
                ],
                'meta' => [],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new RestartProcessesRequest(app: 'docs', workspace: null, name: 'vite'))->dto();

    expect($dto)->toBeInstanceOf(ProcessRestartResponse::class)
        ->and($dto->data['runtimes'][0]['state'])->toBe('running');
});
