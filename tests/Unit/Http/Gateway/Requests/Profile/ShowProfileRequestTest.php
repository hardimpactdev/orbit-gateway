<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Profile;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Profile\ShowProfileRequest;
use App\Http\Gateway\Responses\Profile\ProfileResponse;
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

it('resolves to GET /api/profile with target query data', function (): void {
    $request = new ShowProfileRequest(
        target: 'docs',
        uri: '/login',
        authMode: 'user',
        user: '42',
    );

    expect($request->resolveEndpoint())->toBe('/api/profile');
    expect($request->getMethod())->toBe(Method::GET);
    expect($request->query()->all())->toBe([
        'target' => 'docs',
        'uri' => '/login',
        'auth_mode' => 'user',
        'user' => '42',
    ]);
});

it('returns a ProfileResponse DTO with profile data', function (): void {
    $mock = new MockClient([
        ShowProfileRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'origin' => 'gateway',
                    'request' => ['url' => 'https://docs.test/'],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowProfileRequest('docs'))->dto();

    expect($dto)->toBeInstanceOf(ProfileResponse::class)
        ->and($dto->data)->toBe([
            'origin' => 'gateway',
            'request' => ['url' => 'https://docs.test/'],
        ]);
});
