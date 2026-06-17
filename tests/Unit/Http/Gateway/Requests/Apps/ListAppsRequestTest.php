<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Apps;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Apps\ListAppsRequest;
use App\Http\Gateway\Responses\Apps\AppListResponse;
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

it('resolves to GET /api/apps', function (): void {
    $request = new ListAppsRequest;

    expect($request->resolveEndpoint())->toBe('/api/apps');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes node and environment filters when provided', function (): void {
    $request = new ListAppsRequest(node: 'app-1', environment: 'production');

    expect($request->query()->all())->toBe([
        'node' => 'app-1',
        'environment' => 'production',
    ]);
});

it('omits null filters from the query', function (): void {
    $request = new ListAppsRequest(environment: 'development');

    expect($request->query()->all())->toBe(['environment' => 'development']);
});

it('returns an AppListResponse DTO with apps', function (): void {
    $mock = new MockClient([
        ListAppsRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'apps' => [
                        ['name' => 'docs', 'node' => 'app-1'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListAppsRequest)->dto();

    expect($dto)->toBeInstanceOf(AppListResponse::class);
    expect($dto->apps)->toBe([
        ['name' => 'docs', 'node' => 'app-1'],
    ]);
});
