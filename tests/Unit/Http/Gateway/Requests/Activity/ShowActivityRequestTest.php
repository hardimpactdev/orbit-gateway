<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Activity;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Activity\ShowActivityRequest;
use App\Http\Gateway\Responses\Activity\ActivityShowResponse;
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

it('resolves to GET /api/activity/{id}', function (): void {
    $request = new ShowActivityRequest(42);

    expect($request->resolveEndpoint())->toBe('/api/activity/42');
    expect($request->getMethod())->toBe(Method::GET);
});

it('returns an ActivityShowResponse DTO', function (): void {
    $mock = new MockClient([
        ShowActivityRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'activity' => [
                        'id' => 42,
                        'type' => 'node.created',
                    ],
                    'related' => [
                        ['id' => 41, 'type' => 'node.create_requested'],
                    ],
                ],
                'meta' => [
                    'related_count' => 1,
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowActivityRequest(42))->dto();

    expect($dto)->toBeInstanceOf(ActivityShowResponse::class);
    expect($dto->activity['id'])->toBe(42);
    expect($dto->related)->toBe([
        ['id' => 41, 'type' => 'node.create_requested'],
    ]);
    expect($dto->meta['related_count'])->toBe(1);
});
