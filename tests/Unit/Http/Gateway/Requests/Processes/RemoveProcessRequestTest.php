<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Processes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Processes\RemoveProcessRequest;
use App\Http\Gateway\Responses\Processes\ProcessRemoveResponse;
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

it('resolves to DELETE /api/processes/{name}', function (): void {
    $request = new RemoveProcessRequest(app: 'docs', name: 'vite');

    expect($request->resolveEndpoint())->toBe('/api/processes/vite');
    expect($request->getMethod())->toBe(Method::DELETE);
});

it('serializes app and destructive consent body', function (): void {
    $request = new RemoveProcessRequest(app: 'docs', name: 'vite');

    expect($request->body()->all())->toBe([
        'app' => 'docs',
        'destructive_consent' => true,
        'destructive_consent_source' => 'force',
    ]);
});

it('returns a ProcessRemoveResponse DTO with warnings', function (): void {
    $mock = new MockClient([
        RemoveProcessRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'process' => ['name' => 'vite', 'app' => 'docs'],
                    'removed_runtime_units' => ['orbit_docs_main_vite'],
                ],
                'meta' => [
                    'warnings' => [
                        ['code' => 'process.runtime_unit_extra'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new RemoveProcessRequest(app: 'docs', name: 'vite'))->dto();

    expect($dto)->toBeInstanceOf(ProcessRemoveResponse::class);
    expect($dto->data['removed_runtime_units'])->toBe(['orbit_docs_main_vite']);
    expect($dto->warnings)->toBe([
        ['code' => 'process.runtime_unit_extra'],
    ]);
});
