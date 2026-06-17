<?php

declare(strict_types=1);

use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EWireGuardMesh;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function e2eWireGuardMeshResult(bool $successful = true, string $output = '', string $errorOutput = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}

it('renders the gateway host config as a peer of wg-easy', function (): void {
    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: '10.231.0.11',
        wgEasyPublicKey: 'wg-easy-public',
        gatewayHostPrivateKey: 'gateway-host-private',
        gatewayHostPublicKey: 'gateway-host-public',
        operatorPrivateKey: 'operator-private',
        operatorPublicKey: 'operator-public',
        devPrivateKey: 'dev-private',
        devPublicKey: 'dev-public',
    );

    $config = $mesh->gatewayHostConfig();

    expect($config)->toContain('Address = 10.6.0.2/24')
        ->and($config)->toContain('PrivateKey = gateway-host-private')
        ->and($config)->toContain('PublicKey = wg-easy-public')
        ->and($config)->toContain('PresharedKey = ')
        ->and($config)->toContain('AllowedIPs = 10.6.0.0/24')
        ->and($config)->toContain('Endpoint = 10.231.0.11:51820')
        ->and($config)->toContain('PersistentKeepalive = 25')
        ->and($config)->not->toContain('ListenPort = 51820')
        ->and($config)->not->toContain('PublicKey = operator-public')
        ->and($config)->not->toContain('PublicKey = dev-public');
});

it('renders non-gateway peer configs against the wg-easy server key', function (): void {
    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: '10.231.0.11',
        wgEasyPublicKey: 'wg-easy-public',
        gatewayHostPrivateKey: 'gateway-host-private',
        gatewayHostPublicKey: 'gateway-host-public',
        operatorPrivateKey: 'operator-private',
        operatorPublicKey: 'operator-public',
        devPrivateKey: 'dev-private',
        devPublicKey: 'dev-public',
    );

    $config = $mesh->peerConfig('dev');

    expect($config)->toContain('Address = 10.6.0.4/24')
        ->and($config)->toContain('PrivateKey = dev-private')
        ->and($config)->toContain('PublicKey = wg-easy-public')
        ->and($config)->toContain('PresharedKey = ')
        ->and($config)->toContain('AllowedIPs = 10.6.0.0/24')
        ->and($config)->toContain('Endpoint = 10.231.0.11:51820')
        ->and($config)->toContain('PersistentKeepalive = 25')
        ->and($config)->not->toContain('ListenPort = 51820');
});

it('returns persistent wg-easy peer records for topology roles', function (): void {
    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: '10.231.0.11',
        wgEasyPublicKey: 'wg-easy-public',
        gatewayHostPrivateKey: 'gateway-host-private',
        gatewayHostPublicKey: 'gateway-host-public',
        operatorPrivateKey: 'operator-private',
        operatorPublicKey: 'operator-public',
        devPrivateKey: 'dev-private',
        devPublicKey: 'dev-public',
    );

    $peers = $mesh->wgEasyPeers();

    expect($peers)->toHaveCount(3)
        ->and($peers[0])->toMatchArray(['name' => 'gateway', 'private_key' => 'gateway-host-private', 'public_key' => 'gateway-host-public', 'address' => '10.6.0.2'])
        ->and($peers[0]['pre_shared_key'])->toBe(base64_encode(hash('sha256', 'orbit-e2e-gateway-host-public', binary: true)))
        ->and($peers[1])->toMatchArray(['name' => 'operator', 'private_key' => 'operator-private', 'public_key' => 'operator-public', 'address' => '10.6.0.3'])
        ->and($peers[1]['pre_shared_key'])->not->toBeEmpty()
        ->and($peers[2])->toMatchArray(['name' => 'dev', 'private_key' => 'dev-private', 'public_key' => 'dev-public', 'address' => '10.6.0.4'])
        ->and($peers[2]['pre_shared_key'])->not->toBeEmpty();
});

it('can include an agent peer with a stable WireGuard address', function (): void {
    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: '10.231.0.11',
        wgEasyPublicKey: 'wg-easy-public',
        gatewayHostPrivateKey: 'gateway-host-private',
        gatewayHostPublicKey: 'gateway-host-public',
        operatorPrivateKey: 'operator-private',
        operatorPublicKey: 'operator-public',
        agentPrivateKey: 'agent-private',
        agentPublicKey: 'agent-public',
    );

    $peers = $mesh->wgEasyPeers();

    expect($mesh->addressFor('agent'))->toBe('10.6.0.6')
        ->and($mesh->peerConfig('agent'))->toContain('Address = 10.6.0.6/24')
        ->and($peers)->toHaveCount(3)
        ->and($peers[2])->toMatchArray([
            'name' => 'agent',
            'private_key' => 'agent-private',
            'public_key' => 'agent-public',
            'address' => '10.6.0.6',
        ]);
});

