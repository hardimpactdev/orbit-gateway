<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const METRICS_CRED_CALLER_WG_IP = '10.6.2.98';

function metricsCredCallerNode(string $role = 'gateway'): Node
{
    $node = Node::factory()->create([
        'name' => 'caller',
        'host' => METRICS_CRED_CALLER_WG_IP,
        'wireguard_address' => METRICS_CRED_CALLER_WG_IP,
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => NodeRoleStatus::Active,
    ]);

    return $node;
}

function metricsCredMetricsNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'metrics-1',
        'host' => '10.6.0.55',
        'wireguard_address' => '10.6.0.55',
        'platform' => 'ubuntu',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'metrics',
        'status' => NodeRoleStatus::Active,
    ]);

    return $node;
}

function metricsCredGrafanaProcess(Node $node, string $password = 'old-admin-password'): Process
{
    return Process::factory()->forOwner($node)->create([
        'name' => 'grafana',
        'command' => '/run.sh',
        'restart_policy' => ProcessRestartPolicy::Always,
        'runtime' => ProcessRuntime::DockerSwarm,
        'runtime_config' => [
            'definition' => 'grafana',
            'version_family' => '13',
            'version' => '13.0.2',
            'endpoint' => [
                'name' => 'grafana',
                'kind' => 'tcp',
                'host' => '10.6.0.55',
                'port' => 3000,
            ],
            'environment' => [
                'GF_SECURITY_ADMIN_USER' => 'admin',
                'GF_SECURITY_ADMIN_PASSWORD' => $password,
                'GF_SERVER_ROOT_URL' => 'https://metrics.orbit',
            ],
            'credentials' => [
                'admin_user' => 'admin',
                'admin_password' => $password,
                'url' => 'https://metrics.orbit',
            ],
            'labels' => [
                'orbit.process.spec_hash' => 'oldhash',
            ],
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $query
 */
function metricsCredGet(object $test, array $query = []): TestResponse
{
    $queryString = $query !== [] ? '?'.http_build_query($query) : '';

    return $test->get('/api/metrics/credentials'.$queryString, [
        'REMOTE_ADDR' => METRICS_CRED_CALLER_WG_IP,
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 */
function metricsCredReset(object $test, array $payload = []): TestResponse
{
    return $test->postJson('/api/metrics/credentials/reset', $payload, [
        'REMOTE_ADDR' => METRICS_CRED_CALLER_WG_IP,
    ]);
}

/**
 * @param  array<string, mixed>  $query
 */
function metricsStatusGet(object $test, array $query = []): TestResponse
{
    $queryString = $query !== [] ? '?'.http_build_query($query) : '';

    return $test->get('/api/metrics/status'.$queryString, [
        'REMOTE_ADDR' => METRICS_CRED_CALLER_WG_IP,
    ]);
}

describe('MetricsCredentials authorization', function (): void {
    it('rejects unauthenticated callers', function (): void {
        $response = $this->get('/api/metrics/credentials', [
            'REMOTE_ADDR' => '192.168.99.99',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('allows gateway-role callers to read Grafana credentials', function (): void {
        metricsCredCallerNode(role: 'gateway');
        $metrics = metricsCredMetricsNode();
        metricsCredGrafanaProcess($metrics);

        $response = metricsCredGet($this, ['node' => 'metrics-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.node', 'metrics-1')
            ->assertJsonPath('success.data.credentials.url', 'https://metrics.orbit')
            ->assertJsonPath('success.data.credentials.admin_user', 'admin')
            ->assertJsonPath('success.data.credentials.admin_password', 'old-admin-password')
            ->assertJsonPath('success.meta.process', 'grafana');
    });

    it('denies non-gateway callers without tool credentials grants', function (): void {
        metricsCredCallerNode(role: 'app-prod');
        $metrics = metricsCredMetricsNode();
        metricsCredGrafanaProcess($metrics);

        $response = metricsCredGet($this, ['node' => 'metrics-1']);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'tool:credentials')
            ->assertJsonPath('error.meta.serving_node', 'metrics-1')
            ->assertJsonPath('error.meta.process', 'grafana');
    });

    it('allows non-gateway callers with a tool credentials grant', function (): void {
        $caller = metricsCredCallerNode(role: 'app-prod');
        $metrics = metricsCredMetricsNode();
        metricsCredGrafanaProcess($metrics);

        DB::table('node_access')->insert([
            'consumer_node_id' => $caller->id,
            'serving_node_id' => $metrics->id,
            'permissions' => json_encode(['tool:credentials'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = metricsCredGet($this, ['node' => 'metrics-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.node', 'metrics-1');
    });
});

describe('MetricsStatus authorization', function (): void {
    it('allows gateway-role callers to read metrics status', function (): void {
        metricsCredCallerNode(role: 'gateway');
        $metrics = metricsCredMetricsNode();
        metricsCredGrafanaProcess($metrics);

        $response = metricsStatusGet($this, ['node' => 'metrics-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.metrics.0.node', 'metrics-1')
            ->assertJsonPath('success.data.metrics.0.processes.0.name', 'grafana');
    });

    it('denies explicit metrics status reads without process read grants', function (): void {
        metricsCredCallerNode(role: 'app-prod');
        metricsCredMetricsNode();

        $response = metricsStatusGet($this, ['node' => 'metrics-1']);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'process:read');
    });

    it('allows non-gateway callers with process read grants to read metrics status', function (): void {
        $caller = metricsCredCallerNode(role: 'app-prod');
        $metrics = metricsCredMetricsNode();

        DB::table('node_access')->insert([
            'consumer_node_id' => $caller->id,
            'serving_node_id' => $metrics->id,
            'permissions' => json_encode(['process:read'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = metricsStatusGet($this, ['node' => 'metrics-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.metrics.0.node', 'metrics-1');
    });
});

describe('MetricsCredentials payload and rotation', function (): void {
    it('auto-resolves the only active metrics node', function (): void {
        metricsCredCallerNode(role: 'gateway');
        $metrics = metricsCredMetricsNode();
        metricsCredGrafanaProcess($metrics);

        $response = metricsCredGet($this);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.node', 'metrics-1');
    });

    it('reports missing credentials with a process doctor next command', function (): void {
        metricsCredCallerNode(role: 'gateway');
        metricsCredMetricsNode();

        $response = metricsCredGet($this, ['node' => 'metrics-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'metrics.credentials_missing')
            ->assertJsonPath('error.meta.next_command', 'doctor --family=process --restore --node=metrics-1');
    });

    it('resets the Grafana admin password and updates runtime config consistently', function (): void {
        metricsCredCallerNode(role: 'gateway');
        $metrics = metricsCredMetricsNode();
        metricsCredGrafanaProcess($metrics, password: 'old-admin-password');

        $response = metricsCredReset($this, ['node' => 'metrics-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.node', 'metrics-1')
            ->assertJsonPath('success.data.credentials.admin_user', 'admin');

        $newPassword = $response->json('success.data.credentials.admin_password');
        $process = Process::query()->where('node_id', $metrics->id)->where('name', 'grafana')->sole();
        $runtimeConfig = $process->runtime_config;

        expect($newPassword)->toBeString()
            ->not->toBe('old-admin-password')
            ->and($runtimeConfig['credentials']['admin_password'])->toBe($newPassword)
            ->and($runtimeConfig['environment']['GF_SECURITY_ADMIN_PASSWORD'])->toBe($newPassword)
            ->and($runtimeConfig['labels']['orbit.process.spec_hash'])->not->toBe('oldhash');
    });
});
