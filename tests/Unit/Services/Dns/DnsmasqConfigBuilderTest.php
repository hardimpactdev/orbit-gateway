<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Dns\DnsmasqConfigBuilder;
use Illuminate\Database\Eloquent\Collection;

it('renders only the resolver baseline when fleet has no resolvable nodes', function (): void {
    $config = (new DnsmasqConfigBuilder)->build(new Collection);

    expect($config)->toContain('no-resolv')
        ->and($config)->toContain('server=1.1.1.1')
        ->and($config)->toContain('server=8.8.8.8')
        ->and($config)->toContain('conf-dir=/etc/dnsmasq.d/,*.conf')
        ->and($config)->toContain('log-queries')
        ->and($config)->toContain('log-facility=-')
        ->and($config)->not->toContain('address=/');
});

it('emits one address line per node with both tld and wireguard address', function (): void {
    $nodes = new Collection([
        new Node(['name' => 'gateway', 'tld' => 'gateway', 'wireguard_address' => '10.6.0.2']),
        new Node(['name' => 'app-1', 'tld' => 'app-1.test', 'wireguard_address' => '10.6.0.3']),
    ]);

    $config = (new DnsmasqConfigBuilder)->build($nodes);

    expect($config)->toContain('address=/gateway/10.6.0.2')
        ->and($config)->toContain('local=/gateway/')
        ->and($config)->toContain('address=/app-1.test/10.6.0.3')
        ->and($config)->toContain('local=/app-1.test/');
});

it('emits router-owned orbit service routes as an orbit tld mapping to the router wireguard address', function (): void {
    $router = new Node(['name' => 'gateway', 'tld' => null, 'wireguard_address' => '10.6.0.2']);
    $route = new ProxyRoute([
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);
    $route->setRelation('node', $router);

    $config = (new DnsmasqConfigBuilder)->build(new Collection([$router]), new Collection([$route]));

    expect($config)->toContain('address=/orbit/10.6.0.2')
        ->and($config)->toContain('local=/orbit/')
        ->and($config)->not->toContain('address=/metrics.orbit/');
});

it('emits a single private orbit tld mapping for multiple router-owned orbit service routes', function (): void {
    $router = new Node(['name' => 'gateway', 'tld' => null, 'wireguard_address' => '10.6.0.2']);
    $metricsRoute = new ProxyRoute([
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);
    $metricsRoute->setRelation('node', $router);
    $analyticsRoute = new ProxyRoute([
        'domain' => 'analytics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);
    $analyticsRoute->setRelation('node', $router);

    $config = (new DnsmasqConfigBuilder)->build(new Collection([$router]), new Collection([$metricsRoute, $analyticsRoute]));

    expect($config)->toContain('address=/orbit/10.6.0.2')
        ->and(substr_count($config, 'address=/orbit/10.6.0.2'))->toBe(1);
});

it('resolves router-owned orbit service routes without requiring a loaded node relation', function (): void {
    $router = new Node(['name' => 'gateway', 'tld' => null, 'wireguard_address' => '10.6.0.2']);
    $router->id = 42;
    $route = new ProxyRoute([
        'node_id' => 42,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);

    $config = (new DnsmasqConfigBuilder)->build(new Collection([$router]), new Collection([$route]));

    expect($config)->toContain('address=/orbit/10.6.0.2')
        ->and($config)->toContain('local=/orbit/');
});

it('skips nodes missing tld', function (): void {
    $nodes = new Collection([
        new Node(['name' => 'gateway', 'tld' => 'gateway', 'wireguard_address' => '10.6.0.2']),
        new Node(['name' => 'app-untagged', 'tld' => null, 'wireguard_address' => '10.6.0.3']),
    ]);

    $config = (new DnsmasqConfigBuilder)->build($nodes);

    expect($config)->toContain('address=/gateway/10.6.0.2')
        ->and($config)->not->toContain('10.6.0.3');
});

it('skips nodes missing wireguard address', function (): void {
    $nodes = new Collection([
        new Node(['name' => 'app-1', 'tld' => 'app-1.test', 'wireguard_address' => '10.6.0.3']),
        new Node(['name' => 'app-pending', 'tld' => 'pending.test', 'wireguard_address' => null]),
    ]);

    $config = (new DnsmasqConfigBuilder)->build($nodes);

    expect($config)->toContain('address=/app-1.test/10.6.0.3')
        ->and($config)->not->toContain('pending.test');
});

it('emits address lines in stable alphabetical order by tld', function (): void {
    $nodes = new Collection([
        new Node(['name' => 'z-app', 'tld' => 'zeta', 'wireguard_address' => '10.6.0.5']),
        new Node(['name' => 'a-app', 'tld' => 'alpha', 'wireguard_address' => '10.6.0.4']),
        new Node(['name' => 'm-app', 'tld' => 'mu', 'wireguard_address' => '10.6.0.6']),
    ]);

    $config = (new DnsmasqConfigBuilder)->build($nodes);

    $alphaPos = strpos($config, 'address=/alpha/');
    $muPos = strpos($config, 'address=/mu/');
    $zetaPos = strpos($config, 'address=/zeta/');

    expect($alphaPos)->toBeInt()
        ->and($muPos)->toBeInt()
        ->and($zetaPos)->toBeInt()
        ->and($alphaPos)->toBeLessThan($muPos)
        ->and($muPos)->toBeLessThan($zetaPos);
});

it('produces byte-identical output for identical inputs', function (): void {
    $nodes = new Collection([
        new Node(['name' => 'gateway', 'tld' => 'gateway', 'wireguard_address' => '10.6.0.2']),
        new Node(['name' => 'app-1', 'tld' => 'app-1.test', 'wireguard_address' => '10.6.0.3']),
    ]);

    $first = (new DnsmasqConfigBuilder)->build($nodes);
    $second = (new DnsmasqConfigBuilder)->build($nodes);

    expect($first)->toBe($second);
});

it('terminates with a trailing newline', function (): void {
    $config = (new DnsmasqConfigBuilder)->build(new Collection);

    expect(str_ends_with($config, "\n"))->toBeTrue();
});
