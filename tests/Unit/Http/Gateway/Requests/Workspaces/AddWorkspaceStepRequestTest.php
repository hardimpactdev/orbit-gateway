<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Workspaces;

use App\Enums\WorkspaceLifecyclePhase;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Workspaces\AddWorkspaceStepRequest;
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

it('resolves to POST /api/workspaces/steps/{phase}', function (): void {
    $request = new AddWorkspaceStepRequest(
        phase: WorkspaceLifecyclePhase::Setup,
        command: 'composer install',
        timeout: 600,
        app: 'docs',
        before: 12,
    );

    expect($request->resolveEndpoint())->toBe('/api/workspaces/steps/setup');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'app' => 'docs',
        'command' => 'composer install',
        'timeout' => 600,
        'before' => 12,
    ]);
});

it('returns a WorkspaceStepMutationResponse DTO', function (): void {
    $mock = new MockClient([
        AddWorkspaceStepRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'added'],
                    'step' => ['id' => 12, 'app' => 'docs', 'phase' => 'setup'],
                ],
                'meta' => [],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new AddWorkspaceStepRequest(
        phase: WorkspaceLifecyclePhase::Setup,
        command: 'composer install',
        timeout: 600,
        app: 'docs',
    ))->dto();

    expect($dto)->toBeInstanceOf(WorkspaceStepMutationResponse::class);
    expect($dto->result)->toBe(['action' => 'added']);
    expect($dto->step)->toBe(['id' => 12, 'app' => 'docs', 'phase' => 'setup']);
});
