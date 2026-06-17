<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\GrantNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeGrantResponse;
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

it('resolves to POST /api/nodes/grant with consuming/serving body', function (): void {
    $request = new GrantNodeRequest('app-1', 'gw-1');

    expect($request->resolveEndpoint())->toBe('/api/nodes/grant');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'consuming_node' => 'app-1',
        'serving_node' => 'gw-1',
    ]);
});

it('returns a NodeGrantResponse DTO with consuming/serving/already_granted', function (): void {
    $mock = new MockClient([
        GrantNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'consuming_node' => 'app-1',
                    'serving_node' => 'gw-1',
                    'already_granted' => false,
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new GrantNodeRequest('app-1', 'gw-1'))->dto();

    expect($dto)->toBeInstanceOf(NodeGrantResponse::class);
    expect($dto->consumingNode)->toBe('app-1');
    expect($dto->servingNode)->toBe('gw-1');
    expect($dto->alreadyGranted)->toBeFalse();
});
