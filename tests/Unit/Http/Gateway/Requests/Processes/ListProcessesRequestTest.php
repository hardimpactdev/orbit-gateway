<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Processes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\ListProcessesRequest;
use App\Http\Gateway\Responses\Processes\ProcessListResponse;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('resolves to GET /api/processes', function (): void {
    $request = new ListProcessesRequest;

    expect($request->resolveEndpoint())->toBe('/api/processes');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes app and workspace filters when provided', function (): void {
    $request = new ListProcessesRequest(app: 'docs', workspace: 'feature-docs');

    expect($request->query()->all())->toBe([
        'app' => 'docs',
        'workspace' => 'feature-docs',
    ]);
});

it('omits null filters from the query', function (): void {
    $request = new ListProcessesRequest(app: 'docs');

    expect($request->query()->all())->toBe(['app' => 'docs']);
});

it('returns a ProcessListResponse DTO with context and processes', function (): void {
    $mock = new MockClient([
        ListProcessesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'context' => ['app' => 'docs', 'workspace' => null],
                    'processes' => [
                        ['name' => 'queue', 'command' => 'php artisan queue:work'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListProcessesRequest)->dto();

    expect($dto)->toBeInstanceOf(ProcessListResponse::class);
    expect($dto->context)->toBe(['app' => 'docs', 'workspace' => null]);
    expect($dto->processes)->toBe([
        ['name' => 'queue', 'command' => 'php artisan queue:work'],
    ]);
});
