<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Dns\DnsmasqConfigBuilder;
use App\Services\Doctor\DnsRuntimeProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->workdir = sys_get_temp_dir().'/orbit-dns-probe-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->workdir);

    $this->probe = new DnsRuntimeProbe(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    );
});

afterEach(function (): void {
    if (isset($this->workdir) && is_string($this->workdir) && is_dir($this->workdir)) {
        File::deleteDirectory($this->workdir);
    }
});

it('reports dns.container_missing when orbit-dns is absent', function (): void {
    Process::fake([
        'docker ps*' => Process::result(''),
    ]);

    $drift = $this->probe->probe();

    expect($drift)->toHaveCount(1)
        ->and($drift[0]->key)->toBe('dns.container_missing');
});

it('reports dns.port_not_listening when port 53 is silent', function (): void {
    Process::fake([
        'docker ps*' => Process::result('orbit-dns-id'),
        'docker exec orbit-dns sh*' => Process::result(''),
    ]);

    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    $expected = (new DnsmasqConfigBuilder)->build(Node::query()->get());
    File::put($this->workdir.'/dnsmasq.conf', $expected);

    $drift = $this->probe->probe();

    expect(collect($drift)->pluck('key')->all())->toContain('dns.port_not_listening');
});

it('reports dns.config_drift when on-disk dnsmasq.conf differs from intent', function (): void {
    Process::fake([
        'docker ps*' => Process::result('orbit-dns-id'),
        'docker exec orbit-dns sh*' => Process::result('udp 0 0 :::53 :::* LISTEN'),
    ]);

    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    File::put($this->workdir.'/dnsmasq.conf', "stale content\n");

    $drift = $this->probe->probe();

    expect(collect($drift)->pluck('key')->all())->toContain('dns.config_drift');
});

it('does not report drift when runtime is healthy and config matches intent', function (): void {
    Process::fake([
        'docker ps*' => Process::result('orbit-dns-id'),
        'docker exec orbit-dns sh*' => Process::result('udp 0 0 :::53 :::* LISTEN'),
    ]);

    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    $expected = (new DnsmasqConfigBuilder)->build(Node::query()->get());
    File::put($this->workdir.'/dnsmasq.conf', $expected);

    $drift = $this->probe->probe();

    expect($drift)->toBe([]);
});

it('marks the three drift kinds as restorable', function (): void {
    expect($this->probe->isRestorable('dns.container_missing'))->toBeTrue()
        ->and($this->probe->isRestorable('dns.port_not_listening'))->toBeTrue()
        ->and($this->probe->isRestorable('dns.config_drift'))->toBeTrue()
        ->and($this->probe->isRestorable('dns.unknown'))->toBeFalse();
});

it('marks only config_drift as adoptable', function (): void {
    expect($this->probe->isAdoptable('dns.config_drift'))->toBeTrue()
        ->and($this->probe->isAdoptable('dns.container_missing'))->toBeFalse()
        ->and($this->probe->isAdoptable('dns.port_not_listening'))->toBeFalse();
});

it('restores config drift by rewriting dnsmasq.conf and restarting orbit-dns', function (): void {
    Process::fake([
        'docker restart orbit-dns' => Process::result(),
    ]);

    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    File::put($this->workdir.'/dnsmasq.conf', "stale\n");

    $result = $this->probe->restore('dns.config_drift');

    expect($result)->toBeTrue()
        ->and(File::get($this->workdir.'/dnsmasq.conf'))->toContain('address=/gateway/10.6.0.2');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'docker restart orbit-dns'));
});