it('can include an ingress peer with a stable WireGuard address', function (): void {
    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: '10.231.0.11',
        wgEasyPublicKey: 'wg-easy-public',
        gatewayHostPrivateKey: 'gateway-host-private',
        gatewayHostPublicKey: 'gateway-host-public',
        operatorPrivateKey: 'operator-private',
        operatorPublicKey: 'operator-public',
        ingressPrivateKey: 'ingress-private',
        ingressPublicKey: 'ingress-public',
    );

    $peers = $mesh->wgEasyPeers();

    expect($mesh->addressFor('ingress'))->toBe('10.6.0.7')
        ->and($mesh->peerConfig('ingress'))->toContain('Address = 10.6.0.7/24')
        ->and($peers)->toHaveCount(3)
        ->and($peers[2])->toMatchArray([
            'name' => 'ingress',
            'private_key' => 'ingress-private',
            'public_key' => 'ingress-public',
            'address' => '10.6.0.7',
        ]);
});

it('can include a websocket peer with a stable WireGuard address', function (): void {
    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: '10.231.0.11',
        wgEasyPublicKey: 'wg-easy-public',
        gatewayHostPrivateKey: 'gateway-host-private',
        gatewayHostPublicKey: 'gateway-host-public',
        operatorPrivateKey: 'operator-private',
        operatorPublicKey: 'operator-public',
        websocketPrivateKey: 'websocket-private',
        websocketPublicKey: 'websocket-public',
    );

    $peers = $mesh->wgEasyPeers();

    expect($mesh->addressFor('websocket'))->toBe('10.6.0.8')
        ->and($mesh->peerConfig('websocket'))->toContain('Address = 10.6.0.8/24')
        ->and($peers)->toHaveCount(3)
        ->and($peers[2])->toMatchArray([
            'name' => 'websocket',
            'private_key' => 'websocket-private',
            'public_key' => 'websocket-public',
            'address' => '10.6.0.8',
        ]);
});

it('installs and restarts wg-orbit for a role', function (): void {
    $commands = [];
    $instance = m::mock(E2EInstance::class);
    $instance->shouldReceive('name')->andReturn('dev');
    $instance->shouldReceive('exec')
        ->once()
        ->andReturnUsing(function (string $command) use (&$commands): ProcessResult {
            $commands[] = $command;

            return e2eWireGuardMeshResult();
        });

    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: '10.231.0.11',
        wgEasyPublicKey: 'wg-easy-public',
        gatewayHostPrivateKey: 'gateway-host-private',
        gatewayHostPublicKey: 'gateway-host-public',
        operatorPrivateKey: 'operator-private',
        operatorPublicKey: 'operator-public',
        devPrivateKey: 'dev-private',
        devPublicKey: 'dev-public',
    );

    $mesh->installRole($instance, 'dev');

    expect($commands[0])->toContain('/etc/wireguard/wg-orbit.conf')
        ->and($commands[0])->toContain('command -v wg')
        ->and($commands[0])->toContain('command -v wg-quick')
        ->and($commands[0])->not->toContain('apt-get')
        ->and($commands[0])->toContain('PrivateKey = dev-private')
        ->and($commands[0])->toContain('wg-quick down wg-orbit')
        ->and($commands[0])->toContain('wg-quick up wg-orbit')
        ->and($commands[0])->toContain('systemctl enable wg-quick@wg-orbit');
});

it('verifies a role interface and peer reachability', function (): void {
    $commands = [];
    $instance = m::mock(E2EInstance::class);
    $instance->shouldReceive('name')->andReturn('gateway');
    $instance->shouldReceive('exec')
        ->once()
        ->andReturnUsing(function (string $command) use (&$commands): ProcessResult {
            $commands[] = $command;

            return e2eWireGuardMeshResult();
        });

    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: '10.231.0.11',
        wgEasyPublicKey: 'wg-easy-public',
        gatewayHostPrivateKey: 'gateway-host-private',
        gatewayHostPublicKey: 'gateway-host-public',
        operatorPrivateKey: 'operator-private',
        operatorPublicKey: 'operator-public',
        devPrivateKey: 'dev-private',
        devPublicKey: 'dev-public',
    );

    $mesh->verifyRole($instance, 'gateway', ['operator', 'dev']);

    expect($commands[0])->toContain('deadline=$((SECONDS+60))')
        ->and($commands[0])->toContain('while true; do')
        ->and($commands[0])->toContain('ip link show wg-orbit')
        ->and($commands[0])->toContain('wg show wg-orbit')
        ->and($commands[0])->toContain("ping -c 1 -W 2 '10.6.0.3'")
        ->and($commands[0])->toContain("ping -c 1 -W 2 '10.6.0.4'");
});
