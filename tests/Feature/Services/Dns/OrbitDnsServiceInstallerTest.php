<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Dns\DnsmasqConfigBuilder;
use App\Services\Dns\OrbitDnsServiceInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->workdir = sys_get_temp_dir().'/orbit-dns-installer-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->workdir);
});

afterEach(function (): void {
    if (isset($this->workdir) && is_string($this->workdir) && is_dir($this->workdir)) {
        File::deleteDirectory($this->workdir);
    }
});

it('writes a compose file with network_mode container:wg-easy and no ports', function (): void {
    Process::fake([
        'docker ps*' => Process::result('wg-easy'),
        '*' => Process::result(),
    ]);

    (new OrbitDnsServiceInstaller(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    ))->install();

    $compose = File::get($this->workdir.'/docker-compose.yaml');

    expect($compose)->toContain('network_mode: "container:wg-easy"')
        ->and($compose)->toContain('4km3/dnsmasq:latest')
        ->and($compose)->toContain('cap_add:')
        ->and($compose)->toContain('NET_ADMIN')
        ->and($compose)->toContain('restart: unless-stopped')
        ->and($compose)->not->toContain('networks:')
        ->and($compose)->not->toContain('ports:');
});

it('keeps orbit-dns coupled to the wg-easy container runtime', function (): void {
    Process::fake([
        'docker ps*' => Process::result('wg-easy'),
        '*' => Process::result(),
    ]);

    (new OrbitDnsServiceInstaller(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    ))->install();

    $compose = File::get($this->workdir.'/docker-compose.yaml');

    expect($compose)->toContain('container_name: orbit-dns')
        ->and($compose)->toContain('network_mode: "container:wg-easy"')
        ->and($compose)->not->toContain('53:53')
        ->and($compose)->not->toContain('host:');
});

it('writes the initial dnsmasq.conf before starting the container', function (): void {
    Process::fake([
        'docker ps*' => Process::result('wg-easy'),
        '*' => Process::result(),
    ]);

    Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'app-1.test',
        'wireguard_address' => '10.6.0.3',
    ]);

    (new OrbitDnsServiceInstaller(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    ))->install();

    $conf = File::get($this->workdir.'/dnsmasq.conf');

    expect($conf)->toContain('address=/gateway/10.6.0.2')
        ->and($conf)->toContain('address=/app-1.test/10.6.0.3');
});

it('errors when wg-easy is not running', function (): void {
    Process::fake([
        'docker ps*' => Process::result(''),
    ]);

    expect(fn (): mixed => (new OrbitDnsServiceInstaller(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    ))->install())
        ->toThrow(RuntimeException::class, 'wg-easy');
});

it('invokes docker compose up after writing files', function (): void {
    Process::fake([
        'docker ps*' => Process::result('wg-easy'),
        '*' => Process::result(),
    ]);

    (new OrbitDnsServiceInstaller(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    ))->install();

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'docker compose')
        && str_contains((string) $process->command, 'up -d'));
});
