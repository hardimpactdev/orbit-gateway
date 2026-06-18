<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Workspaces;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\ShowWorkspaceLogRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceLogResponse;
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

it('resolves to GET /api/workspaces/runs/{run}/log', function (): void {
    $request = new ShowWorkspaceLogRequest(run: 12);

    expect($request->resolveEndpoint())->toBe('/api/workspaces/runs/12/log');
    expect($request->getMethod())->toBe(Method::GET);
});

it('returns a WorkspaceLogResponse DTO with run and meta payloads', function (): void {
    $mock = new MockClient([
        ShowWorkspaceLogRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'run' => [
                        'id' => 12,
                        'workspace' => 'feature-docs',
                        'steps' => [],
                    ],
                ],
                'meta' => [
                    'registry_only' => true,
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowWorkspaceLogRequest(run: 12))->dto();

    expect($dto)->toBeInstanceOf(WorkspaceLogResponse::class);
    expect($dto->run)->toBe([
        'id' => 12,
        'workspace' => 'feature-docs',
        'steps' => [],
    ]);
    expect($dto->meta)->toBe(['registry_only' => true]);
});
