<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Workspaces;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\ListWorkspacesRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceListResponse;
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

it('resolves to GET /api/workspaces', function (): void {
    $request = new ListWorkspacesRequest;

    expect($request->resolveEndpoint())->toBe('/api/workspaces');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes app and node filters when provided', function (): void {
    $request = new ListWorkspacesRequest(app: 'docs', node: 'app-1');

    expect($request->query()->all())->toBe([
        'app' => 'docs',
        'node' => 'app-1',
    ]);
});

it('omits null filters from the query', function (): void {
    $request = new ListWorkspacesRequest(node: 'app-1');

    expect($request->query()->all())->toBe(['node' => 'app-1']);
});

it('returns a WorkspaceListResponse DTO with workspaces', function (): void {
    $mock = new MockClient([
        ListWorkspacesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'workspaces' => [
                        ['name' => 'feature-docs', 'app' => 'docs'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListWorkspacesRequest)->dto();

    expect($dto)->toBeInstanceOf(WorkspaceListResponse::class);
    expect($dto->workspaces)->toBe([
        ['name' => 'feature-docs', 'app' => 'docs'],
    ]);
});
