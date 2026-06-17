<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Tools;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Tools\ListToolsRequest;
use App\Http\Gateway\Responses\Tools\ToolListResponse;
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

it('resolves to GET /api/tools', function (): void {
    $request = new ListToolsRequest;

    expect($request->resolveEndpoint())->toBe('/api/tools');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes app and node filters when provided', function (): void {
    $request = new ListToolsRequest(app: 'docs', node: 'app-1');

    expect($request->query()->all())->toBe([
        'app' => 'docs',
        'node' => 'app-1',
    ]);
});

it('omits null filters from the query', function (): void {
    $request = new ListToolsRequest(node: 'app-1');

    expect($request->query()->all())->toBe(['node' => 'app-1']);
});

it('returns a ToolListResponse DTO with tools', function (): void {
    $mock = new MockClient([
        ListToolsRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'tools' => [
                        ['name' => 'composer', 'node' => 'app-1'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListToolsRequest)->dto();

    expect($dto)->toBeInstanceOf(ToolListResponse::class);
    expect($dto->tools)->toBe([
        ['name' => 'composer', 'node' => 'app-1'],
    ]);
});
