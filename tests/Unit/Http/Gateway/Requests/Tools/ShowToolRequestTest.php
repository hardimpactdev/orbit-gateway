<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Tools;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\ShowToolRequest;
use App\Http\Gateway\Responses\Tools\ToolShowResponse;
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

it('resolves to GET /api/tools/{tool}', function (): void {
    $request = new ShowToolRequest(tool: 'composer');

    expect($request->resolveEndpoint())->toBe('/api/tools/composer');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes app node and live filters when provided', function (): void {
    $request = new ShowToolRequest(tool: 'composer', app: 'docs', node: 'app-1', live: true);

    expect($request->query()->all())->toBe([
        'app' => 'docs',
        'node' => 'app-1',
        'live' => '1',
    ]);
});

it('omits null and false filters from the query', function (): void {
    $request = new ShowToolRequest(tool: 'composer', node: 'app-1');

    expect($request->query()->all())->toBe(['node' => 'app-1']);
});

it('returns a ToolShowResponse DTO with a tool', function (): void {
    $mock = new MockClient([
        ShowToolRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'tool' => [
                        'name' => 'composer',
                        'node' => 'app-1',
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowToolRequest(tool: 'composer'))->dto();

    expect($dto)->toBeInstanceOf(ToolShowResponse::class);
    expect($dto->tool)->toBe([
        'name' => 'composer',
        'node' => 'app-1',
    ]);
});
