<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const AGENT_IDE_CHOICES_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function agentIdeChoicesNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'control-1',
        'host' => AGENT_IDE_CHOICES_CALLER_WG_IP,
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => AGENT_IDE_CHOICES_CALLER_WG_IP,
        'public_ipv4' => null,
        'public_ipv6' => null,
        'agent_ide_config' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function getAgentIdeChoicesJson(string $scope): TestResponse
{
    return test()->call(
        'GET',
        '/api/agent-ide/adapters?scope='.rawurlencode($scope),
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => AGENT_IDE_CHOICES_CALLER_WG_IP,
        ],
    );
}

it('returns gateway adapter choices for node scope without credentials or session data', function (): void {
    DB::table('nodes')->insert(agentIdeChoicesNodeRow());

    $response = getAgentIdeChoicesJson('node');

    $response->assertOk()
        ->assertJson([
            'success' => [
                'data' => [
                    'scope' => 'node',
                    'reserved_tokens' => ['none'],
                    'adapters' => [
                        [
                            'name' => 'opencode',
                            'label' => 'opencode',
                            'source' => 'core',
                            'capabilities' => ['message_delivery', 'workspace_path_resolution'],
                        ],
                        [
                            'name' => 'polyscope',
                            'label' => 'polyscope',
                            'source' => 'core',
                            'capabilities' => ['message_delivery', 'workspace_path_resolution'],
                        ],
                    ],
                ],
            ],
        ])
        ->assertJsonMissingPath('success.data.adapters.0.credentials')
        ->assertJsonMissingPath('success.data.adapters.0.sessions');
});

it('returns app scope reserved tokens before registered adapters', function (): void {
    DB::table('nodes')->insert(agentIdeChoicesNodeRow());

    $response = getAgentIdeChoicesJson('app');

    $response->assertOk()
        ->assertJsonPath('success.data.scope', 'app')
        ->assertJsonPath('success.data.reserved_tokens', ['inherit', 'none'])
        ->assertJsonPath('success.data.adapters.0.name', 'opencode')
        ->assertJsonPath('success.data.adapters.1.name', 'polyscope');
});

it('rejects unsupported adapter choice scopes', function (): void {
    DB::table('nodes')->insert(agentIdeChoicesNodeRow());

    $response = getAgentIdeChoicesJson('workspace');

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.message', 'Agent IDE adapter scope is not supported.')
        ->assertJsonPath('error.meta.scope', 'workspace')
        ->assertJsonPath('error.meta.supported', ['node', 'app']);
});
