<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Dns\DnsmasqConfigBuilder;
use App\Services\Dns\DnsmasqReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->workdir = sys_get_temp_dir().'/orbit-dns-reconciler-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->workdir);
    $this->confPath = $this->workdir.'/dnsmasq.conf';
});

afterEach(function (): void {
    if (isset($this->workdir) && is_string($this->workdir) && is_dir($this->workdir)) {
        File::deleteDirectory($this->workdir);
    }
});

it('writes dnsmasq.conf and restarts orbit-dns when state changes', function (): void {
    Process::fake([
        "docker service inspect 'orbit_orbit-dns'" => Process::result(exitCode: 1),
        'docker restart orbit-dns' => Process::result(),
    ]);

    Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);

    (new DnsmasqReconciler(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    ))->reconcile();

    expect(File::exists($this->confPath))->toBeTrue()
        ->and(File::get($this->confPath))->toContain('address=/gateway/10.6.0.2');

    Process::assertRan(fn ($process): bool => str_contains(
        (string) $process->command,
        'docker restart orbit-dns',
    ));
});

it('is a no-op when the on-disk config already matches state', function (): void {
    Process::fake();

    Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);

    $expected = (new DnsmasqConfigBuilder)->build(Node::query()->get());
    File::put($this->confPath, $expected);

    (new DnsmasqReconciler(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    ))->reconcile();

    Process::assertNothingRan();
});

it('rewrites the conf and restarts dns when fleet state changes', function (): void {
    Process::fake([
        "docker service inspect 'orbit_orbit-dns'" => Process::result(exitCode: 1),
        'docker restart orbit-dns' => Process::result(),
    ]);

    Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);

    $reconciler = new DnsmasqReconciler(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    );

    $reconciler->reconcile();

    Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'app-1.test',
        'wireguard_address' => '10.6.0.3',
    ]);

    $reconciler->reconcile();

    expect(File::get($this->confPath))->toContain('address=/app-1.test/10.6.0.3');
});

it('does not rewrite the compose topology while reconciling dns state', function (): void {
    Process::fake([
        "docker service inspect 'orbit_orbit-dns'" => Process::result(exitCode: 1),
        'docker restart orbit-dns' => Process::result(),
    ]);

    File::put($this->workdir.'/docker-compose.yaml', <<<'YAML'
services:
  orbit-dns:
    network_mode: "container:wg-easy"

YAML);

    Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);

    (new DnsmasqReconciler(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    ))->reconcile();

    expect(File::get($this->workdir.'/docker-compose.yaml'))->toContain('network_mode: "container:wg-easy"');
});

it('restarts the Swarm dns service when it exists', function (): void {
    Process::fake([
        "docker service inspect 'orbit_orbit-dns'" => Process::result(),
        "docker service update --force 'orbit_orbit-dns'" => Process::result(),
    ]);

    Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);

    (new DnsmasqReconciler(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    ))->reconcile();

    Process::assertRan("docker service update --force 'orbit_orbit-dns'");
    Process::assertNotRan('docker restart orbit-dns');
});
