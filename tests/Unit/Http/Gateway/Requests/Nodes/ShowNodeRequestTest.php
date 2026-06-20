<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\ShowNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeShowResponse;
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

it('resolves to GET /api/nodes/{name}', function (): void {
    $request = new ShowNodeRequest('gw-1');

    expect($request->resolveEndpoint())->toBe('/api/nodes/gw-1');
    expect($request->getMethod())->toBe(Method::GET);
});

it('returns a NodeShowResponse DTO with node array', function (): void {
    $mock = new MockClient([
        ShowNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'node' => [
                        'name' => 'gw-1',
                        'role' => 'gateway',
                        'status' => 'active',
                        'wireguard_address' => '10.6.0.2',
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowNodeRequest('gw-1'))->dto();

    expect($dto)->toBeInstanceOf(NodeShowResponse::class);
    expect($dto->node)->toMatchArray([
        'name' => 'gw-1',
        'role' => 'gateway',
        'status' => 'active',
        'wireguard_address' => '10.6.0.2',
    ]);
});
