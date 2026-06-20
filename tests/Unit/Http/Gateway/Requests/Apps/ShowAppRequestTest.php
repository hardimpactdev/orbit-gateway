<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Apps;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\ShowAppRequest;
use App\Http\Gateway\Responses\Apps\AppShowResponse;
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

it('resolves to GET /api/apps/{app}', function (): void {
    $request = new ShowAppRequest('docs app');

    expect($request->resolveEndpoint())->toBe('/api/apps/docs%20app');
    expect($request->getMethod())->toBe(Method::GET);
});

it('returns an AppShowResponse DTO with app and details', function (): void {
    $mock = new MockClient([
        ShowAppRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'app' => ['name' => 'docs'],
                    'details' => ['workspaces' => []],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowAppRequest('docs'))->dto();

    expect($dto)->toBeInstanceOf(AppShowResponse::class)
        ->and($dto->app)->toBe(['name' => 'docs'])
        ->and($dto->details)->toBe(['workspaces' => []]);
});
