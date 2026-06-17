<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Workspaces;

use App\Enums\WorkspaceLifecyclePhase;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\RemoveWorkspaceStepRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceStepMutationResponse;
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

it('resolves to DELETE /api/workspaces/steps/{phase}/{step}', function (): void {
    $request = new RemoveWorkspaceStepRequest(
        phase: WorkspaceLifecyclePhase::Setup,
        step: 12,
        app: 'docs',
    );

    expect($request->resolveEndpoint())->toBe('/api/workspaces/steps/setup/12');
    expect($request->getMethod())->toBe(Method::DELETE);
    expect($request->query()->all())->toBe(['app' => 'docs']);
    expect($request->body()->all())->toBe([
        'destructive_consent' => true,
        'destructive_consent_source' => 'force',
    ]);
});

it('returns a WorkspaceStepMutationResponse DTO', function (): void {
    $mock = new MockClient([
        RemoveWorkspaceStepRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'removed'],
                    'step' => ['id' => 12, 'app' => 'docs', 'phase' => 'setup'],
                ],
                'meta' => ['remaining_step_count' => 0],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new RemoveWorkspaceStepRequest(
        phase: WorkspaceLifecyclePhase::Setup,
        step: 12,
        app: 'docs',
    ))->dto();

    expect($dto)->toBeInstanceOf(WorkspaceStepMutationResponse::class);
    expect($dto->result)->toBe(['action' => 'removed']);
    expect($dto->step)->toBe(['id' => 12, 'app' => 'docs', 'phase' => 'setup']);
    expect($dto->meta)->toBe(['remaining_step_count' => 0]);
});
