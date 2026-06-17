<?php

declare(strict_types=1);

use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\OrbitHostInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('copies bootstrap authorized keys to the target orbit user before installing orbit', function (): void {
    Process::fake(fn () => Process::result());

    app(OrbitHostInstaller::class)->install('192.0.2.10', 'root', 'orbit');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "'root'@'192.0.2.10'")
        && str_contains((string) $process->command, 'BOOTSTRAP_KEYS="/root/.ssh/authorized_keys"')
        && str_contains((string) $process->command, 'TARGET_KEYS="/home/$USER/.ssh/authorized_keys"')
        && str_contains((string) $process->command, 'sudo grep -qxF "$key" "$TARGET_KEYS"'));
});

it('runs the pre-wireguard node security baseline over pinned ssh during provisioning', function (): void {
    $node = Node::factory()->create([
        'host' => '203.0.113.20',
        'wireguard_address' => '10.6.0.20',
        'user' => 'orbit',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
        'host_key_fingerprint' => 'SHA256:test',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ]);

    Process::fake(fn () => Process::result());

    $installer = app(OrbitHostInstaller::class);
    $installer->usePinnedNode($node);

    $result = $installer->install('203.0.113.20', 'ubuntu', 'orbit');

    expect($result->successful)->toBeTrue()
        ->and(FirewallRule::query()->where('node_id', $node->id)->where('owner', 'node-security')->count())->toBe(0);

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, '-o StrictHostKeyChecking=yes')
        && str_contains((string) $process->command, "'ubuntu'@'203.0.113.20'")
        && str_contains((string) $process->command, 'sudo useradd -m -s /bin/bash "$USER"'));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, '-o StrictHostKeyChecking=yes')
        && str_contains((string) $process->command, "'orbit'@'203.0.113.20'")
        && str_contains((string) $process->command, '/etc/sysctl.d/60-orbit.conf'));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "'orbit'@'203.0.113.20'")
        && str_contains((string) $process->command, 'ListenAddress 10.6.0.20'));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "'orbit'@'203.0.113.20'")
        && str_contains((string) $process->command, 'unattended-upgrades'));

    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'ufw deny in on "$PUBLIC_IFACE"'));
});

it('stages node identity through a temporary remote env file without operation token signing material', function (): void {
    config()->set('app.key', 'shared-app-key');

    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'host' => '203.0.113.30',
        'wireguard_address' => '10.6.0.30',
        'user' => 'orbit',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
        'host_key_fingerprint' => 'SHA256:test',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ]);

    Process::fake(fn () => Process::result());

    $installer = app(OrbitHostInstaller::class);
    $installer->usePinnedNode($node);

    $result = $installer->install('203.0.113.30', 'ubuntu', 'orbit');

    expect($result->successful)->toBeTrue();

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'scp')
        && str_contains((string) $process->command, '.env')
        && str_contains((string) $process->command, "'orbit'@'203.0.113.30'"));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'set -a; . ')
        && str_contains((string) $process->command, '--source-archive='));

    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'shared-app-key'));
});

it('forwards local gateway and dependency image archives to install-orbit when enabled for archive-seeded provisioning', function (): void {
    config()->set('orbit.forward_install_image_archives', true);

    Process::fake(fn () => Process::result());

    $result = app(OrbitHostInstaller::class)->install('192.0.2.20', 'root', 'orbit');

    expect($result->successful)->toBeTrue();

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'docker image inspect')
        && str_contains((string) $process->command, "'orbit-gateway:current'"));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'docker save')
        && str_contains((string) $process->command, "'orbit-gateway:current'")
        && str_contains((string) $process->command, '/var/tmp/orbit-gateway-current-'));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'docker save')
        && str_contains((string) $process->command, "'caddy:2-alpine'")
        && str_contains((string) $process->command, '/var/tmp/caddy-2-alpine-'));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'docker save')
        && str_contains((string) $process->command, "'4km3/dnsmasq:latest'")
        && str_contains((string) $process->command, '/var/tmp/dnsmasq-latest-'));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'docker save')
        && str_contains((string) $process->command, "'dunglas/frankenphp:1-php8.5-bookworm'")
        && str_contains((string) $process->command, '/var/tmp/frankenphp-1-php8.5-bookworm-'));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'docker save')
        && str_contains((string) $process->command, "'ghcr.io/wg-easy/wg-easy:15'")
        && str_contains((string) $process->command, '/var/tmp/wg-easy-15-'));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'scp')
        && str_contains((string) $process->command, '/var/tmp/orbit-gateway-current-')
        && str_contains((string) $process->command, "'root'@'192.0.2.20'"));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, '--gateway-image=orbit-gateway:current')
        && str_contains((string) $process->command, '--gateway-image-archive=')
        && str_contains((string) $process->command, '--caddy-image-archive=')
        && str_contains((string) $process->command, '--dnsmasq-image-archive=')
        && str_contains((string) $process->command, '--frankenphp-image-archive=')
        && str_contains((string) $process->command, '--wg-easy-image-archive=')
        && ! preg_match('/(^|\s)--gateway(\s|$)/', (string) $process->command));
});

it('forwards a configured local cli binary so workload installs do not require gh', function (): void {
    $binary = tempnam(sys_get_temp_dir(), 'orbit-binary-');

    file_put_contents($binary, '#!/bin/sh');
    chmod($binary, 0755);

    config()->set('orbit.forward_install_binary', $binary);

    Process::fake(fn () => Process::result());

    try {
        $result = app(OrbitHostInstaller::class)->install('192.0.2.22', 'root', 'orbit');
    } finally {
        @unlink($binary);
    }

    expect($result->successful)->toBeTrue();

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'scp')
        && str_contains((string) $process->command, $binary)
        && str_contains((string) $process->command, '-orbit-binary')
        && str_contains((string) $process->command, "'root'@'192.0.2.22'"));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'set -a; . ')
        && str_contains((string) $process->command, '-orbit-binary')
        && str_contains((string) $process->command, 'rm -f'));
});

it('stages installer transfer artifacts under var tmp instead of the small tmpfs', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $result = app(OrbitHostInstaller::class)->install('192.0.2.21', 'root', 'orbit');

    expect($result->successful)->toBeTrue();

    $output = implode("\n", $commands);

    expect($output)
        ->toContain('/var/tmp/orbit-source-')
        ->toContain("--exclude='./.env'")
        ->toContain("--exclude='./.env.e2e'")
        ->toContain("--exclude='./apps/gateway/.env'")
        ->toContain("--exclude='./apps/gateway/.env.e2e'")
        ->not->toContain("--exclude='.env.*'")
        ->not->toContain("--exclude='./.env.*'")
        ->not->toContain('apps/gateway/.env.example')
        ->toContain('/var/tmp/orbit-install-env-')
        ->toContain('/var/tmp/orbit-install-')
        ->not->toContain("-czf '/tmp/orbit-source-")
        ->not->toContain("'/tmp/orbit-install-env-")
        ->not->toContain("'/tmp/orbit-install-");
});
