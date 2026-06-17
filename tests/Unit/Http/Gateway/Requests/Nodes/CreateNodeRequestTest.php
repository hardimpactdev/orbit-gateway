<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\CreateNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeCreateResponse;
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

it('resolves canonical hosted-role forwarding to POST /api/nodes', function (): void {
    $request = new CreateNodeRequest(
        name: 'app-1',
        roles: ['app-dev'],
        host: '192.0.2.20',
        tld: 'test',
        user: 'provisioner',
    );

    expect($request->resolveEndpoint())->toBe('/api/nodes');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'name' => 'app-1',
        'roles' => ['app-dev'],
        'host' => '192.0.2.20',
        'tld' => 'test',
        'user' => 'provisioner',
    ]);
});

it('serializes explicit operator identity requests without a role value', function (): void {
    $request = new CreateNodeRequest(
        name: 'operator-1',
        roles: [],
        host: null,
        tld: null,
        user: null,
        operator: true,
    );

    expect($request->body()->all())->toBe([
        'name' => 'operator-1',
        'roles' => [],
        'host' => null,
        'tld' => null,
        'user' => null,
        'operator' => true,
    ]);
});

it('includes an expected host key fingerprint when supplied', function (): void {
    $request = new CreateNodeRequest(
        name: 'app-1',
        roles: ['app-prod'],
        host: '192.0.2.20',
        tld: null,
        user: 'ubuntu',
        hostKeyFingerprint: 'SHA256:expected',
    );

    expect($request->body()->all())->toBe([
        'name' => 'app-1',
        'roles' => ['app-prod'],
        'host' => '192.0.2.20',
        'tld' => null,
        'user' => 'ubuntu',
        'host_key_fingerprint' => 'SHA256:expected',
    ]);
});

it('returns a NodeCreateResponse DTO with gateway data', function (): void {
    $mock = new MockClient([
        CreateNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'node' => [
                        'name' => 'app-1',
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new CreateNodeRequest('app-1', ['app-dev'], '192.0.2.20', 'test', 'orbit'))->dto();

    expect($dto)->toBeInstanceOf(NodeCreateResponse::class);
    expect($dto->data)->toBe([
        'node' => [
            'name' => 'app-1',
        ],
    ]);
});
