<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Processes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\ShowProcessLogsRequest;
use App\Http\Gateway\Responses\Processes\ProcessLogsResponse;
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

it('targets the process log endpoint with query filters', function (): void {
    $request = new ShowProcessLogsRequest(name: 'vite', app: 'docs', workspace: 'feature-docs', lines: 50);

    expect($request->getMethod())->toBe(Method::GET)
        ->and($request->resolveEndpoint())->toBe('/api/processes/vite/log')
        ->and($request->query()->all())->toBe([
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'lines' => 50,
        ]);
});

it('returns a ProcessLogsResponse DTO with meta', function (): void {
    $mock = new MockClient([
        ShowProcessLogsRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'logs' => [
                        'process' => 'vite',
                        'app' => 'docs',
                        'workspace' => null,
                        'runtime_unit' => 'orbit_docs_main_vite',
                        'lines' => [['timestamp' => null, 'message' => 'Vite ready']],
                    ],
                ],
                'meta' => ['line_count' => 1],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowProcessLogsRequest(name: 'vite', app: 'docs', workspace: null))->dto();

    expect($dto)->toBeInstanceOf(ProcessLogsResponse::class)
        ->and($dto->data['logs']['runtime_unit'])->toBe('orbit_docs_main_vite')
        ->and($dto->meta['line_count'])->toBe(1);
});
