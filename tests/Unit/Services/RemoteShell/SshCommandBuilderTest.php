<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\RemoteShell\Exceptions\HostKeyMissing;
use App\Services\RemoteShell\SshCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('builds ssh commands with the current accept-new baseline', function (): void {
    $command = app(SshCommandBuilder::class)->ssh(
        user: 'orbit',
        host: '10.6.0.2',
        remoteCommand: 'bash -lc '.escapeshellarg('php artisan about'),
        options: [
            'batch_mode' => true,
            'log_level' => 'ERROR',
            'server_alive_interval' => 30,
            'server_alive_count_max' => 10,
        ],
    );

    expect($command)->toContain('ssh -o BatchMode=yes')
        ->and($command)->toContain('-o StrictHostKeyChecking=accept-new')
        ->and($command)->toContain('-o LogLevel=ERROR')
        ->and($command)->toContain('-o ConnectTimeout=10')
        ->and($command)->toContain('-o ServerAliveInterval=30')
        ->and($command)->toContain('-o ServerAliveCountMax=10')
        ->and($command)->toContain("'orbit'@'10.6.0.2'")
        ->and($command)->toContain(escapeshellarg('bash -lc '.escapeshellarg('php artisan about')));
});

it('builds ssh commands for nodes through their wireguard address and steady-state user', function (): void {
    $node = Node::factory()->create([
        'host' => 'public.example.com',
        'wireguard_address' => '10.6.0.4',
        'user' => 'deploy',
    ]);

    $command = app(SshCommandBuilder::class)->sshForNode(
        node: $node,
        remoteCommand: 'hostname',
    );

    expect($command)->toContain("'deploy'@'10.6.0.4'")
        ->and($command)->not->toContain("'deploy'@'public.example.com'");
});

it('builds scp uploads with the same ssh option baseline', function (): void {
    $command = app(SshCommandBuilder::class)->scpTo(
        source: '/tmp/orbit.tgz',
        user: 'root',
        host: '203.0.113.10',
        destination: '/tmp/orbit.tgz',
        options: ['batch_mode' => true],
    );

    expect($command)->toBe("scp -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 '/tmp/orbit.tgz' 'root'@'203.0.113.10':'/tmp/orbit.tgz'");
});

it('enforces pinned host keys for node ssh commands', function (): void {
    $node = Node::factory()->create([
        'host' => 'public.example.com',
        'wireguard_address' => '10.6.0.12',
        'user' => 'orbit',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAITestPinnedPublicKey',
        'host_key_fingerprint' => 'SHA256:pinned',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ]);

    $command = app(SshCommandBuilder::class)->enforceForNode(
        node: $node,
        remoteCommand: 'hostname',
    );

    expect($command)->toContain('-o StrictHostKeyChecking=yes')
        ->and($command)->toContain('-o UserKnownHostsFile=')
        ->and($command)->toContain('-o GlobalKnownHostsFile=')
        ->and($command)->toContain("'orbit'@'10.6.0.12'")
        ->and($command)->not->toContain('accept-new');
});

it('stores generated known hosts outside shared app storage', function (): void {
    $node = Node::factory()->create([
        'host' => 'public.example.com',
        'wireguard_address' => '10.6.0.12',
        'user' => 'orbit',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAITestPinnedPublicKey',
        'host_key_fingerprint' => 'SHA256:pinned',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ]);

    $command = app(SshCommandBuilder::class)->enforceForNode(
        node: $node,
        remoteCommand: 'hostname',
    );

    expect($command)
        ->toContain(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).'/orbit-ssh-known-hosts-')
        ->not->toContain(storage_path('framework/ssh-known-hosts'));
});

it('can enforce pinned host keys for the bootstrap user over the public host', function (): void {
    $node = Node::factory()->create([
        'host' => 'public.example.com',
        'wireguard_address' => '10.6.0.12',
        'user' => 'orbit',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAITestPinnedPublicKey',
        'host_key_fingerprint' => 'SHA256:pinned',
        'host_key_pin_mode' => 'tofu',
        'host_key_pinned_at' => now(),
    ]);

    $command = app(SshCommandBuilder::class)->enforceForNode(
        node: $node,
        remoteCommand: 'id -u orbit',
        loginUser: 'ubuntu',
        options: ['prefer_public_host' => true],
    );

    expect($command)->toContain("'ubuntu'@'public.example.com'")
        ->and($command)->not->toContain("'orbit'@'10.6.0.12'");
});

it('fails closed when node host key material is missing', function (): void {
    $node = Node::factory()->create([
        'host_key_type' => null,
        'host_key_public' => null,
        'host_key_fingerprint' => null,
    ]);

    expect(fn () => app(SshCommandBuilder::class)->enforceForNode($node, 'hostname'))
        ->toThrow(HostKeyMissing::class);
});
