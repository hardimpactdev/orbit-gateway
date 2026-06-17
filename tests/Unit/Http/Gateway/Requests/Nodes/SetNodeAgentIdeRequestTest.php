<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\SetNodeAgentIdeRequest;
use App\Http\Gateway\Responses\Nodes\NodeAgentIdeResponse;
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

it('resolves to POST /api/nodes/{name}/agent-ide with the adapter body', function (): void {
    $request = new SetNodeAgentIdeRequest('app-1', 'opencode');

    expect($request->resolveEndpoint())->toBe('/api/nodes/app-1/agent-ide');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe(['agent_ide' => 'opencode']);
});

it('returns a NodeAgentIdeResponse DTO', function (): void {
    $mock = new MockClient([
        SetNodeAgentIdeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'agent_ide' => [
                        'adapter' => 'opencode',
                        'source' => 'node',
                    ],
                    'action' => 'set',
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new SetNodeAgentIdeRequest('app-1', 'opencode'))->dto();

    expect($dto)->toBeInstanceOf(NodeAgentIdeResponse::class);
    expect($dto->name)->toBe('app-1');
    expect($dto->agentIde)->toBe([
        'adapter' => 'opencode',
        'source' => 'node',
    ]);
    expect($dto->action)->toBe('set');
});
