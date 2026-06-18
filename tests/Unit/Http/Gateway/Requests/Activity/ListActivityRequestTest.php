<?php

declare(strict_types=1);

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Activity\ListActivityRequest;
use App\Http\Gateway\Responses\Activity\ActivityListResponse;
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

it('targets the activity list endpoint with normalized query parameters', function (): void {
    $request = new ListActivityRequest(
        app: 'docs',
        node: 'app-1',
        effect: 'destructive',
        correlation: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        limit: 50,
    );

    expect($request->getMethod())->toBe(Method::GET)
        ->and($request->resolveEndpoint())->toBe('/api/activity')
        ->and($request->query()->all())->toBe([
            'app' => 'docs',
            'node' => 'app-1',
            'effect' => 'destructive',
            'correlation' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'limit' => 50,
        ]);
});

it('creates an activity list dto from the gateway envelope', function (): void {
    $connector = new GatewayConnector;
    $request = new ListActivityRequest(effect: 'destructive');

    $mock = new MockClient([
        ListActivityRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'activities' => [
                        [
                            'id' => 42,
                            'effect' => 'destructive',
                        ],
                    ],
                ],
                'meta' => [
                    'count' => 1,
                    'has_more' => false,
                ],
            ],
        ], 200),
    ]);

    $connector->withMockClient($mock);

    $dto = $connector->send($request)->dto();

    expect($dto)->toBeInstanceOf(ActivityListResponse::class)
        ->and($dto->activities)->toBe([
            [
                'id' => 42,
                'effect' => 'destructive',
            ],
        ])
        ->and($dto->meta)->toBe([
            'count' => 1,
            'has_more' => false,
        ]);
});
