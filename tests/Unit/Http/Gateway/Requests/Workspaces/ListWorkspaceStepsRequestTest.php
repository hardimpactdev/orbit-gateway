<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Workspaces;

use App\Enums\WorkspaceLifecyclePhase;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\ListWorkspaceStepsRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceStepListResponse;
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

it('resolves to GET /api/workspaces/steps/{phase}', function (): void {
    $request = new ListWorkspaceStepsRequest(phase: WorkspaceLifecyclePhase::Setup, app: 'docs');

    expect($request->resolveEndpoint())->toBe('/api/workspaces/steps/setup');
    expect($request->getMethod())->toBe(Method::GET);
    expect($request->query()->all())->toBe(['app' => 'docs']);
});

it('serializes path lookups when no app is provided', function (): void {
    $request = new ListWorkspaceStepsRequest(
        phase: WorkspaceLifecyclePhase::Teardown,
        path: '/srv/docs/.worktrees/feature-docs',
    );

    expect($request->resolveEndpoint())->toBe('/api/workspaces/steps/teardown');
    expect($request->query()->all())->toBe(['path' => '/srv/docs/.worktrees/feature-docs']);
});

it('returns a WorkspaceStepListResponse DTO', function (): void {
    $mock = new MockClient([
        ListWorkspaceStepsRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'steps' => [
                        ['id' => 12, 'app' => 'docs', 'phase' => 'setup'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListWorkspaceStepsRequest(phase: WorkspaceLifecyclePhase::Setup, app: 'docs'))->dto();

    expect($dto)->toBeInstanceOf(WorkspaceStepListResponse::class);
    expect($dto->steps)->toBe([
        ['id' => 12, 'app' => 'docs', 'phase' => 'setup'],
    ]);
});
