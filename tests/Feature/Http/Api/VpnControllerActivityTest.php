<?php

declare(strict_types=1);

use App\Data\Vpn\VpnBackendClient;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Services\Vpn\ArrayVpnBackend;
use App\Services\Vpn\VpnBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('logs vpn api activity with safe metadata', function (): void {
    $node = createTestGatewayNode([
        'name' => 'gateway-1',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'vpn',
        'status' => 'active']);

    app()->instance(VpnBackend::class, new ArrayVpnBackend([
        new VpnBackendClient('client-1', 'laptop', '10.6.0.7', true, null)]));

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.6.0.2'])
        ->getJson('/api/vpn/clients?totp=123456')
        ->assertSuccessful()
        ->assertJsonPath('success.meta.count', 1);

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull();
    expect($entry->event)->toBe('api:GET /api/vpn/clients');
    expect($entry->properties->get('type'))->toBe('read');
    expect($entry->properties->get('method'))->toBe('GET');
    expect($entry->properties->get('path'))->toBe('api/vpn/clients');
    expect(json_encode($entry->properties->toArray(), JSON_THROW_ON_ERROR))->not->toContain('123456');
});

it('returns vpn runtime unavailable when no active vpn role node exists', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active']);

    app()->instance(VpnBackend::class, new ArrayVpnBackend);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.6.0.2'])
        ->getJson('/api/vpn/clients')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'vpn_runtime_unavailable')
        ->assertJsonPath('error.message', 'No active VPN role node is available for VPN administration.');
});

it('uses the configured fake backend without requiring an active vpn role node', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active']);

    $backendPath = storage_path('framework/testing/vpn-api-fake-'.bin2hex(random_bytes(6)).'.json');
    File::ensureDirectoryExists(dirname($backendPath));
    File::put($backendPath, json_encode([
        'clients' => [
            [
                'id' => 'client-1',
                'name' => 'laptop',
                'address' => '10.6.0.7',
                'enabled' => true,
                'latest_handshake_at' => null]]], JSON_THROW_ON_ERROR));
    config(['services.wg_easy.fake_backend_path' => $backendPath]);

    try {
        $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.2'])
            ->getJson('/api/vpn/clients')
            ->assertSuccessful()
            ->assertJsonPath('success.meta.count', 1)
            ->assertJsonPath('success.data.clients.0.name', 'laptop');
    } finally {
        File::delete($backendPath);
    }
});

it('requires vpn read grants for non-gateway api callers', function (): void {
    $gateway = createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'vpn',
        'status' => 'active']);
    Node::factory()->create([
        'name' => 'control-1',
        'wireguard_address' => '10.6.0.9',
        'status' => 'active']);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.6.0.9'])
        ->getJson('/api/vpn/clients')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', 'vpn:read')
        ->assertJsonPath('error.meta.serving_node', 'gateway-1');
});

it('allows non-gateway api callers with vpn read grants', function (): void {
    $gateway = createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'vpn',
        'status' => 'active']);
    $caller = Node::factory()->create([
        'name' => 'control-1',
        'wireguard_address' => '10.6.0.9',
        'status' => 'active']);
    NodeAccess::query()->create([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $gateway->id,
        'permissions' => ['vpn:read'],
        'custom_permissions' => ['vpn:read']]);
    app()->instance(VpnBackend::class, new ArrayVpnBackend([
        new VpnBackendClient('client-1', 'laptop', '10.6.0.7', true, null)]));

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.6.0.9'])
        ->getJson('/api/vpn/clients')
        ->assertOk()
        ->assertJsonPath('success.data.clients.0.name', 'laptop');
});

it('enables and disables vpn clients through independent endpoints', function (): void {
    $gateway = createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'vpn',
        'status' => 'active']);
    app()->instance(VpnBackend::class, new ArrayVpnBackend([
        new VpnBackendClient('client-1', 'laptop', '10.6.0.7', false, null)]));

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.6.0.2'])
        ->postJson('/api/vpn/clients/laptop/enable')
        ->assertOk()
        ->assertJsonPath('success.data.client.name', 'laptop')
        ->assertJsonPath('success.data.client.enabled', true)
        ->assertJsonPath('success.data.client.action', 'enabled')
        ->assertJsonPath('success.data.client.already_enabled', false);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.6.0.2'])
        ->postJson('/api/vpn/clients/laptop/disable')
        ->assertOk()
        ->assertJsonPath('success.data.client.name', 'laptop')
        ->assertJsonPath('success.data.client.enabled', false)
        ->assertJsonPath('success.data.client.action', 'disabled')
        ->assertJsonPath('success.data.client.already_disabled', false);
});

it('requires vpn write grants for non-gateway api writes', function (): void {
    $gateway = createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'vpn',
        'status' => 'active']);
    Node::factory()->create([
        'name' => 'control-1',
        'wireguard_address' => '10.6.0.9',
        'status' => 'active']);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.6.0.9'])
        ->postJson('/api/vpn/clients', ['name' => 'laptop'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', 'vpn:write')
        ->assertJsonPath('error.meta.serving_node', 'gateway-1');
});
